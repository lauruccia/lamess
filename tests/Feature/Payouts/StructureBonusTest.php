<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Domain\Access\Roles;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StructureBonusService;
use App\Domain\Periods\PeriodAutomation;
use App\Domain\Periods\PeriodService;
use App\Domain\Settings\SystemSettings;
use App\Filament\Resources\StructureBonuses\Pages\ListStructureBonuses;
use App\Models\CommissionLine;
use App\Models\CommissionReceipt;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\StructureBonus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Bonus di Struttura: registro settimanale, conferma tracciata, pagamento una sola volta, fuori dal calcolo mensile. */
final class StructureBonusTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    private Period $period;

    private Consultant $crystal;

    private Consultant $x;

    private function network(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous', 'kosmos_rank_maintenance' => 'none'];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $month = now('Europe/Rome')->format('Y-m');
        $this->period = $this->openPeriod($month, $plan);
        $this->shipping();
        $product = $this->product('A', '123.00');
        $start = now('Europe/Rome')->startOfMonth()->addMinutes(5)->format('Y-m-d H:i');
        $this->crystal = $this->consultant('C', null, 'CRYSTAL', $start);
        $this->x = $this->consultant('X', $this->crystal, null, $start);
        $svc = app(OrderService::class);
        for ($i = 0; $i < 3; $i++) {
            $svc->markPaid($svc->place($this->buyer($this->x), [$product->id => 1], 'IT', 'transfer', $this->billing()));
        }
    }

    public function test_weekly_run_writes_the_bonus_once_and_keeps_it_out_of_the_monthly_lines(): void
    {
        $this->network();
        $svc = app(StructureBonusService::class);

        $first = $svc->syncOpenPeriods();
        $this->assertSame(1, $first['new']);
        $bonus = StructureBonus::firstOrFail();
        $this->assertSame($this->crystal->id, $bonus->beneficiary_id);
        $this->assertSame('60.00', (string) $bonus->amount);
        $this->assertSame(StructureBonus::CALCULATED, $bonus->status);

        $this->assertSame(0, $svc->syncOpenPeriods()['new'], 'un secondo calcolo non duplica il bonus');
        $this->assertSame(1, StructureBonus::count());

        $run = app(PeriodService::class)->calculate($this->period);
        $this->assertSame(0, CommissionLine::where('run_id', $run->id)->where('bonus_type', 'structure')->count());
    }

    public function test_confirmation_records_who_and_when_and_a_bonus_is_paid_only_once(): void
    {
        $this->network();
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin);
        $svc = app(StructureBonusService::class);
        $svc->syncOpenPeriods();
        $id = StructureBonus::firstOrFail()->id;

        $this->crystal->update(['kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'C']);
        $this->assertSame(0, $svc->markPaid([$id], 'BON-1')['paid'], 'non si paga senza conferma');
        $this->assertSame(1, $svc->confirm([$id]));
        $b = StructureBonus::findOrFail($id);
        $this->assertSame($admin->id, $b->confirmed_by);
        $this->assertNotNull($b->confirmed_at);
        $this->assertSame(0, $svc->confirm([$id]), 'la conferma non si ripete');

        $this->assertSame(1, $svc->markPaid([$id], 'BON-1')['paid']);
        $this->assertSame(0, $svc->markPaid([$id], 'BON-2')['paid'], 'già pagato: non si paga due volte');
        $b->refresh();
        $this->assertSame('BON-1', $b->payment_reference);
        $this->assertSame($admin->id, $b->paid_by);

        $svc->syncOpenPeriods();
        $this->assertSame(1, StructureBonus::count(), 'i ricalcoli non rigenerano un bonus già pagato');
        $this->assertSame(StructureBonus::PAID, $b->refresh()->status);
    }

    public function test_closing_the_month_writes_the_last_bonuses_in_the_register(): void
    {
        $this->network();
        $svc = app(PeriodService::class);
        $svc->calculate($this->period, 'official');
        $this->assertSame(0, StructureBonus::count());
        $svc->close($this->period);

        $this->assertSame(1, StructureBonus::count());
        $this->assertSame(0, CommissionLine::where('bonus_type', 'structure')->count());
    }

    public function test_weekly_command_runs_only_on_the_chosen_day_and_hour(): void
    {
        $this->network();
        config(['lamess.structure_bonus_auto' => true, 'lamess.structure_bonus_weekday' => 3, 'lamess.structure_bonus_hour' => 9]);

        $this->travelTo(CarbonImmutable::parse(now('Europe/Rome')->startOfMonth()->addDays(9), 'Europe/Rome')->next(CarbonImmutable::TUESDAY)->setTime(9, 5));
        $this->artisan('lamess:bonus-struttura')->assertSuccessful();
        $this->assertSame(0, StructureBonus::count(), 'martedì: non è il giorno scelto');

        $this->travelTo(CarbonImmutable::now('Europe/Rome')->next(CarbonImmutable::WEDNESDAY)->setTime(9, 5));
        $this->artisan('lamess:bonus-struttura')->assertSuccessful();
        $this->assertSame(1, StructureBonus::count());
    }

    public function test_monthly_calculation_date_follows_the_setting(): void
    {
        $this->network();
        $auto = app(PeriodAutomation::class);
        config(['lamess.commission_calc_hour' => 1]);
        $code = $this->period->code;
        $month = CarbonImmutable::parse($code.'-01', 'Europe/Rome');

        config(['lamess.commission_calc_day' => '1']);
        $this->assertSame($month->addMonth()->setTime(1, 0)->toDateTimeString(), $auto->dueAt($this->period)->toDateTimeString());
        config(['lamess.commission_calc_day' => '15']);
        $this->assertSame($month->addMonth()->setDay(15)->setTime(1, 0)->toDateTimeString(), $auto->dueAt($this->period)->toDateTimeString());
        config(['lamess.commission_calc_day' => 'end', 'lamess.commission_calc_hour' => 22]);
        $this->assertSame($month->endOfMonth()->setTime(22, 0)->toDateTimeString(), $auto->dueAt($this->period)->toDateTimeString());
    }

    public function test_admin_page_lists_confirms_and_marks_paid(): void
    {
        $this->network();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->crystal->update(['kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'C']);
        app(StructureBonusService::class)->syncOpenPeriods();
        $bonus = StructureBonus::firstOrFail();

        Livewire::test(ListStructureBonuses::class)
            ->assertCanSeeTableRecords([$bonus])
            ->callTableBulkAction('confirm', [$bonus])
            ->callTableBulkAction('paid', [$bonus], ['reference' => 'BON-77']);

        $bonus->refresh();
        $this->assertSame(StructureBonus::PAID, $bonus->status);
        $this->assertSame('BON-77', $bonus->payment_reference);
    }

    public function test_settings_for_weekly_day_hour_and_monthly_date_are_editable_by_the_super_admin(): void
    {
        foreach (['commission_calc_day', 'commission_calc_hour', 'structure_bonus_auto', 'structure_bonus_weekday', 'structure_bonus_hour'] as $key) {
            $this->assertArrayHasKey($key, SystemSettings::DEFINITIONS);
            $this->assertNotNull(config(SystemSettings::DEFINITIONS[$key]['config']));
        }
    }

    public function test_payment_applies_withholding_and_issues_a_receipt_like_any_other_payout(): void
    {
        $this->network();
        config(['lamess.fiscal.receipts_enabled' => true, 'lamess.fiscal.withholding_enabled' => true, 'lamess.fiscal.withholding_pct' => '20', 'lamess.fiscal.taxable_pct' => '100']);
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->crystal->update(['kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'C']);
        $svc = app(StructureBonusService::class);
        $svc->syncOpenPeriods();
        $svc->confirm([StructureBonus::firstOrFail()->id]);

        $r = $svc->markPaid([StructureBonus::firstOrFail()->id], 'BON-9');

        $this->assertSame(1, $r['payouts']);
        $bonus = StructureBonus::firstOrFail();
        $payout = Payout::findOrFail($bonus->payout_id);
        $this->assertSame('60.00', (string) $payout->gross_amount);
        $this->assertSame('12.00', (string) $payout->withholding_amount);
        $this->assertSame('48.00', (string) $payout->amount);
        $receipt = CommissionReceipt::where('payout_id', $payout->id)->firstOrFail();
        $this->assertSame('48.00', (string) $receipt->net);
        $this->assertNotSame('', app(ReceiptService::class)->pdf($receipt));
    }

    public function test_a_blocked_incaricato_is_not_paid_and_the_bonus_stays_confirmed(): void
    {
        $this->network();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $svc = app(StructureBonusService::class);
        $svc->syncOpenPeriods();
        $id = StructureBonus::firstOrFail()->id;
        $svc->confirm([$id]);

        $r = $svc->markPaid([$id], 'BON-3');

        $this->assertSame(0, $r['paid']);
        $this->assertArrayHasKey('C', $r['skipped']);
        $this->assertSame(StructureBonus::CONFIRMED, StructureBonus::findOrFail($id)->status);
    }
}
