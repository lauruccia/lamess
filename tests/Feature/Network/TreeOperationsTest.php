<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Access\Roles;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Domain\Network\TreeOperations;
use App\Domain\Periods\PeriodService;
use App\Filament\Pages\NetworkTreePage;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\AuditEntry;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Profile;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Fase 3 — operazioni sull'albero: spostamento, cambio sponsor, clienti, rank forzato, vista. */
final class TreeOperationsTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private TreeOperations $ops;

    /** @var array<string, Consultant> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->plan();
        $this->ops = app(TreeOperations::class);
        // R ─ A ─ B ─ C        R ─ X
        $this->c['R'] = $this->consultant('R');
        $this->c['A'] = $this->consultant('A', $this->c['R']);
        $this->c['B'] = $this->consultant('B', $this->c['A']);
        $this->c['C'] = $this->consultant('C', $this->c['B']);
        $this->c['X'] = $this->consultant('X', $this->c['R']);
        app(NetworkService::class)->rebuildClosure();
    }

    private function audit(string $action): array
    {
        $row = AuditEntry::where('action', $action)->latest('id')->firstOrFail();

        return is_string($row->changes) ? json_decode($row->changes, true) : $row->changes;
    }

    private function descendants(Consultant $c): array
    {
        return DB::table('network_paths')->where('ancestor_id', $c->id)->where('depth', '>', 0)->pluck('descendant_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    public function test_move_branch_carries_descendants_and_updates_closure(): void
    {
        $r = $this->ops->moveBranch($this->c['A'], $this->c['X'], 'Riorganizzazione', true);
        $this->assertSame(3, $r['moved_count']); // A, B, C

        $this->assertSame($this->c['X']->id, $this->c['A']->fresh()->sponsor_id);
        $this->assertSame($this->c['A']->id, $this->c['B']->fresh()->sponsor_id);
        $this->assertSame([$this->c['A']->id, $this->c['B']->id, $this->c['C']->id], $this->descendants($this->c['X']));
        $this->assertContains($this->c['C']->id, $this->descendants($this->c['R']));
        $this->assertSame(3, (int) DB::table('network_paths')->where('ancestor_id', $this->c['X']->id)->where('descendant_id', $this->c['C']->id)->value('depth'));
    }

    public function test_move_without_branch_relinks_directs_to_previous_sponsor(): void
    {
        $this->ops->moveBranch($this->c['B'], $this->c['X'], 'Solo lui', false);
        $this->assertSame($this->c['X']->id, $this->c['B']->fresh()->sponsor_id);
        $this->assertSame($this->c['A']->id, $this->c['C']->fresh()->sponsor_id); // C passa allo sponsor precedente di B
        $this->assertSame([$this->c['B']->id], $this->descendants($this->c['X']));
    }

    public function test_cycle_is_refused_and_nothing_changes(): void
    {
        foreach (['C', 'B', 'A'] as $k) {
            try {
                $this->ops->moveBranch($this->c['A'], $this->c[$k], 'Ciclo');
                $this->fail('ciclo accettato');
            } catch (NetworkException $e) {
                $this->assertStringContainsString('ciclo', $e->getMessage());
            }
        }
        $this->assertSame($this->c['R']->id, $this->c['A']->fresh()->sponsor_id);
        $this->assertSame(0, DB::table('sponsor_history')->where('reason', 'transfer')->count());
    }

    public function test_reason_is_required_and_inactive_sponsor_refused(): void
    {
        try {
            $this->ops->moveBranch($this->c['A'], $this->c['X'], '  ');
            $this->fail();
        } catch (NetworkException $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }
        app(NetworkService::class)->changeStatus($this->c['X'], 'suspended', 'prova');
        $this->expectException(NetworkException::class);
        $this->ops->moveBranch($this->c['A'], $this->c['X'], 'Verso sospeso');
    }

    public function test_future_effective_date_is_refused(): void
    {
        $this->expectException(NetworkException::class);
        $this->ops->moveBranch($this->c['A'], $this->c['X'], 'Domani', true, new DateTimeImmutable('+2 days'));
    }

    public function test_preview_writes_nothing_and_lists_closed_periods(): void
    {
        $p = $this->openPeriod('2026-10');
        $this->sale($p, $this->c['C'], '300');
        $svc = app(PeriodService::class);
        $svc->calculate($p->refresh(), 'official');
        $svc->close($p->refresh());

        $v = $this->ops->previewMove($this->c['A'], $this->c['X'], true, new DateTimeImmutable('2026-10-01'));
        $this->assertTrue($v['ok']);
        $this->assertSame(3, $v['moved_count']);
        $this->assertSame(['R'], $v['from_chain']);
        $this->assertSame(['X', 'R'], $v['to_chain']);
        $this->assertSame(1, $v['closed_periods']['2026-10']['movements']);
        $this->assertSame($this->c['R']->id, $this->c['A']->fresh()->sponsor_id);
        $this->assertSame(0, AuditEntry::where('action', 'network.moved')->count());
    }

    public function test_move_is_logged_with_before_after_history_and_flags(): void
    {
        $this->ops->moveBranch($this->c['A'], $this->c['X'], 'Accordo con il cliente', true, new DateTimeImmutable('today'), true);
        $a = $this->audit('network.moved');
        $this->assertEquals(['prima' => 'R', 'dopo' => 'X'], $a['sponsor']);
        $this->assertTrue($a['ricalcola_mesi_chiusi']);
        $h = DB::table('sponsor_history')->where('consultant_id', $this->c['A']->id)->where('reason', 'transfer')->first();
        $this->assertSame('Accordo con il cliente', $h->note);
        $this->assertSame(1, (int) $h->recalc_closed);
        $this->assertSame(now()->format('Y-m-d'), substr((string) $h->effective_from, 0, 10));
    }

    public function test_recalc_closed_is_super_admin_only(): void
    {
        $this->actingAs(User::factory()->role(Roles::ADMIN)->create());
        $this->expectException(NetworkException::class);
        $this->ops->moveBranch($this->c['A'], $this->c['X'], 'Prova', true, null, true);
    }

    public function test_customer_reassignment_single_and_bulk_with_history(): void
    {
        $custs = [];
        foreach (['1', '2', '3'] as $i) {
            $u = User::create(['name' => "Cliente $i", 'email' => "c{$i}@example.test", 'password' => 'x']);
            Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => 'N', 'last_name' => $i]);
            $custs[] = Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $this->c['A']->id, 'source' => 'manual', 'registered_at' => now()]);
        }
        $n = $this->ops->reassignCustomers($custs, $this->c['X'], 'Cambio di zona');
        $this->assertSame(3, $n);
        $this->assertSame(3, Customer::where('assigned_consultant_id', $this->c['X']->id)->count());
        $this->assertSame(3, DB::table('customer_assignment_history')->where('to_consultant_id', $this->c['X']->id)->where('from_consultant_id', $this->c['A']->id)->count());
        $this->assertSame(0, $this->ops->reassignCustomers($custs, $this->c['X'], 'Già lì')); // nessun cambio
        $this->assertSame(1, $this->ops->reassignCustomers([$custs[0]], null, 'Senza incaricato'));
        $this->assertNull($custs[0]->fresh()->assigned_consultant_id);
        $this->assertSame(['clienti', 'consulente'], array_keys($this->audit('customers.reassigned')));
    }

    public function test_customers_cannot_go_to_inactive_consultant(): void
    {
        app(NetworkService::class)->changeStatus($this->c['X'], 'suspended', 'prova');
        $this->expectException(NetworkException::class);
        $this->ops->reassignCustomers([], $this->c['X'], 'Verso sospeso');
    }

    public function test_forced_rank_is_marked_and_logged(): void
    {
        $a = $this->ops->forceRank($this->c['B'], 'PEARL', new DateTimeImmutable('2026-10-01'), 'Accordo contrattuale');
        $this->assertSame('forced', $a->source);
        $this->assertSame('Accordo contrattuale', $a->detail['motivo']);
        $this->assertEquals('PEARL', $this->audit('rank.forced')['qualifica']['dopo']);
        $this->expectException(NetworkException::class);
        $this->ops->forceRank($this->c['B'], 'PEARL', new DateTimeImmutable('2026-10-02'), 'Doppione');
    }

    public function test_status_changes_are_written_to_the_audit_log(): void
    {
        app(NetworkService::class)->changeStatus($this->c['X'], 'suspended', 'Verifica in corso');
        $this->assertEquals(['prima' => 'active', 'dopo' => 'suspended'], $this->audit('consultant.status')['stato']);
        app(NetworkService::class)->terminate($this->c['B'], 'Rinuncia');
        $this->assertSame('A', $this->audit('consultant.terminated')['diretti_ricollegati_a']);
    }

    public function test_move_action_is_super_admin_only_even_with_the_permission(): void
    {
        $viewer = User::factory()->role(Roles::ADMIN)->create();
        $viewer->givePermissionTo(['network.view', 'network.restructure']);
        $this->actingAs($viewer);
        Livewire::test(ViewConsultant::class, ['record' => $this->c['A']->getKey()])->assertActionHidden('move');

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        Livewire::test(ViewConsultant::class, ['record' => $this->c['A']->getKey()])->assertActionVisible('move')
            ->callAction('move', ['new_sponsor_id' => $this->c['X']->id, 'with_branch' => true, 'effective_from' => now()->toDateString(), 'reason' => 'Test']);
        $this->assertSame($this->c['X']->id, $this->c['A']->fresh()->sponsor_id);
    }

    public function test_customer_bulk_reassign_from_list(): void
    {
        $u = User::create(['name' => 'Cl', 'email' => 'cl@example.test', 'password' => 'x']);
        Profile::create(['user_id' => $u->id, 'type' => 'private', 'first_name' => 'C', 'last_name' => 'L']);
        $cust = Customer::create(['user_id' => $u->id, 'assigned_consultant_id' => $this->c['A']->id, 'source' => 'manual', 'registered_at' => now()]);
        Livewire::test(ListCustomers::class)->callTableBulkAction('reassignBulk', [$cust], ['consultant_id' => $this->c['X']->id, 'reason' => 'Blocco']);
        $this->assertSame($this->c['X']->id, $cust->fresh()->assigned_consultant_id);
    }

    public function test_tree_page_renders_with_nodes_and_ranks(): void
    {
        $this->get('/admin/albero-rete')->assertOk()->assertSee('Albero della rete');
        $data = Livewire::test(NetworkTreePage::class)->instance()->treeData();
        $this->assertCount(5, $data['nodes']);
        $this->assertNotEmpty($data['ranks']);
        $a = collect($data['nodes'])->firstWhere('code', 'A');
        $this->assertSame($this->c['R']->id, $a['parent']);
        $this->assertSame(2, $a['team']);
    }
}
