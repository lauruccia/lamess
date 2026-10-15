<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Access\Roles;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Plan\IncaricatiSimulator;
use App\Filament\Pages\IncaricatiSimulationPage;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\RankAchievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Simulazione «cosa succede se» sull'albero e le qualifiche: lavora in memoria e non scrive nel database. */
final class IncaricatiSimulationTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function incaricati(): CompPlanVersion
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);

        return CompPlanVersion::createFromArray('Incaricati', $data);
    }

    /** @return array{0:Period,1:CompPlanVersion,2:Consultant,3:Consultant} periodo, piano, Francesco (sponsor) e Laura */
    private function network(): array
    {
        $this->plan();
        $version = $this->incaricati();
        $period = $this->openPeriod('2026-10');
        $francesco = $this->consultant('FRA', null, null, '2026-01-10 08:00');
        $laura = $this->consultant('LAU', $francesco, null, '2026-01-20 08:00');

        return [$period, $version, $francesco, $laura];
    }

    /** @param list<array<string,mixed>> $changes */
    private function simulate(Period $p, CompPlanVersion $v, array $changes): array
    {
        return app(IncaricatiSimulator::class)->run($p, $v->plan(), $changes);
    }

    /** @return array<string, mixed>|null */
    private function row(array $result, Consultant $c): ?array
    {
        foreach ($result['rows'] as $r) {
            if ($r['id'] === (string) $c->id) {
                return $r;
            }
        }

        return null;
    }

    public function test_three_clients_make_laura_pearl_and_nothing_is_written(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $counts = fn () => [PvMovement::count(), CommissionLine::count(), RankAchievement::count(), Consultant::count(), Period::count()];
        $before = $counts();

        $res = $this->simulate($p, $v, [['type' => 'clients', 'target' => $lau->id, 'count' => 3]]);

        $row = $this->row($res, $lau);
        $this->assertSame('Incaricato', $row['rank_before']);
        $this->assertSame('Pearl', $row['rank_after']);
        $this->assertSame(0, $row['clients_before']);
        $this->assertSame(3, $row['clients_after']);
        $this->assertSame(1, $res['summary']['rank_up']);
        $this->assertSame(3, $res['summary']['clients']);
        $fraRow = $this->row($res, $fra);
        $this->assertSame(1, $fraRow['pearl_after'], 'Francesco ora ha un Pearl al 1° livello');
        $this->assertSame($before, $counts(), 'La simulazione non scrive nulla nel database');
    }

    public function test_francesco_with_six_clients_and_one_pearl_needs_a_second_pearl_for_crystal(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $changes = [
            ['type' => 'clients', 'target' => $lau->id, 'count' => 3],
            ['type' => 'clients', 'target' => $fra->id, 'count' => 6],
        ];

        $res = $this->simulate($p, $v, $changes);
        $row = $this->row($res, $fra);
        $this->assertSame('Pearl', $row['rank_after']);
        $this->assertSame(6, $row['clients_after']);
        $this->assertSame(1, $row['pearl_after']);
        $this->assertStringContainsString('Pearl 1° liv. 1/2', $row['missing_after']);

        // un secondo Pearl (agente simulato) sotto Francesco: diventa Crystal
        $res = $this->simulate($p, $v, [...$changes, ['type' => 'agents', 'target' => $fra->id, 'count' => 1, 'rank' => 'PEARL']]);
        $row = $this->row($res, $fra);
        $this->assertSame('Crystal', $row['rank_after']);
        $this->assertSame(2, $row['pearl_after']);
        $this->assertSame(1, $res['summary']['virtual_agents']);
        $this->assertTrue($res['agents'][0]['ok']);
    }

    public function test_virtual_agents_reach_every_requested_rank(): void
    {
        [$p, $v, $fra] = $this->network();
        foreach (array_slice(array_map(static fn ($r) => $r->code, $v->plan()->ranks), 1) as $code) {
            $res = $this->simulate($p, $v, [['type' => 'agents', 'target' => $fra->id, 'count' => 1, 'rank' => $code]]);
            $this->assertTrue($res['agents'][0]['ok'], "L'agente simulato deve raggiungere {$code}: ha raggiunto {$res['agents'][0]['reached']}");
            $this->assertSame([], $res['warnings']);
        }
    }

    public function test_extra_orders_do_not_add_clients_but_raise_commissions(): void
    {
        [$p, $v, , $lau] = $this->network();
        $res = $this->simulate($p, $v, [
            ['type' => 'clients', 'target' => $lau->id, 'count' => 1],
            ['type' => 'orders', 'target' => $lau->id, 'count' => 3, 'amount' => '150'],
        ]);
        $row = $this->row($res, $lau);
        $this->assertSame(1, $row['clients_after'], 'Gli ordini aggiuntivi arrivano dallo stesso cliente');
        $this->assertSame(3, $res['summary']['orders']);
        $this->assertGreaterThan(0, (float) $res['summary']['total_diff']);
    }

    public function test_orders_below_the_minimum_amount_do_not_make_clients_and_warn(): void
    {
        [$p, $v, , $lau] = $this->network();
        $res = $this->simulate($p, $v, [['type' => 'clients', 'target' => $lau->id, 'count' => 3, 'amount' => '20']]);
        $this->assertSame($this->row($res, $lau)['rank_before'], $this->row($res, $lau)['rank_after']);
        $this->assertSame(0, $this->row($res, $lau)['clients_after']);
        $this->assertNotEmpty($res['warnings']);
    }

    public function test_moving_a_pearl_changes_the_first_level_count_of_both_sponsors(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $other = $this->consultant('ALT', null, null, '2026-01-12 08:00');

        $res = $this->simulate($p, $v, [
            ['type' => 'clients', 'target' => $lau->id, 'count' => 3],
            ['type' => 'move', 'target' => $lau->id, 'sponsor' => $other->id],
        ]);

        $this->assertSame(0, $this->row($res, $fra)['pearl_after'] ?? 0);
        $this->assertSame(1, $this->row($res, $other)['pearl_after']);
        $this->assertTrue($this->row($res, $lau)['moved']);
    }

    public function test_invalid_changes_are_rejected_with_a_clear_message(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $cases = [
            'ciclo' => [[['type' => 'move', 'target' => $fra->id, 'sponsor' => $lau->id]], 'rete simulata non è valida'],
            'sé stesso' => [[['type' => 'move', 'target' => $lau->id, 'sponsor' => $lau->id]], 'sponsor di sé stesso'],
            'incaricato' => [[['type' => 'clients', 'target' => 999999, 'count' => 1]], 'scegli'],
            'quantità' => [[['type' => 'clients', 'target' => $lau->id, 'count' => 0]], 'quantità'],
            'qualifica' => [[['type' => 'agents', 'target' => $lau->id, 'count' => 1, 'rank' => 'NOPE']], 'sconosciuta'],
            'vuoto' => [[], 'almeno una modifica'],
        ];
        foreach ($cases as $name => [$changes, $needle]) {
            try {
                $this->simulate($p, $v, $changes);
                $this->fail("Atteso errore per «{$name}»");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $name);
            }
        }
    }

    public function test_tree_shows_the_whole_network_with_before_and_after_ranks(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $other = $this->consultant('ALT', null, null, '2026-01-12 08:00');

        $res = $this->simulate($p, $v, [
            ['type' => 'clients', 'target' => $lau->id, 'count' => 3],
            ['type' => 'agents', 'target' => $other->id, 'count' => 1, 'rank' => 'CRYSTAL'],
        ]);

        $tree = array_column($res['tree'], null, 'id');
        foreach ([$fra, $lau, $other] as $c) {
            $this->assertArrayHasKey((string) $c->id, $tree, 'Tutta la rete è nell\'albero, anche chi non cambia');
        }
        $this->assertSame((string) $fra->id, $tree[(string) $lau->id]['parent']);
        $this->assertSame('Incaricato', $tree[(string) $lau->id]['rank_before']);
        $this->assertSame('Pearl', $tree[(string) $lau->id]['rank_after']);
        $this->assertTrue($tree[(string) $lau->id]['changed']);
        $this->assertGreaterThanOrEqual(1, $tree[(string) $fra->id]['sub_changes'], 'Il ramo di Francesco segnala i cambiamenti sotto di lui');
        $virtual = array_values(array_filter($res['tree'], static fn (array $t) => $t['virtual']));
        $this->assertCount(1, $virtual, 'Gli agenti simulati annidati sono riassunti in quello in cima al ramo');
        $this->assertGreaterThan(0, $virtual[0]['extra']);
    }

    public function test_earnings_explanation_says_where_the_difference_comes_from(): void
    {
        [$p, $v, $fra, $lau] = $this->network();

        $res = $this->simulate($p, $v, [
            ['type' => 'clients', 'target' => $lau->id, 'count' => 3],
            ['type' => 'clients', 'target' => $fra->id, 'count' => 1],
        ]);

        $tree = array_column($res['tree'], null, 'id');
        $own = implode("\n", $tree[(string) $lau->id]['explain']);
        $this->assertStringContainsString('Compensi diretti', $own);
        $this->assertStringContainsString('ai propri clienti', $own);
        $this->assertStringContainsString('3 vendite', $own);
        $up = implode("\n", $tree[(string) $fra->id]['explain']);
        $this->assertStringContainsString('Compensi indiretti livello 1', $up);
        $this->assertStringContainsString('requisiti del livello', $up);
    }

    public function test_only_super_admin_and_admins_with_the_permission_can_open_the_page(): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertTrue(IncaricatiSimulationPage::canAccess());
        $this->actingAs(User::factory()->role('admin_compplan')->create());
        $this->assertTrue(IncaricatiSimulationPage::canAccess());
        foreach (['board', 'support', 'admin_finance'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->assertFalse(IncaricatiSimulationPage::canAccess(), $role);
        }
    }

    public function test_total_difference_is_split_between_simulated_agents_and_real_incaricati(): void
    {
        [$p, $v, , $lau] = $this->network();

        $res = $this->simulate($p, $v, [['type' => 'agents', 'target' => $lau->id, 'count' => 1, 'rank' => 'PEARL']]);

        $s = $res['summary'];
        $this->assertSame(1, $s['virtual_agents']);
        $this->assertGreaterThan(0, (float) $s['total_virtual']);
        $this->assertEqualsWithDelta((float) $s['total_diff'], (float) $s['total_virtual'] + (float) $s['total_real_diff'], 0.01);
    }

    public function test_breakdown_lists_who_earns_each_commission_type_and_from_which_sales(): void
    {
        [$p, $v, , $lau] = $this->network();

        $res = $this->simulate($p, $v, [['type' => 'clients', 'target' => $lau->id, 'count' => 3]]);

        $direct = $res['breakdown']['direct']['rows'];
        $row = collect($direct)->firstWhere('id', (string) $lau->id);
        $this->assertNotNull($row, 'Laura matura compensi diretti con i nuovi clienti');
        $this->assertGreaterThan(0, (float) $row['diff']);
        $this->assertSame('i suoi clienti', $row['lines'][0]['source']);
        $this->assertSame(3, $row['lines'][0]['sales']);
        $this->assertEqualsWithDelta((float) $row['after'], array_sum(array_map(static fn (array $l) => (float) $l['after'], $row['lines'])), 0.01);
    }

    public function test_current_tree_shows_the_real_network_with_what_is_missing_for_the_next_rank(): void
    {
        [$p, $v, $fra, $lau] = $this->network();
        $counts = fn () => [PvMovement::count(), CommissionLine::count(), RankAchievement::count()];
        $before = $counts();

        $rows = app(IncaricatiSimulator::class)->currentTree($p, $v->plan());

        $tree = array_column($rows, null, 'id');
        $this->assertSame((string) $fra->id, $tree[(string) $lau->id]['parent']);
        $this->assertSame('Incaricato', $tree[(string) $lau->id]['rank_after']);
        $this->assertFalse($tree[(string) $lau->id]['changed'], 'Senza modifiche nessuna riga risulta cambiata');
        $this->assertSame('Pearl', $tree[(string) $lau->id]['next_rank']);
        $this->assertStringContainsString('clienti', $tree[(string) $lau->id]['missing']);
        $this->assertSame($before, $counts());
    }

    public function test_page_shows_the_tree_before_the_first_simulation(): void
    {
        [, , $fra, $lau] = $this->network();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        Livewire::test(IncaricatiSimulationPage::class)
            ->assertSee('La rete oggi')
            ->assertSee('LAU — Incaricato LAU')
            ->assertSee('+ clienti')
            ->assertSee('Vista albero')
            ->assertSee('Elenco per livelli')
            ->call('addChangeFor', 'clients', 'LAU — Incaricato LAU')
            ->assertSee('Tutta la rete dopo la simulazione');
    }

    public function test_page_runs_the_simulation_from_the_form_and_shows_the_difference(): void
    {
        [, , $fra, $lau] = $this->network();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $lines = CommissionLine::count();

        Livewire::test(IncaricatiSimulationPage::class)
            ->set('changes', [['type' => 'clients', 'target' => 'LAU — Incaricato LAU', 'count' => '3', 'amount' => '', 'rank' => 'PEARL', 'sponsor' => '']])
            ->call('simulate')
            ->assertSet('error', null)
            ->assertSee('Solo simulazione')
            ->assertSee('Incaricato → Pearl')
            ->assertSee('Chi sale di qualifica')
            ->assertSee('Come sono suddivise le provvigioni del mese')
            ->assertSee('Che cosa sono queste provvigioni e come maturano')
            ->assertSee('Chi le riceve');

        Livewire::test(IncaricatiSimulationPage::class)
            ->set('changes', [['type' => 'clients', 'target' => 'nessuno', 'count' => '3', 'amount' => '', 'rank' => 'PEARL', 'sponsor' => '']])
            ->call('simulate')
            ->assertSee('scegli');

        Livewire::test(IncaricatiSimulationPage::class)
            ->call('addChangeFor', 'clients', 'LAU — Incaricato LAU')
            ->assertSet('error', null)
            ->assertSet('changes.0.target', 'LAU — Incaricato LAU')
            ->assertSee('Tutta la rete dopo la simulazione');

        $this->assertSame($lines, CommissionLine::count());
        $this->assertSame(0, PvMovement::count());
        $this->assertSame(0, RankAchievement::count());
    }

    public function test_columns_create_pearl_lines_with_at_least_ninety_clients_each(): void
    {
        [$p, $v, $fra] = $this->network();
        $this->assertSame(90, IncaricatiSimulator::defaultColumnClients($v->plan()));

        $res = $this->simulate($p, $v, [['type' => 'columns', 'target' => $fra->id, 'count' => 3, 'clients' => '']]);

        $this->assertSame(3, $res['summary']['virtual_agents']);
        $this->assertCount(3, $res['agents']);
        foreach ($res['agents'] as $agent) {
            $this->assertTrue($agent['ok']);
            $this->assertGreaterThanOrEqual(90, $agent['sub_clients']);
        }

        $custom = $this->simulate($p, $v, [['type' => 'columns', 'target' => $fra->id, 'count' => 2, 'clients' => '120']]);
        foreach ($custom['agents'] as $agent) {
            $this->assertGreaterThanOrEqual(120, $agent['sub_clients']);
        }
    }

    public function test_columns_reject_invalid_numbers(): void
    {
        [$p, $v, $fra] = $this->network();

        foreach ([['count' => 0, 'clients' => ''], ['count' => 11, 'clients' => ''], ['count' => 3, 'clients' => '501']] as $bad) {
            try {
                $this->simulate($p, $v, [['type' => 'columns', 'target' => $fra->id] + $bad]);
                $this->fail('La modifica non valida doveva essere rifiutata.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Modifica 1', $e->getMessage());
            }
        }
    }

    public function test_page_offers_the_columns_change_with_ninety_clients_by_default(): void
    {
        [, , $fra] = $this->network();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        Livewire::test(IncaricatiSimulationPage::class)
            ->assertSee('Aggiungi colonne (da 90 clienti)')
            ->call('addChange', 'columns')
            ->assertSet('changes.0.count', '3')
            ->assertSee('colonne, ciascuna con almeno')
            ->set('changes.0.target', 'FRA — Incaricato FRA')
            ->call('simulate')
            ->assertSet('error', null)
            ->assertSee('Risultato per il periodo');
    }
}
