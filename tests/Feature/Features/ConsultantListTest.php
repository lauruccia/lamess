<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Features\Feature;
use App\Domain\Periods\PeriodService;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Support\ConsultantList;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class ConsultantListTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private Consultant $a;

    private Consultant $b;

    private Consultant $c;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $p = $this->openPeriod('2026-10');
        $this->a = $this->consultant('A', null, 'DIAMOND');
        $this->b = $this->consultant('B', $this->a, 'CRYSTAL');
        $this->c = $this->consultant('C', $this->a, 'CRYSTAL');
        $this->sale($p, $this->b, '8000');
        app(PeriodService::class)->calculate($p);
        $this->super = User::factory()->role()->create();
        $this->actingAs($this->super);
    }

    /** Filtri e ricerca restano in sessione: ogni verifica parte da una lista pulita. */
    private function list(): Testable
    {
        session()->flush();

        return Livewire::test(ListConsultants::class);
    }

    public function test_list_renders_with_useful_columns(): void
    {
        $this->get('/admin/consultants')->assertOk()
            ->assertSee('Qualifica')->assertSee('Diretti')->assertSee('Da pagare')->assertSee('KYC')
            ->assertSee('Incaricato A')->assertSee('Crystal');
    }

    public function test_sponsor_name_and_directs_come_from_the_same_query(): void
    {
        $rows = Livewire::test(ListConsultants::class)->assertCanSeeTableRecords([$this->a, $this->b, $this->c])->instance()->getTableRecords();
        $a = $rows->firstWhere('id', $this->a->id);
        $b = $rows->firstWhere('id', $this->b->id);
        $this->assertSame(2, (int) $a->directs_count);
        $this->assertSame($this->a->name, $b->sponsor_name);
        $this->assertSame($this->a->code, $b->sponsor_code);
        $this->assertNotNull($b->stat_rank);
    }

    public function test_no_per_row_queries(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->consultant("X{$i}", $this->a, 'CRYSTAL');
        }
        $count = static function (callable $f): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $f();

            return count(DB::getQueryLog());
        };
        $few = $count(fn () => Livewire::test(ListConsultants::class)->set('tableRecordsPerPage', 25));
        $this->assertLessThan(60, $few, 'le query non crescono con le righe');
        $this->assertCount(0, array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select * from "consultants" where "consultants"."id" = ?')), 'nessun find() per riga');
    }

    public function test_tabs_have_counts_and_filter(): void
    {
        $this->c->update(['status' => 'suspended', 'name' => 'Zorro Unico']);
        $t = Livewire::test(ListConsultants::class)->set('activeTab', 'suspended');
        $t->assertCanSeeTableRecords([$this->c])->assertCanNotSeeTableRecords([$this->a, $this->b]);
        $this->assertSame(1, app(ConsultantList::class)->counts()['suspended']);
    }

    public function test_filters(): void
    {
        $this->list()->filterTable('sponsor', $this->a->id)->assertCanSeeTableRecords([$this->b, $this->c])->assertCanNotSeeTableRecords([$this->a]);
        $this->list()->filterTable('rank', 'DIAMOND')->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->c]);
        $this->list()->filterTable('is_root', false)->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
        $this->list()->filterTable('status', ['terminated'])->assertCanNotSeeTableRecords([$this->a, $this->b]);
    }

    public function test_search_by_email_and_name(): void
    {
        $u = User::factory()->create(['email' => 'trovami@example.test']);
        $this->b->update(['user_id' => $u->id]);
        $this->c->update(['name' => 'Zorro Unico']);
        $this->list()->searchTable('trovami@')->assertCanSeeTableRecords([$this->b])->assertCanNotSeeTableRecords([$this->c]);
        $this->list()->searchTable('Zorro')->assertCanSeeTableRecords([$this->c])->assertCanNotSeeTableRecords([$this->b]);
    }

    public function test_open_balance_and_toggles(): void
    {
        $period = DB::table('periods')->value('id');
        DB::table('settlements')->insert(['period_id' => $period, 'consultant_id' => $this->b->id, 'accrued' => 120.5, 'held' => 0, 'lines_count' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/admin/consultants')->assertSee('120,50');
        $this->list()->filterTable('has_balance', true)->assertCanSeeTableRecords([$this->b])->assertCanNotSeeTableRecords([$this->a]);

        Feature::set('backoffice.consultants_list.balance', false, reason: 'test');
        Feature::set('backoffice.consultants_list.tabs', false, reason: 'test');
        $this->get('/admin/consultants')->assertSee('120,50')->assertSee('KYC da verificare'); // il super_admin le vede sempre
        $admin = User::factory()->role('admin')->create();
        $admin->givePermissionTo(['network.view']);
        $this->actingAs($admin);
        $this->get('/admin/consultants')->assertOk()->assertDontSee('KYC da verificare')->assertDontSee('120,50');
        $this->list()->assertTableColumnHidden('open_balance')->assertTableFilterHidden('has_balance');
    }
}
