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
        $this->assertNull($b['rank'], 'senza calcolo ufficiale non c\'è una qualifica da mostrare');
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
