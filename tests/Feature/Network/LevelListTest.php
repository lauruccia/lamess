<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Network\LevelList;
use App\Filament\Pages\ConsultantLevelsPage;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class LevelListTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    /** @var array<string, Consultant> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->openPeriod('2026-10');
        $this->c['A'] = $this->consultant('A', null, 'DIAMOND');
        $this->c['B'] = $this->consultant('B', $this->c['A'], 'CRYSTAL');
        $this->c['C'] = $this->consultant('C', $this->c['B']);
        $this->c['D'] = $this->consultant('D', $this->c['A']);
        $this->c['E'] = $this->consultant('E');
        foreach (['A', 'B', 'C', 'D', 'E'] as $k) {
            $this->c[$k]->update(['name' => "Nome {$k}"]);
        }
    }

    /** @return list<string> */
    private function shape(array $list): array
    {
        return array_map(fn (array $r): string => $r['level'].':'.$r['code'], $list['rows']);
    }

    public function test_the_whole_network_is_listed_branch_by_branch_with_the_level_of_each_person(): void
    {
        $list = app(LevelList::class)->rows();

        $this->assertSame(['0:A', '1:B', '2:C', '1:D', '0:E'], $this->shape($list), 'ogni persona sta sotto il suo sponsor, il livello cresce di uno');
        $this->assertSame(5, $list['total']);
        $this->assertFalse($list['truncated']);
        $this->assertSame(2, $list['rows'][0]['directs']);
        $this->assertSame(0, $list['rows'][2]['directs']);
    }

    public function test_the_list_can_start_from_one_person_and_stop_at_a_level(): void
    {
        $this->assertSame(['0:B', '1:C'], $this->shape(app(LevelList::class)->rows($this->c['B']->id)), 'chi si sceglie è il livello 0');
        $this->assertSame(['0:A', '1:B', '1:D'], $this->shape(app(LevelList::class)->rows($this->c['A']->id, 1)), 'solo i diretti');
        $this->assertSame(['0:A', '0:E'], $this->shape(app(LevelList::class)->rows(null, 0)));
        $this->assertSame([], $this->shape(app(LevelList::class)->rows(999999)));
    }

    public function test_status_filter_hides_rows_but_keeps_the_levels_of_the_others(): void
    {
        $this->c['B']->update(['status' => 'terminated']);

        $this->assertSame(['0:A', '2:C', '1:D', '0:E'], $this->shape(app(LevelList::class)->rows(null, null, 'not_terminated')));
        $this->assertSame(['1:B'], $this->shape(app(LevelList::class)->rows(null, null, 'terminated')));
    }

    public function test_rows_carry_the_clients_and_the_rank_of_the_last_calculation(): void
    {
        foreach (['x', 'y'] as $n) {
            $u = User::factory()->create();
            Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $this->c['B']->id, 'registered_at' => now()]);
        }

        $b = collect(app(LevelList::class)->rows()['rows'])->firstWhere('code', 'B');
        $this->assertSame(2, $b['clients']);
        $entry = ($code = app(\App\Filament\Support\ConsultantList::class)->entryRankCode()) === null ? null : app(\App\Filament\Support\ConsultantList::class)->rankName($code);
        $this->assertSame($entry, $b['rank'], 'senza calcolo ufficiale si mostra la qualifica d\'ingresso del piano (se c\'è un piano in vigore)');

        $this->c['C']->update(['status' => 'terminated']);
        $c = collect(app(LevelList::class)->rows()['rows'])->firstWhere('code', 'C');
        $this->assertNull($c['rank'], 'un incaricato cessato senza calcolo non ha qualifica');
    }

    public function test_buyers_count_each_assigned_customer_once_when_they_have_a_paid_order_in_the_period(): void
    {
        $order = function (User $u, string $status, string $paidAt, string $n): void {
            \Illuminate\Support\Facades\DB::table('orders')->insert([
                'number' => $n, 'buyer_user_id' => $u->id, 'buyer_type' => 'customer', 'status' => $status, 'country_code' => 'IT', 'payment_method' => 'card',
                'subtotal_net' => 10, 'vat_total' => 2.2, 'total' => 12.2, 'paid_at' => $paidAt, 'billing' => '{}', 'created_at' => $paidAt, 'updated_at' => $paidAt,
            ]);
        };
        $mk = function () {
            $u = User::factory()->create();
            Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $this->c['B']->id, 'registered_at' => now()]);

            return $u;
        };
        [$twice, $other, $late, $unpaid] = [$mk(), $mk(), $mk(), $mk()];
        $order($twice, 'paid', '2026-10-03 10:00:00', 'T1');
        $order($twice, 'shipped', '2026-10-20 10:00:00', 'T2');
        $order($other, 'paid', '2026-10-31 23:30:00', 'T3');
        $order($late, 'paid', '2026-09-30 10:00:00', 'T4');
        $order($unpaid, 'cancelled', '2026-10-05 10:00:00', 'T5');

        $row = fn (array $r, string $code) => collect($r['rows'])->firstWhere('code', $code);
        $october = app(LevelList::class)->rows(null, null, 'all', ['2026-10-01', '2026-10-31']);
        $this->assertSame(2, $row($october, 'B')['buyers'], 'chi ha due ordini conta uno; annullati e fuori periodo no');
        $this->assertSame(4, $row($october, 'B')['clients'], 'la colonna Clienti resta il totale assegnato');
        $this->assertSame(0, $row($october, 'A')['buyers']);
        $this->assertSame(3, $row(app(LevelList::class)->rows(null, null, 'all', ['2026-09-01', '2026-10-31']), 'B')['buyers']);
        $this->assertSame(0, $row(app(LevelList::class)->rows(), 'B')['buyers'], 'senza periodo la colonna è a zero');
    }

    public function test_the_page_offers_the_period_of_the_buyers_column(): void
    {
        $this->actingAs(User::factory()->role()->create());

        Livewire::test(ConsultantLevelsPage::class)->assertSee('Hanno acquistato')->assertSee('mese in corso')
            ->set('buyPeriod', 'year')->assertSee('anno '.now()->year)
            ->set('buyPeriod', 'custom')->set('buyFrom', '2026-10-01')->set('buyTo', '2026-10-15')->assertSee('2026-10-01 → 2026-10-15')
            ->set('buyFrom', 'non-una-data')->assertSee('mese in corso');
    }

    public function test_the_page_shows_the_indented_list_and_filters_from_the_chosen_person(): void
    {
        $this->actingAs(User::factory()->role()->create());

        $this->get('/admin/elenco-livelli')->assertOk()->assertSee('Nome A')->assertSee('Nome C')->assertSee('Nome E');
        $this->get('/admin/consultants')->assertOk()->assertSee('Elenco per livelli');

        Livewire::test(ConsultantLevelsPage::class)->call('selectRoot', $this->c['B']->id)
            ->assertSee('Nome B')->assertSee('Nome C')->assertDontSee('Nome D')->assertDontSee('Nome E')
            ->set('rootSearch', 'zzz')->assertSee('Nome C');
        Livewire::test(ConsultantLevelsPage::class)->set('rootSearch', 'Nome D')->assertSee('Nome D')
            ->set('maxLevel', 0)->assertSee('Nome E')->assertDontSee('Nome C');
    }

    public function test_only_people_who_can_view_the_network_open_the_page(): void
    {
        $this->actingAs(User::factory()->role('admin')->create());
        $this->get('/admin/elenco-livelli')->assertForbidden();

        $viewer = User::factory()->role('admin')->create();
        $viewer->givePermissionTo('network.view');
        $this->actingAs($viewer)->get('/admin/elenco-livelli')->assertOk();
    }
}
