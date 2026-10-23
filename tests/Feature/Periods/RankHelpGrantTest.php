<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Ledger\PointsGrant;
use App\Domain\Ledger\RankHelpGrant;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\AuditEntry;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** «Agenti regalati» e scadenza «valido fino al» dei clienti regalati. */
final class RankHelpGrantTest extends TestCase
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

    private function help(): RankHelpGrant
    {
        return app(RankHelpGrant::class);
    }

    public function test_added_agents_reach_the_engine_input_until_they_expire_or_are_cancelled(): void
    {
        $h = $this->help()->add($this->sponsor, 'PEARL', 2, 'Aiuto per Crystal', at: $this->at('2026-10-10 10:00'));

        $this->assertSame(['PEARL' => 2], SnapshotBuilder::build($this->period)->rankHelps[(string) $this->sponsor->id]);
        $this->assertTrue(AuditEntry::where('action', 'rank_help.added')->exists());

        $this->help()->cancel($h, 'errore');
        $this->assertSame([], SnapshotBuilder::build($this->period)->rankHelps);
        $this->expectException(InvalidArgumentException::class);
        $this->help()->cancel($h, 'ancora');
    }

    public function test_agents_expired_before_the_period_do_not_count(): void
    {
        $this->help()->add($this->sponsor, 'CRYSTAL', 1, 'Scaduti', new \DateTimeImmutable('2026-10-10'), $this->at('2026-10-05 10:00'));
        $this->assertSame(['CRYSTAL' => 1], SnapshotBuilder::build($this->period)->rankHelps[(string) $this->sponsor->id], 'scadono a ottobre: valgono ancora nel mese');

        $november = $this->openPeriod('2026-11', CompPlanVersion::inForceForPeriod('2026-11'));
        $this->assertSame([], SnapshotBuilder::build($november)->rankHelps, 'a novembre sono scaduti');
    }

    public function test_invalid_requests_are_refused(): void
    {
        $at = $this->at('2026-10-10 10:00');
        foreach ([
            'qualifica d\'ingresso' => fn () => $this->help()->add($this->sponsor, 'CONSULTANT', 1, 'x', at: $at),
            'qualifica inesistente' => fn () => $this->help()->add($this->sponsor, 'NOPE', 1, 'x', at: $at),
            'zero agenti' => fn () => $this->help()->add($this->sponsor, 'PEARL', 0, 'x', at: $at),
            'senza causale' => fn () => $this->help()->add($this->sponsor, 'PEARL', 1, ' ', at: $at),
            'scadenza nel passato' => fn () => $this->help()->add($this->sponsor, 'PEARL', 1, 'x', new \DateTimeImmutable('2026-10-01'), $at),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: doveva essere rifiutato");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_client_validity_can_be_given_as_a_date_and_counts_whole_months(): void
    {
        $m = app(PointsGrant::class)->grant($this->sponsor, '10', 'Socio', PointsGrant::EFFECT_CLIENT, $this->buyer(), at: $this->at('2026-10-10 10:00'), validUntil: new \DateTimeImmutable('2026-12-15'));
        $this->assertSame(2, $m->validity_months, 'novembre e dicembre');

        $this->expectException(InvalidArgumentException::class);
        app(PointsGrant::class)->grant($this->sponsor, '10', 'Socio', PointsGrant::EFFECT_CLIENT, $this->buyer(), at: $this->at('2026-10-10 10:00'), validUntil: new \DateTimeImmutable('2026-10-28'));
    }
}
