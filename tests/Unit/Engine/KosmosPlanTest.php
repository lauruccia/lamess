<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Support\Canonical;
use App\Domain\Engine\Support\Num;
use InvalidArgumentException;
use Tests\Support\Scenario;

/** Piano «Incaricati» (motore kosmos_v1): scenari tratti dalle presentazioni dell'08/10/2026. */
final class KosmosPlanTest extends EngineTestCase
{
    private function scenario(array $policies = [], ?PlanConfig $plan = null): Scenario
    {
        return new Scenario('2026-10', $policies, $plan ?? PlanLoader::incaricati(array_replace(Scenario::NEUTRAL_ACTIVITY, ['kosmos_rank_maintenance' => 'none'], $policies)));
    }

    private function buy(Scenario $s, string $owner, string $customer, int $pv = 100, string $on = '2026-10-10 12:00'): void
    {
        $m = $s->sale($owner, $pv, $on);
        $s->moves[array_key_last($s->moves)] = new Movement($m->id, $m->ownerId, 'pvf', $m->pv, $m->cv, $m->at, customerId: $customer);
    }

    /** @return array<string, string> beneficiario => totale per tipo di bonus */
    private function totals(PeriodResult $r, string $bonus, ?string $source = null): array
    {
        $out = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === $bonus && $l->status !== 'excluded' && ($source === null || $l->sourcePartnerId === $source)) {
                $out[$l->beneficiaryId] = Num::money(($out[$l->beneficiaryId] ?? Num::zero())->plus($l->amount));
            }
        }
        $out = array_map(static fn ($v) => (string) $v, $out);
        ksort($out, SORT_STRING);

        return $out;
    }

    public function test_plan_loads_with_the_current_rank_names_ending_at_diamond(): void
    {
        $new = PlanLoader::incaricati();
        $old = PlanLoader::prelaunch();
        $this->assertSame('kosmos_v1', $new->engine());
        $this->assertSame('multilevel_v3', $old->engine());
        $codes = array_map(fn ($r) => $r->code, $new->ranks);
        $this->assertSame(['CONSULTANT', 'PEARL', 'CRYSTAL', 'SAPPHIRE', 'RUBY', 'EMERALD', 'DIAMOND'], $codes);
        $this->assertSame($codes, array_slice(array_map(fn ($r) => $r->code, $old->ranks), 0, 7), 'Stessa scala delle qualifiche attuali');
        $this->assertSame([0, 3, 6, 6, 6, 6, 6], array_map(fn ($r) => $r->minClients, $new->ranks));
        $this->assertSame([0, 0, 2, 3, 4, 5, 6], array_map(fn ($r) => $r->minPearlFirstLevel, $new->ranks));
    }

    public function test_direct_rate_follows_the_client_tiers_on_two_thirds_of_the_volume(): void
    {
        foreach ([[2, '10.00', 2], [3, '15.00', 3], [6, '20.00', 6]] as [$clients, $each, $n]) {
            $s = $this->scenario();
            $s->consultant('A');
            for ($i = 1; $i <= $clients; $i++) {
                $this->buy($s, 'A', "c{$i}");
            }
            $r = $s->run();
            $first = collect($r->lines)->firstWhere('key', 'direct:m1');
            $this->assertSame($each, (string) $first->amount, "{$clients} clienti");
        }
    }

    public function test_indirect_levels_need_clients_and_pearls(): void
    {
        $s = $this->scenario();
        $s->consultant('S');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'S', 's1');
        $r = $s->run();
        $this->assertSame(['S' => '8.00'], $this->totals($r, 'indirect'), 'Livello 1: 8% di 100 con 1 cliente');

        $s = $this->scenario();
        $s->consultant('S');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $r = $s->run();
        $this->assertSame([], $this->totals($r, 'indirect'), 'Senza clienti personali il livello 1 non matura');

        // livello 2: servono 2 clienti e 1 Pearl al 1° livello
        $s = $this->scenario();
        $s->consultant('T');
        $s->consultant('S', 'T', rank: 'PEARL');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'S', 's1');
        $this->buy($s, 'T', 't1');
        $this->buy($s, 'T', 't2');
        $r = $s->run();
        $this->assertSame('4.00', $this->totals($r, 'indirect', 'A')['T'] ?? null, 'Livello 2: 4% di 100');

        $s = $this->scenario();
        $s->consultant('T');
        $s->consultant('S', 'T');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'S', 's1');
        $this->buy($s, 'T', 't1');
        $this->buy($s, 'T', 't2');
        $this->assertArrayNotHasKey('T', $this->totals($s->run(), 'indirect', 'A'), 'Senza Pearl al 1° livello il livello 2 non matura');
    }

    public function test_structure_bonus_is_a_differential_by_rank(): void
    {
        $s = $this->scenario();
        $s->consultant('D', null, rank: 'DIAMOND');
        $s->consultant('S', 'D', rank: 'SAPPHIRE');
        $s->consultant('C', 'S', rank: 'CRYSTAL');
        $s->consultant('X', 'C', since: '2026-10-01 09:00');
        foreach (['a', 'b', 'c'] as $i => $c) {
            $this->buy($s, 'X', $c, 100, '2026-10-0'.(3 + $i).' 10:00');
        }
        $r = $s->run();
        $this->assertSame(['C' => '60.00', 'D' => '90.00', 'S' => '30.00'], $this->totals($r, 'structure'));
    }

    public function test_structure_bonus_needs_the_clients_inside_the_window(): void
    {
        $s = $this->scenario();
        $s->consultant('C', null, rank: 'CRYSTAL');
        $s->consultant('X', 'C', since: '2026-08-01 09:00');
        foreach (['a', 'b', 'c'] as $i => $c) {
            $this->buy($s, 'X', $c, 100, '2026-10-0'.(3 + $i).' 10:00');
        }
        $this->assertSame([], $this->totals($s->run(), 'structure'), 'Oltre i 30 giorni dall\'attivazione');
    }

    public function test_extended_indirect_goes_below_level_five_and_stops_at_the_next_ruby(): void
    {
        $s = $this->scenario(['kosmos_compression' => 'none']);
        $s->consultant('R', null, rank: 'RUBY');
        $prev = 'R';
        foreach (range(1, 7) as $i) {
            $s->consultant("n{$i}", $prev);
            $prev = "n{$i}";
        }
        $this->buy($s, 'n7', 'x');
        $r = $s->run();
        // n7 è al 7° livello sotto R: livello esteso 1% di 100
        $this->assertSame(['R' => '1.00'], $this->totals($r, 'indirect_ext'));

        // un secondo Ruby a 6 livelli sopra chi vende: dal Ruby seguente in poi non vale più
        $s = $this->scenario(['kosmos_compression' => 'none']);
        $s->consultant('R', null, rank: 'RUBY');
        $s->consultant('n1', 'R');
        $s->consultant('R2', 'n1', rank: 'RUBY');
        $prev = 'R2';
        foreach (range(1, 6) as $i) {
            $s->consultant("m{$i}", $prev);
            $prev = "m{$i}";
        }
        $this->buy($s, 'm6', 'x');
        $r = $s->run();
        $ext = $this->totals($r, 'indirect_ext');
        $this->assertArrayNotHasKey('R', $ext, 'Il venditore è al 6° livello del secondo Ruby: spetta a lui');
        $this->assertSame('1.00', $ext['R2'] ?? null);
    }

    public function test_extended_indirect_stops_by_default_at_the_next_same_or_higher_rank(): void
    {
        $build = function (array $policies): Scenario {
            $s = $this->scenario($policies + ['kosmos_compression' => 'none']);
            $s->consultant('R', null, rank: 'RUBY');
            $s->consultant('n1', 'R');
            $s->consultant('E', 'n1', rank: 'EMERALD');
            $prev = 'E';
            foreach (range(1, 6) as $i) {
                $s->consultant("m{$i}", $prev);
                $prev = "m{$i}";
            }
            $this->buy($s, 'm6', 'x');

            return $s;
        };

        $this->assertSame('same_or_higher', $this->scenario()->plan->policies->get('kosmos_extended_stop'), 'predefinito deciso da Laura il 14/10/2026');
        $default = $this->totals($build([])->run(), 'indirect_ext');
        $this->assertArrayNotHasKey('R', $default, 'Un Emerald è più alto di un Ruby: il venditore al 6° livello dell\'Emerald spetta a lui');
        $this->assertSame('1.00', $default['E'] ?? null);

        $sameRank = $this->totals($build(['kosmos_extended_stop' => 'same_rank'])->run(), 'indirect_ext');
        $this->assertSame('1.00', $sameRank['R'] ?? null, 'Con «stessa qualifica» il Ruby continua oltre l\'Emerald');
    }

    public function test_the_line_compresses_and_pays_the_first_who_meets_the_requirements(): void
    {
        // T > S > A: S non ha clienti e viene saltato; T (1 cliente) diventa il 1° livello e prende l'8%, una sola volta.
        $s = $this->scenario();
        $s->consultant('T');
        $s->consultant('S', 'T');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'T', 't1');
        $this->assertSame(['T' => '8.00'], $this->totals($s->run(), 'indirect'));

        // senza compressione T è al 2° livello (4%) e non ha i requisiti: nulla
        $s = $this->scenario(['kosmos_compression' => 'none']);
        $s->consultant('T');
        $s->consultant('S', 'T');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'T', 't1');
        $this->assertSame([], $this->totals($s->run(), 'indirect'));
    }

    public function test_a_skipped_upline_gets_a_never_paid_line_that_says_which_requirement_is_missing(): void
    {
        // T > S > A: S non ha clienti e viene saltato. Il suo compenso non matura e non cambia i totali,
        // ma resta una riga «non spettante» con i numeri dei requisiti (serve per spiegare perché).
        $s = $this->scenario();
        $s->consultant('T');
        $s->consultant('S', 'T');
        $s->consultant('A', 'S');
        $this->buy($s, 'A', 'x1');
        $this->buy($s, 'T', 't1');
        $result = $s->run();

        $this->assertSame(['T' => '8.00'], $this->totals($result, 'indirect'), 'i totali pagati non cambiano');
        $skipped = array_values(array_filter($result->lines, static fn ($l) => $l->bonus === 'indirect' && $l->beneficiaryId === 'S'));
        $this->assertCount(1, $skipped);
        $this->assertSame('excluded', $skipped[0]->status);
        $this->assertSame(1, $skipped[0]->level, 'il livello che non ha saputo occupare');
        $this->assertStringStartsWith('requisiti_livello_1', $skipped[0]->reason);
        $this->assertSame(0, $skipped[0]->explanation['clients']);
        $this->assertSame(1, $skipped[0]->explanation['min_clients']);
        $this->assertTrue($skipped[0]->explanation['compressed']);
    }

    public function test_compression_counts_levels_only_among_those_who_qualify(): void
    {
        // Top > A (Pearl) > B > V: B non ha clienti ed è saltato. A è il 1° livello (8%), Top il 2° (4%: 2 clienti e A Pearl al 1° livello).
        $s = $this->scenario();
        $s->consultant('Top');
        $s->consultant('A', 'Top', rank: 'PEARL');
        $s->consultant('B', 'A');
        $s->consultant('V', 'B');
        $this->buy($s, 'V', 'x');
        foreach (['1', '2'] as $n) {
            $this->buy($s, 'A', "a{$n}");
            $this->buy($s, 'Top', "t{$n}");
        }
        $onV = [];
        foreach ($s->run()->lines as $l) {
            if ($l->bonus === 'indirect' && $l->sourcePartnerId === 'V' && $l->status !== 'excluded') {
                $onV[$l->beneficiaryId] = (string) $l->amount;
            }
        }
        $this->assertSame(['A' => '8.00', 'Top' => '4.00'], $onV, 'B è saltato; nessuno incassa due volte sulla stessa vendita');
    }

    public function test_the_rank_is_lost_when_any_requirement_of_the_current_rank_is_missing(): void
    {
        $maintain = ['kosmos_rank_maintenance' => 'requirements'];
        $paid = function (callable $setup) use ($maintain): string {
            $s = $this->scenario(plan: $this->planWithKosmos([], $maintain));
            $setup($s);

            return $s->run()->stats['C']->effectiveRank;
        };
        $clients = function (Scenario $s, string $id, int $n): void {
            foreach (range(1, $n) as $i) {
                $this->buy($s, $id, "{$id}-{$i}");
            }
        };
        $crystal = function (Scenario $s, int $ownClients, int $pearls) use ($clients): void {
            $s->consultant('C', null, rank: 'CRYSTAL');
            $clients($s, 'C', $ownClients);
            foreach (range(1, $pearls) as $i) {
                $s->consultant("P{$i}", 'C', rank: 'PEARL');
                $clients($s, "P{$i}", 3);
            }
        };

        $this->assertSame('CRYSTAL', $paid(fn (Scenario $s) => $crystal($s, 6, 2)), 'tutti i requisiti rispettati');
        $this->assertSame('PEARL', $paid(fn (Scenario $s) => $crystal($s, 5, 2)), 'manca un cliente personale');
        $this->assertSame('PEARL', $paid(fn (Scenario $s) => $crystal($s, 6, 1)), 'manca un Pearl al 1° livello');
        // il Pearl del 1° livello che retrocede (perde i suoi 3 clienti) fa scendere anche chi sta sopra
        $this->assertSame('PEARL', $paid(function (Scenario $s) use ($clients) {
            $s->consultant('C', null, rank: 'CRYSTAL');
            $clients($s, 'C', 6);
            $s->consultant('P1', 'C', rank: 'PEARL');
            $clients($s, 'P1', 3);
            $s->consultant('P2', 'C', rank: 'PEARL');
            $clients($s, 'P2', 2);
        }), 'P2 scende a Incaricato per mancanza di clienti, quindi a C resta un solo Pearl');
    }

    public function test_the_rank_is_lost_when_the_columns_or_the_other_incaricati_are_missing(): void
    {
        $paid = function (array $crystal): string {
            $s = $this->scenario(plan: $this->planWithCrystal(array_replace(['min_clients' => 0, 'min_pearl_first_level' => 0], $crystal)));
            $s->consultant('C', null, rank: 'CRYSTAL');
            foreach (['x', 'y', 'z'] as $i) {
                $this->buy($s, 'C', "C{$i}");
            }
            foreach (['P1', 'P2'] as $p) {
                $s->consultant($p, 'C', rank: 'PEARL');
                foreach (['a', 'b', 'c'] as $i) {
                    $this->buy($s, $p, "{$p}{$i}");
                }
            }

            return $s->run()->stats['C']->effectiveRank;
        };

        $this->assertSame('CRYSTAL', $paid(['min_columns' => 2, 'min_column_clients' => 3]), 'due colonne da 3 clienti presenti');
        $this->assertSame('PEARL', $paid(['min_columns' => 2, 'min_column_clients' => 4]), 'le colonne non arrivano a 4 clienti: retrocede');
        $this->assertSame('CRYSTAL', $paid(['structure' => [['rank' => 'PEARL', 'count' => 2]]]), 'due incaricati Pearl su linee diverse');
        $this->assertSame('PEARL', $paid(['structure' => [['rank' => 'PEARL', 'count' => 3]]]), 'manca un terzo incaricato Pearl: retrocede');
    }

    public function test_personal_purchases_generate_nothing_when_the_option_is_off(): void
    {
        $s = $this->scenario(['kosmos_own_purchase_as_client' => 'off']);
        $s->consultant('S');
        $s->consultant('A', 'S');
        $s->sale('A', 100, kind: 'pvc');
        $r = $s->run();
        $this->assertSame([], array_filter($r->lines, fn ($l) => $l->amount->isPositive()));
    }

    public function test_own_purchase_counts_as_a_client_and_earns_like_one(): void
    {
        $s = $this->scenario();
        $s->consultant('S');
        $s->consultant('A', 'S');
        foreach (['c1', 'c2'] as $c) {
            $this->buy($s, 'A', $c);
        }
        $s->sale('A', 100, kind: 'pvc');
        $r = $s->run();
        $this->assertSame(3, $this->clientsOf($r, 'A'));
        $this->assertSame('15.00', (string) collect($r->lines)->firstWhere('key', 'direct:m3')->amount, 'Terzo cliente (se stesso): scaglione 15% su 100');
    }

    public function test_sponsor_earns_on_the_own_purchase_of_a_direct_agent_like_on_a_client_of_that_agent(): void
    {
        // il cliente di A compra: S (sponsor di A) matura l'indiretto di 1° livello
        $viaClient = $this->scenario();
        $viaClient->consultant('S');
        $viaClient->consultant('A', 'S');
        $this->buy($viaClient, 'S', 's1');
        $this->buy($viaClient, 'A', 'x1');
        $expected = $this->totals($viaClient->run(), 'indirect');
        $this->assertSame(['S' => '8.00'], $expected);

        // A compra per sé: stesso importo allo sponsor, e A incassa il proprio diretto come per un suo cliente
        $own = $this->scenario();
        $own->consultant('S');
        $own->consultant('A', 'S');
        $this->buy($own, 'S', 's1');
        $own->sale('A', 100, kind: 'pvc');
        $r = $own->run();
        $this->assertSame($expected, $this->totals($r, 'indirect'));
        $this->assertSame(['A' => '10.00', 'S' => '10.00'], $this->totals($r, 'direct'));

        // con l'interruttore spento non matura nulla né per A né per lo sponsor sull'acquisto di A
        $off = $this->scenario(['kosmos_own_purchase_as_client' => 'off']);
        $off->consultant('S');
        $off->consultant('A', 'S');
        $this->buy($off, 'S', 's1');
        $off->sale('A', 100, kind: 'pvc');
        $this->assertSame([], $this->totals($off->run(), 'indirect'));
    }

    private function clientsOf(PeriodResult $r, string $id): int
    {
        return (int) collect($r->lines)->firstWhere(fn ($l) => $l->bonus === 'direct' && $l->beneficiaryId === $id)->explanation['clients'];
    }

    public function test_small_purchases_do_not_make_a_client(): void
    {
        $s = $this->scenario();
        $s->consultant('A');
        $this->buy($s, 'A', 'c1', 100);
        $this->buy($s, 'A', 'c2', 40);
        $this->assertSame(1, $this->clientsOf($s->run(), 'A'), 'Sotto i 100 PV (una Box) non è un cliente');
    }

    public function test_new_qualifications_follow_clients_and_first_level_pearls(): void
    {
        $s = $this->scenario();
        $s->consultant('C');
        foreach (['P1', 'P2'] as $p) {
            $s->consultant($p, 'C');
            foreach (['a', 'b', 'c'] as $i) {
                $this->buy($s, $p, "{$p}{$i}");
            }
        }
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i) {
            $this->buy($s, 'C', "C{$i}");
        }
        $r = $s->run();
        $this->assertSame('PEARL', $r->stats['P1']->achievedRank);
        $this->assertSame('CRYSTAL', $r->stats['C']->achievedRank, '6 clienti e 2 Pearl al 1° livello');
        $this->assertSame('CRYSTAL', $r->stats['C']->effectiveRank, 'La qualifica vale dal giorno in cui è raggiunta: si paga già nel mese, se i clienti sono mantenuti');
    }

    public function test_rank_is_paid_only_while_the_clients_are_maintained(): void
    {
        $s = $this->scenario(['kosmos_rank_maintenance' => 'clients']);
        $s->consultant('C', null, rank: 'CRYSTAL');
        foreach (['a', 'b', 'c', 'd', 'e'] as $i) {
            $this->buy($s, 'C', "C{$i}");
        }
        $this->assertSame('PEARL', $s->run()->stats['C']->effectiveRank, '5 clienti: scende a Pearl (3 clienti)');
        $this->buy($s, 'C', 'Cf');
        $this->assertSame('CRYSTAL', $s->run()->stats['C']->effectiveRank, '6 clienti: Crystal mantenuta');
    }

    /** @param array<string, mixed> $crystal */
    private function planWithCrystal(array $crystal): PlanConfig
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $data['policies'] = Scenario::NEUTRAL_ACTIVITY;
        $data['ranks'][2] = array_replace($data['ranks'][2], $crystal);

        return PlanConfig::fromArray($data);
    }

    private function crystalNetwork(PlanConfig $plan): Scenario
    {
        $s = $this->scenario(plan: $plan);
        $s->consultant('C');
        foreach (['x', 'y', 'z'] as $i) {
            $this->buy($s, 'C', "C{$i}");
        }
        foreach (['P1', 'P2'] as $p) {
            $s->consultant($p, 'C');
            foreach (['a', 'b', 'c'] as $i) {
                $this->buy($s, $p, "{$p}{$i}");
            }
        }

        return $s;
    }

    public function test_columns_requirement_counts_the_clients_of_each_whole_column(): void
    {
        $crystal = ['min_clients' => 0, 'min_pearl_first_level' => 0, 'min_columns' => 2, 'min_column_clients' => 3];
        $this->assertSame('CRYSTAL', $this->crystalNetwork($this->planWithCrystal($crystal))->run()->stats['C']->achievedRank, '2 colonne da 3 clienti');
        $crystal['min_column_clients'] = 4;
        $this->assertSame('PEARL', $this->crystalNetwork($this->planWithCrystal($crystal))->run()->stats['C']->achievedRank, 'colonne da 4: non bastano');
    }

    public function test_every_requirement_can_be_switched_off_per_rank(): void
    {
        $crystal = ['min_clients' => 0, 'min_pearl_first_level' => 0, 'min_columns' => 2, 'min_column_clients' => 4];
        $this->assertSame('PEARL', $this->crystalNetwork($this->planWithCrystal($crystal))->run()->stats['C']->achievedRank);
        $crystal['disabled_requirements'] = ['columns'];
        $this->assertSame('CRYSTAL', $this->crystalNetwork($this->planWithCrystal($crystal))->run()->stats['C']->achievedRank, 'requisito colonne disattivato');

        $strict = ['min_clients' => 6, 'min_pearl_first_level' => 3];
        $this->assertSame('PEARL', $this->crystalNetwork($this->planWithCrystal($strict))->run()->stats['C']->achievedRank);
        $strict['disabled_requirements'] = ['clients', 'pearl'];
        $this->assertSame('CRYSTAL', $this->crystalNetwork($this->planWithCrystal($strict))->run()->stats['C']->achievedRank, 'clienti e Pearl disattivati');
    }

    public function test_gifted_agents_help_the_rank_requirements_but_pay_and_count_for_nothing_else(): void
    {
        // C ha 6 clienti e un solo Pearl vero: con 1 Pearl regalato raggiunge Crystal
        $build = function (array $helps): Scenario {
            $s = $this->scenario();
            $s->consultant('C');
            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i) {
                $this->buy($s, 'C', "C{$i}");
            }
            $s->consultant('P1', 'C');
            foreach (['a', 'b', 'c'] as $i) {
                $this->buy($s, 'P1', "P1{$i}");
            }
            $s->rankHelps = $helps;

            return $s;
        };
        $this->assertSame('PEARL', $build([])->run()->stats['C']->achievedRank, 'senza aiuto manca un Pearl al 1° livello');
        $helped = $build(['C' => ['PEARL' => 1]]);
        $r = $helped->run();
        $this->assertSame('CRYSTAL', $r->stats['C']->achievedRank, '1 Pearl vero + 1 regalato = 2 Pearl al 1° livello');
        $this->assertSame(2, $r->stats['C']->nextRank['pearl_first_level_now'] ?? null, 'il conteggio mostrato include gli agenti regalati');
        // un agente di livello più alto vale come Pearl
        $this->assertSame('CRYSTAL', $build(['C' => ['RUBY' => 1]])->run()->stats['C']->achievedRank);
        // gli agenti regalati non generano compensi a nessuno
        $without = $this->totals($build([])->run(), 'indirect');
        $with = $this->totals($helped->run(), 'indirect');
        $this->assertSame($without, $with, 'nessun compenso indiretto in più per gli agenti regalati');
    }

    public function test_gifted_agents_fill_the_structure_slots_of_higher_ranks_in_separate_columns(): void
    {
        $s = $this->scenario();
        $s->consultant('S', rank: 'CRYSTAL');
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i) {
            $this->buy($s, 'S', "S{$i}");
        }
        foreach (['P1', 'P2', 'P3'] as $p) {
            $s->consultant($p, 'S', rank: 'PEARL');
        }
        $this->assertSame('CRYSTAL', $s->run()->stats['S']->achievedRank, 'Sapphire chiede anche 2 Crystal su colonne diverse');
        $s->rankHelps = ['S' => ['CRYSTAL' => 2]];
        $this->assertSame('SAPPHIRE', $s->run()->stats['S']->achievedRank, '3 Pearl veri + 2 Crystal regalati = Sapphire');
    }

    public function test_first_level_pearl_requirement_accepts_pearl_or_higher_ranks(): void
    {
        $s = $this->scenario();
        $s->consultant('C');
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i) {
            $this->buy($s, 'C', "C{$i}");
        }
        $s->consultant('P1', 'C');
        foreach (['a', 'b', 'c'] as $i) {
            $this->buy($s, 'P1', "P1{$i}");
        }
        $s->consultant('R1', 'C', rank: 'RUBY');

        $this->assertSame('CRYSTAL', $s->run()->stats['C']->achievedRank, 'un Pearl e un Ruby al 1° livello valgono come 2 Pearl');
    }

    public function test_ruby_needs_three_columns_of_ninety_clients_in_the_default_plan(): void
    {
        $ruby = PlanLoader::incaricati()->rank('RUBY');
        $this->assertSame([3, 90, []], [$ruby->minColumns, $ruby->minColumnClients, $ruby->structure]);
    }

    public function test_minimum_clients_per_rank_is_editable_in_the_plan(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $data['policies'] = Scenario::NEUTRAL_ACTIVITY;
        $data['ranks'][1]['min_clients'] = 4;
        $s = $this->scenario(plan: PlanConfig::fromArray($data));
        $s->consultant('A');
        foreach (['a', 'b', 'c'] as $i) {
            $this->buy($s, 'A', $i);
        }
        $this->assertSame('CONSULTANT', $s->run()->stats['A']->achievedRank, '3 clienti non bastano se il minimo è 4');
    }

    public function test_the_engine_requires_its_tables_and_valid_tiers(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $noSection = $data;
        unset($noSection['kosmos']);
        $this->expectException(InvalidArgumentException::class);
        PlanConfig::fromArray($noSection);
    }

    public function test_tiers_must_be_increasing(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $data['kosmos']['direct_tiers'][] = ['min_clients' => 3, 'pct' => 99];
        $this->expectException(InvalidArgumentException::class);
        PlanConfig::fromArray($data);
    }

    /** Acquisto in euro: i punti non contano. @param string|null $order righe con lo stesso valore appartengono allo stesso ordine */
    private function buyEur(Scenario $s, string $owner, string $customer, string $amount, ?string $order = null, string $on = '2026-10-10 12:00'): void
    {
        $m = $s->sale($owner, 1, $on);
        $s->moves[array_key_last($s->moves)] = new Movement(
            $m->id, $m->ownerId, 'pvf', Num::of('1'), Num::of('1'), $m->at, customerId: $customer, amountEur: Num::of($amount), orderId: $order,
        );
    }

    /** @param array<string, mixed> $kosmos @param array<string, string> $policies @param array<string, string> $settings */
    private function planWithKosmos(array $kosmos, array $policies = [], array $settings = []): PlanConfig
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $data['policies'] = array_replace(Scenario::NEUTRAL_ACTIVITY, ['kosmos_rank_maintenance' => 'none'], $policies);
        $data['kosmos'] = array_replace($data['kosmos'], ['rank_grace_days' => null, 'rank_grace_until' => null], $kosmos);
        $data['settings'] = array_replace($data['settings'], $settings);

        return PlanConfig::fromArray($data);
    }

    private function directClients(PeriodResult $r): int
    {
        return (int) collect($r->lines)->firstWhere('bonus', 'direct')->explanation['clients'];
    }

    public function test_a_client_is_whoever_places_an_order_of_the_minimum_amount(): void
    {
        $s = $this->scenario(plan: $this->planWithKosmos(['client_rule' => 'min_amount', 'client_min_amount' => '150']));
        $s->consultant('A');
        $this->buyEur($s, 'A', 'c1', '149.99', 'o1');
        $this->buyEur($s, 'A', 'c2', '150.00', 'o2');
        $this->buyEur($s, 'A', 'c3', '100.00', 'o3');
        $this->buyEur($s, 'A', 'c3', '60.00', 'o3');
        $this->buyEur($s, 'A', 'c4', '100.00', 'o4');
        $this->buyEur($s, 'A', 'c4', '100.00', 'o5');

        $this->assertSame(2, $this->directClients($s->run()), 'c2 (150) e c3 (100 + 60 nello stesso ordine); c1 e c4 non raggiungono 150 in un solo ordine');
    }

    public function test_the_admin_can_make_any_order_enough_to_be_a_client(): void
    {
        $s = $this->scenario(plan: $this->planWithKosmos(['client_rule' => 'any_order']));
        $s->consultant('A');
        $this->buyEur($s, 'A', 'c1', '49.90', 'o1');
        $this->buyEur($s, 'A', 'c2', '129.90', 'o2');
        $this->buyEur($s, 'A', 'c3', '150.00', 'o3');

        $this->assertSame(3, $this->directClients($s->run()));
    }

    public function test_commissions_use_the_euro_amount_not_the_points(): void
    {
        $s = $this->scenario(plan: $this->planWithKosmos(['client_rule' => 'any_order']));
        $s->consultant('S');
        $s->consultant('A', 'S');
        $this->buyEur($s, 'S', 'c9', '150.00', 'o9'); // 1 cliente: sblocca il livello 1
        $this->buyEur($s, 'A', 'c1', '129.90', 'o1'); // 1 punto soltanto: conta l'importo
        $r = $s->run();

        $direct = collect($r->lines)->firstWhere('key', 'direct:m2');
        $this->assertSame('86.60', (string) $direct->base, '129,90 × 66,66666%');
        $this->assertSame('8.66', (string) $direct->amount, '10% con 1 cliente');
        $this->assertSame(['S' => '6.93'], $this->totals($r, 'indirect'), '8% di 86,60 al livello 1');
    }

    public function test_movements_without_an_amount_are_converted_from_the_legacy_points(): void
    {
        $s = $this->scenario();
        $s->consultant('A');
        $this->buy($s, 'A', 'c1', 100);

        $this->assertSame(1, $this->directClients($s->run()), '100 punti storici = 150 €: è un cliente');
        $first = collect($s->run()->lines)->firstWhere('key', 'direct:m1');
        $this->assertSame('100.00', (string) $first->base);
    }

    public function test_there_is_no_active_status_so_indirects_accrue_without_any_spending_threshold(): void
    {
        $plan = $this->planWithKosmos(['client_rule' => 'any_order'], ['activity_month_rule' => 'current'], ['activity_threshold' => '99999']);
        $s = $this->scenario(plan: $plan);
        $s->consultant('S', active: false);
        $s->consultant('A', 'S', active: false);
        $this->buyEur($s, 'S', 'c9', '10.00', 'o9'); // 1 cliente, ordine minuscolo: nessuna soglia di spesa
        $this->buyEur($s, 'A', 'c1', '150.00', 'o1');
        $r = $s->run();

        $this->assertSame(['S' => '8.00'], $this->totals($r, 'indirect'), '8% di 100 al livello 1, anche se S non ha "speso abbastanza"');
        $this->assertSame(['A' => '10.00', 'S' => '0.67'], $this->totals($r, 'direct'), 'i diretti maturano sempre');
        $this->assertTrue($r->stats['S']->active, 'nessuno stato attivo da perdere');
        $this->assertSame('0', (string) $r->stats['S']->activityPoints->toScale(0), 'le soglie di attività non si calcolano');
    }

    public function test_suspended_incaricati_still_do_not_earn_indirects(): void
    {
        $s = $this->scenario(plan: $this->planWithKosmos(['client_rule' => 'any_order']));
        $s->consultant('S', status: 'suspended');
        $s->consultant('A', 'S');
        $this->buyEur($s, 'S', 'c9', '150.00', 'o9');
        $this->buyEur($s, 'A', 'c1', '150.00', 'o1');

        $this->assertSame([], $this->totals($s->run(), 'indirect'));
    }

    /** @return array<string, string> codice qualifica pagata */
    private function paidRanks(PlanConfig $plan, ?string $holdUntil = null, ?string $previousRank = 'CRYSTAL'): array
    {
        $s = $this->scenario(plan: $plan);
        $s->consultant('C', null, rank: 'CRYSTAL', rankHoldUntil: $holdUntil, previousRank: $previousRank);
        foreach (['a', 'b', 'c', 'd', 'e'] as $i) {
            $this->buy($s, 'C', "C{$i}");
        }

        return ['C' => $s->run()->stats['C']->effectiveRank];
    }

    public function test_ranks_are_kept_until_the_date_chosen_by_the_admin(): void
    {
        $maintain = ['kosmos_rank_maintenance' => 'clients'];
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($this->planWithKosmos([], $maintain)), 'senza data: scende a Pearl con 5 clienti');
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($this->planWithKosmos(['rank_grace_until' => '2026-10-31'], $maintain)), 'ottobre finisce il 31: protetto');
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($this->planWithKosmos(['rank_grace_until' => '2026-12-31'], $maintain)));
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($this->planWithKosmos(['rank_grace_until' => '2026-10-30'], $maintain)), 'il mese finisce dopo la data: non protetto');
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($this->planWithKosmos(['rank_grace_until' => '2026-09-30'], $maintain)), 'data già passata');
    }

    public function test_the_plan_wide_date_protects_only_those_coming_from_the_previous_system(): void
    {
        $plan = $this->planWithKosmos(['rank_grace_until' => '2026-12-31'], ['kosmos_rank_maintenance' => 'clients']);
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($plan), 'importato dal vecchio piano: qualifica mantenuta');
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($plan, null, null), 'nuovo incaricato: nessuna protezione, scende con i clienti');
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($plan, '2026-12-31', null), 'il mantenimento deciso dall\'admin vale anche per i nuovi');
    }

    public function test_the_rank_of_a_single_incaricato_is_kept_until_the_date_chosen_by_the_admin(): void
    {
        $plan = $this->planWithKosmos([], ['kosmos_rank_maintenance' => 'clients']);
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($plan), 'senza protezione: scende a Pearl con 5 clienti');
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($plan, '2026-10-31'), 'ottobre finisce il 31: protetto');
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($plan, '2027-03-31'));
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($plan, '2026-10-30'), 'il mese finisce dopo la data: non protetto');
        $this->assertSame(['C' => 'PEARL'], $this->paidRanks($plan, '2026-09-30'), 'data già passata');
    }

    public function test_the_rank_can_be_kept_until_revoked(): void
    {
        $plan = $this->planWithKosmos([], ['kosmos_rank_maintenance' => 'clients']);
        $this->assertSame(['C' => 'CRYSTAL'], $this->paidRanks($plan, '2099-12-31'), 'fino a revoca: qualifica sempre mantenuta');
    }

    public function test_ranks_are_kept_for_the_days_chosen_by_the_admin_counted_from_the_activation_of_the_plan(): void
    {
        $maintain = ['kosmos_rank_maintenance' => 'clients'];
        $paid = fn (array $k) => $this->paidRanks($this->planWithKosmos($k, $maintain));

        // piano attivo dal 1° ottobre: 90 giorni = ottobre, novembre e dicembre protetti per intero
        $this->assertSame(['C' => 'CRYSTAL'], $paid(['rank_grace_days' => 90, 'activation_date' => '2026-10-01']));
        $this->assertSame('2026-12-31', $this->planWithKosmos(['rank_grace_days' => 90, 'activation_date' => '2026-10-01'])->kosmos->rankGraceEnd());
        // 30 giorni dal 1° settembre finiscono il 30/09: ottobre non è protetto
        $this->assertSame(['C' => 'PEARL'], $paid(['rank_grace_days' => 30, 'activation_date' => '2026-09-01']));
        // piano attivo da metà ottobre: il mese di attivazione conta per intero
        $this->assertSame(['C' => 'CRYSTAL'], $paid(['rank_grace_days' => 1, 'activation_date' => '2026-10-20']));
        // senza giorni, senza data di attivazione o con 0 giorni: nessuna protezione
        $this->assertSame(['C' => 'PEARL'], $paid(['rank_grace_days' => null, 'activation_date' => '2026-10-01']));
        $this->assertSame(['C' => 'PEARL'], $paid(['rank_grace_days' => 90]));
        $this->assertSame(['C' => 'PEARL'], $paid(['rank_grace_days' => 0, 'activation_date' => '2026-10-01']));
        // con anche la data fissa vale la più lontana
        $this->assertSame('2026-12-31', $this->planWithKosmos(['rank_grace_days' => 1, 'activation_date' => '2026-10-01', 'rank_grace_until' => '2026-12-31'])->kosmos->rankGraceEnd());
        $this->assertSame('2026-12-31', $this->planWithKosmos(['rank_grace_days' => 90, 'activation_date' => '2026-10-01', 'rank_grace_until' => '2026-10-31'])->kosmos->rankGraceEnd());
    }

    public function test_the_published_plan_version_gives_the_engine_its_activation_day(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/'.PlanLoader::INCARICATI_FILE), true);
        $this->assertNull(PlanConfig::fromArray($data)->kosmos->rankGraceDays, 'predefinito: nessuna protezione a giorni');
        $this->assertSame('2026-12-31', PlanConfig::fromArray($data)->kosmos->rankGraceEnd(), 'predefinito: protezione fino al 31/12/2026');
    }

    public function test_client_rule_and_grace_date_are_validated(): void
    {
        foreach ([['client_rule' => 'tutti'], ['client_min_amount' => '-1'], ['rank_grace_until' => '31/12/2026'], ['rank_grace_until' => '2026-02-30'], ['rank_grace_days' => -5], ['rank_grace_days' => 99999], ['activation_date' => '1/10/2026']] as $bad) {
            try {
                $this->planWithKosmos($bad);
                $this->fail('Valore non valido accettato: '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($this->planWithKosmos(['rank_grace_until' => ''])->kosmos->rankGraceUntil, 'vuoto = nessuna protezione');
    }

    public function test_new_optional_fields_do_not_change_hashes_of_existing_inputs(): void
    {
        $s = new Scenario('2026-10', []);
        $s->consultant('A');
        $m = $s->sale('A', 100);
        $norm = Canonical::normalize($m);
        $this->assertArrayNotHasKey('customerId', $norm);
        $this->assertArrayNotHasKey('amountEur', $norm);
        $this->assertArrayNotHasKey('orderId', $norm);
        $this->assertArrayNotHasKey('kosmos', Canonical::normalize($s->plan));
        $this->assertArrayNotHasKey('customerFirstPurchase', Canonical::normalize($s->input()));
    }
}
