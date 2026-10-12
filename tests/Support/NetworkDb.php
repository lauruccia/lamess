<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ledger\PvLedger;
use App\Domain\Periods\PeriodService;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\RankAchievement;
use Database\Seeders\PrelaunchPlanSeeder;
use DateTimeImmutable;
use DateTimeZone;

/** Helper per i test con database. */
trait NetworkDb
{
    protected function plan(array $settings = [], array $policies = []): CompPlanVersion
    {
        if ($settings === [] && $policies === []) {
            $this->seed(PrelaunchPlanSeeder::class);

            return CompPlanVersion::firstOrFail();
        }
        $data = json_decode((string) file_get_contents(base_path('config/lamess/plan-prelaunch-2026-07-07.json')), true);
        $data['settings'] = array_replace($data['settings'] ?? [], $settings);
        $data['policies'] = array_replace($data['policies'] ?? [], $policies);

        return CompPlanVersion::createFromArray('Piano di test', $data, CompPlanVersion::ACTIVE);
    }

    protected function openPeriod(string $code, ?CompPlanVersion $plan = null): Period
    {
        return app(PeriodService::class)->open($code, $plan ?? $this->plan());
    }

    protected function consultant(string $code, ?Consultant $sponsor = null, ?string $rank = null, string $since = '2026-10-01 08:00'): Consultant
    {
        $c = Consultant::create([
            'code' => $code, 'name' => "Incaricato {$code}", 'sponsor_id' => $sponsor?->id, 'original_sponsor_id' => $sponsor?->id,
            'status' => 'active', 'since' => $this->at($since), 'onboarding_complete' => true,
        ]);
        if ($rank !== null) {
            RankAchievement::create([
                'consultant_id' => $c->id, 'rank_code' => $rank, 'achieved_at' => $this->at('2026-08-01 10:00'),
                'effective_from' => '2026-09-01', 'source' => 'manual',
            ]);
        }

        return $c;
    }

    protected function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Europe/Rome'));
    }

    protected function sale(Period $p, Consultant $c, string $pv, string $on = '2026-10-10 10:00', string $kind = 'pvf', string $status = 'confirmed'): PvMovement
    {
        return PvLedger::record($p, $c, $kind, $pv, $this->at($on), status: $status);
    }
}
