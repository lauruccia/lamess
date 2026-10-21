<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Ledger\PointsGrant;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\AuditEntry;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Assegnazione libera dei punti: punteggio semplice, cliente per N mesi, come un acquisto. */
final class PointsGrantTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private Period $period;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $this->period = $this->openPeriod('2026-10', $plan);
        $this->sponsor = $this->activeSponsor();
    }

    private function grant(): PointsGrant
    {
        return app(PointsGrant::class);
    }

    private function when(): \DateTimeImmutable
    {
        return $this->at('2026-10-10 10:00');
    }

    public function test_score_only_is_registered_but_never_counts_for_the_plan(): void
    {
        $m = $this->grant()->grant($this->sponsor, '30', 'Quota consorzio Kadeo', at: $this->when());

        $this->assertSame('grant', $m->type);
        $this->assertSame('pvc', $m->kind);
        $this->assertTrue($m->excluded_from_bonus);
        $this->assertFalse($m->makes_client);
        $this->assertSame('Quota consorzio Kadeo', $m->note);
        $this->assertSame('30.00', (string) $m->pv);
        $this->assertSame('Solo punteggio', PointsGrant::effectOf($m));
        $this->assertTrue(AuditEntry::where('action', 'points.granted')->exists());

        $run = app(PeriodService::class)->calculate($this->period);
        $this->assertSame(0, CommissionLine::where('run_id', $run->id)->where('source_movement_id', $m->id)->count());
    }

    public function test_client_effect_counts_the_person_as_a_client_for_n_months_without_commissions(): void
    {
        $client = $this->buyer();
        $m = $this->grant()->grant($this->sponsor, '10', 'Socio Kadeo', PointsGrant::EFFECT_CLIENT, $client, months: 2, at: $this->when());

        $this->assertSame('pvf', $m->kind);
        $this->assertSame($client->id, $m->client_user_id);
        $this->assertSame(2, $m->validity_months);
        $this->assertSame('Cliente per 2 mesi, senza compensi', PointsGrant::effectOf($m));

        $input = SnapshotBuilder::build($this->period);
        $this->assertSame((string) $client->id, $input->movements[0]->customerId);
        $this->assertArrayHasKey((string) $client->id, $input->customerCoverage[(string) $this->sponsor->id], 'il cliente vale per i mesi indicati');
        $this->assertArrayHasKey((string) $client->id, $input->customerFirstPurchase[(string) $this->sponsor->id]);

        $run = app(PeriodService::class)->calculate($this->period);
        $paid = CommissionLine::where('run_id', $run->id)->where('source_movement_id', $m->id)->sum('amount');
        $this->assertSame(0.0, (float) $paid, 'nessun compenso sulla vendita');
    }

    public function test_purchase_effect_pays_commissions_on_the_amount(): void
    {
        $client = $this->buyer();
        $m = $this->grant()->grant($this->sponsor, '10', 'Quota in euro', PointsGrant::EFFECT_PURCHASE, $client, '150', at: $this->when());

        $this->assertSame('150.00', (string) $m->amount);
        $run = app(PeriodService::class)->calculate($this->period);
        $direct = CommissionLine::where('run_id', $run->id)->where('source_movement_id', $m->id)->where('bonus_type', 'direct')->firstOrFail();
        $this->assertSame('100.00', number_format((float) $direct->base_amount, 2, '.', ''), 'V.A.P. del piano: 150 € → base 100 €');
    }

    public function test_the_beneficiary_can_be_his_own_client(): void
    {
        $socio = $this->consultant('LM00002', $this->sponsor);
        $user = $this->buyer();
        $socio->update(['user_id' => $user->id]);

        $m = $this->grant()->grant($socio, '5', 'Quota socio', PointsGrant::EFFECT_CLIENT, $user, at: $this->when());

        $this->assertSame($user->id, $m->client_user_id);
    }

    public function test_two_grants_for_two_clients_count_as_two_distinct_clients(): void
    {
        $this->grant()->grant($this->sponsor, '1', 'Uno', PointsGrant::EFFECT_CLIENT, $this->buyer(), at: $this->when());
        $this->grant()->grant($this->sponsor, '1', 'Due', PointsGrant::EFFECT_CLIENT, $this->buyer(), at: $this->when());

        $this->assertCount(2, SnapshotBuilder::build($this->period)->customerCoverage[(string) $this->sponsor->id]);
    }

    public function test_cancelled_grant_no_longer_counts(): void
    {
        $m = $this->grant()->grant($this->sponsor, '1', 'Errore', PointsGrant::EFFECT_CLIENT, $this->buyer(), at: $this->when());
        $this->grant()->cancel($m, 'inserito per sbaglio');

        $this->assertSame('cancelled', $m->refresh()->status);
        $input = SnapshotBuilder::build($this->period);
        $this->assertSame([], $input->movements);
        $this->assertSame([], $input->customerCoverage);
        $this->assertTrue(AuditEntry::where('action', 'points.cancelled')->exists());
    }

    public function test_invalid_requests_are_refused(): void
    {
        $cases = [
            'causale mancante' => fn () => $this->grant()->grant($this->sponsor, '5', '  ', at: $this->when()),
            'punti a zero' => fn () => $this->grant()->grant($this->sponsor, '0', 'x', at: $this->when()),
            'punti non numerici' => fn () => $this->grant()->grant($this->sponsor, 'abc', 'x', at: $this->when()),
            'cliente mancante' => fn () => $this->grant()->grant($this->sponsor, '5', 'x', PointsGrant::EFFECT_CLIENT, at: $this->when()),
            'acquisto senza importo' => fn () => $this->grant()->grant($this->sponsor, '5', 'x', PointsGrant::EFFECT_PURCHASE, $this->buyer(), at: $this->when()),
            'mesi fuori limite' => fn () => $this->grant()->grant($this->sponsor, '5', 'x', PointsGrant::EFFECT_CLIENT, $this->buyer(), months: 0, at: $this->when()),
            'effetto sconosciuto' => fn () => $this->grant()->grant($this->sponsor, '5', 'x', 'altro', at: $this->when()),
        ];
        foreach ($cases as $label => $run) {
            try {
                $run();
                $this->fail("Doveva essere rifiutato: {$label}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, PvMovement::count());
    }

    public function test_a_month_without_an_open_period_is_refused(): void
    {
        config(['lamess.auto_open_period' => false]);
        $this->expectException(InvalidArgumentException::class);
        $this->grant()->grant($this->sponsor, '5', 'x', at: $this->at('2026-12-10 10:00'));
    }

    public function test_terminated_consultant_cannot_receive_points(): void
    {
        $this->sponsor->update(['status' => 'terminated']);
        $this->expectException(InvalidArgumentException::class);
        $this->grant()->grant($this->sponsor, '5', 'x', at: $this->when());
    }
}
