<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Domain\Access\Roles;
use App\Domain\Payouts\PayoutException;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\StatementService;
use App\Domain\Periods\PeriodAutomation;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Periods\Pages\ViewPeriod;
use App\Filament\Widgets\SchedulerStatus;
use App\Models\AuditEntry;
use App\Models\CommissionLine;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\RankAchievement;
use App\Models\Settlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class PayoutsTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function svc(): PayoutService
    {
        return app(PayoutService::class);
    }

    private function close(Period $p): Period
    {
        app(PeriodService::class)->calculate($p, 'official');

        return app(PeriodService::class)->close($p);
    }

    /** Incaricato pagabile: KYC verificato e IBAN presenti. */
    private function payable(Consultant $c): Consultant
    {
        $c->update(['kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => $c->name]);

        return $c;
    }

    private function superAdmin(): User
    {
        $u = User::factory()->role('super_admin')->create();
        $this->actingAs($u);

        return $u;
    }

    /** A Diamond sopra B Crystal; B vende 8.000 PV ⇒ B diretto 1.680 €; A team 720 € + matching 168 € = 888 €. */
    private function october(): array
    {
        $plan = $this->plan();
        $p = $this->openPeriod('2026-10', $plan);
        $a = $this->payable($this->consultant('A', null, 'DIAMOND'));
        $b = $this->payable($this->consultant('B', $a, 'CRYSTAL'));
        $this->sale($p, $b, '8000');

        return [$plan, $p, $a, $b];
    }

    public function test_closing_a_period_generates_one_settlement_per_beneficiary(): void
    {
        [, $p, $a, $b] = $this->october();
        $this->close($p);

        $this->assertSame('888.00', (string) Settlement::where('period_id', $p->id)->where('consultant_id', $a->id)->firstOrFail()->accrued);
        $this->assertSame('1680.00', (string) Settlement::where('period_id', $p->id)->where('consultant_id', $b->id)->firstOrFail()->accrued);
        $this->assertSame(2, Settlement::count());
    }

    public function test_prepare_payouts_is_idempotent_and_pays_above_threshold(): void
    {
        [, $p, $a, $b] = $this->october();
        $this->close($p);

        $r = $this->svc()->preparePayouts($p->refresh());
        $this->assertSame(2, $r['created']);
        $this->assertSame('2568.00', $r['amount']);
        $this->assertSame('888.00', (string) Payout::where('consultant_id', $a->id)->firstOrFail()->amount);

        $again = $this->svc()->preparePayouts($p);
        $this->assertSame(0, $again['created']);
        $this->assertSame(2, Payout::count());
    }

    public function test_below_threshold_accumulates_into_the_next_month(): void
    {
        $plan = $this->plan();
        $oct = $this->openPeriod('2026-10', $plan);
        $a = $this->payable($this->consultant('A', null, 'CONSULTANT'));
        $this->sale($oct, $a, '50'); // 15% di 50 = 7,50 €
        $this->close($oct);

        $r1 = $this->svc()->preparePayouts($oct->refresh());
        $this->assertSame(0, $r1['created']);
        $this->assertSame(1, $r1['below_min']);

        $nov = $this->openPeriod('2026-11', $plan);
        $this->sale($nov, $a, '60', '2026-11-10 10:00'); // 9,00 € ⇒ 16,50 € cumulati
        $this->close($nov);
        $r2 = $this->svc()->preparePayouts($nov->refresh());

        $this->assertSame(1, $r2['created']);
        $payout = Payout::firstOrFail();
        $this->assertSame('16.50', (string) $payout->amount);
        $this->assertSame(2, Settlement::where('payout_id', $payout->id)->count());
    }

    public function test_blocked_consultants_get_no_payout_but_keep_the_balance(): void
    {
        [, $p, $a, $b] = $this->october();
        $b->update(['kyc_status' => 'submitted']);
        $a->update(['iban' => null]);
        $this->close($p);

        $r = $this->svc()->preparePayouts($p->refresh());

        $this->assertSame(0, $r['created']);
        $this->assertSame(2, $r['blocked']);
        $this->assertSame('1680.00', (string) $this->svc()->openBalance($b->id));
    }

    public function test_mark_paid_and_cancel(): void
    {
        [, $p, $a] = $this->october();
        $this->close($p);
        $this->svc()->preparePayouts($p->refresh());
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();

        $this->svc()->markPaid($payout, 'CRO123', new \DateTimeImmutable('2026-11-15'));
        $this->assertSame('paid', $payout->refresh()->status);
        $this->assertSame('paid', Settlement::where('consultant_id', $a->id)->firstOrFail()->status);
        $this->expectException(PayoutException::class);
        $this->svc()->cancel($payout, 'errore');
    }

    public function test_cancel_releases_the_settlements(): void
    {
        [, $p, $a] = $this->october();
        $this->close($p);
        $this->svc()->preparePayouts($p->refresh());
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();

        $this->svc()->cancel($payout, 'IBAN da correggere');

        $this->assertSame('cancelled', $payout->refresh()->status);
        $this->assertNull(Settlement::where('consultant_id', $a->id)->firstOrFail()->payout_id);
        $this->assertSame('888.00', (string) $this->svc()->openBalance($a->id));
        $this->assertSame(1, $this->svc()->preparePayouts($p)['created']); // di nuovo pagabile
    }

    public function test_reopen_requires_super_admin_and_reason(): void
    {
        [, $p] = $this->october();
        $this->close($p);

        try {
            app(PeriodService::class)->reopen($p, 'prova');
            $this->fail('Senza super_admin non deve riaprire');
        } catch (PeriodStateException $e) {
            $this->assertStringContainsString('super_admin', $e->getMessage());
        }

        $this->superAdmin();
        $this->expectException(PeriodStateException::class);
        app(PeriodService::class)->reopen($p, '  ');
    }

    public function test_reopen_restores_open_state_and_allows_recalculation(): void
    {
        [, $p, $a, $b] = $this->october();
        $this->close($p);
        $this->svc()->preparePayouts($p->refresh());
        $this->superAdmin();

        $p = app(PeriodService::class)->reopen($p, 'Reso non registrato');

        $this->assertSame(Period::REVIEW, $p->status);
        $this->assertNull($p->closed_at);
        $this->assertSame(0, Settlement::count());
        $this->assertSame('cancelled', Payout::firstOrFail()->status);
        $this->assertSame(0, CommissionLine::where('status', 'locked')->count());
        $this->assertTrue(AuditEntry::where('action', 'period.reopened')->exists());

        // di nuovo modificabile: un nuovo movimento, ricalcolo e nuova chiusura
        $this->sale($p, $b, '100', '2026-10-12 10:00');
        $this->close($p);
        $this->assertSame('1701.00', (string) Settlement::where('consultant_id', $b->id)->firstOrFail()->accrued);
    }

    public function test_reopen_removes_ranks_written_at_close(): void
    {
        $plan = $this->plan();
        $p = $this->openPeriod('2026-10', $plan);
        $a = $this->consultant('A', null, 'CONSULTANT');
        $this->sale($p, $a, '700'); // oltre 600 PR ⇒ Pearl
        $this->close($p);
        $this->assertTrue(RankAchievement::where('consultant_id', $a->id)->where('rank_code', 'PEARL')->exists());
        $this->superAdmin();

        app(PeriodService::class)->reopen($p, 'verifica');

        $this->assertFalse(RankAchievement::where('consultant_id', $a->id)->where('rank_code', 'PEARL')->exists());
    }

    public function test_cannot_reopen_when_paid_or_when_a_later_month_is_closed(): void
    {
        $plan = $this->plan();
        $oct = $this->openPeriod('2026-10', $plan);
        $a = $this->payable($this->consultant('A', null, 'DIAMOND'));
        $this->sale($oct, $a, '1000');
        $this->close($oct);
        $nov = $this->openPeriod('2026-11', $plan);
        $this->sale($nov, $a, '1000', '2026-11-10 10:00');
        $this->close($nov);
        $this->superAdmin();

        try {
            app(PeriodService::class)->reopen($oct->refresh(), 'x');
            $this->fail('Con novembre chiuso non si riapre ottobre');
        } catch (PeriodStateException $e) {
            $this->assertStringContainsString('2026-11', $e->getMessage());
        }

        $this->svc()->preparePayouts($nov->refresh());
        $this->svc()->markPaid(Payout::firstOrFail(), 'CRO1', new \DateTimeImmutable('2026-12-15'));
        $this->expectException(PeriodStateException::class);
        app(PeriodService::class)->reopen($nov->refresh(), 'x');
    }

    public function test_statement_lists_lines_with_reasons_and_renders_pdf(): void
    {
        [, $p, $a] = $this->october();
        $this->close($p);

        $s = app(StatementService::class)->build($p->refresh(), $a);

        $this->assertSame('888.00', $s['accrued']);
        $this->assertArrayHasKey('team', $s['groups']);
        $this->assertNotEmpty($s['groups']['team']['lines'][0]['why']);
        $this->assertStringStartsWith('%PDF', app(StatementService::class)->pdf($p, $a));
    }

    public function test_statement_needs_a_closed_period(): void
    {
        [, $p, $a] = $this->october();
        $this->expectException(PayoutException::class);
        app(StatementService::class)->build($p, $a);
    }

    public function test_consultant_sees_only_own_statements(): void
    {
        [, $p, $a, $b] = $this->october();
        $ua = User::factory()->create();
        $a->update(['user_id' => $ua->id]);
        $ub = User::factory()->create();
        $b->update(['user_id' => $ub->id]);
        $this->close($p);

        $this->actingAs($ua)->get('/area/statini')->assertOk()->assertSee('2026-10');
        $this->actingAs($ua)->get('/area/statini/2026-10')->assertOk()->assertSee('Team Bonus');
        $this->actingAs($ua)->get('/area/statini/2026-10/pdf')->assertOk();
        // un cliente senza profilo incaricato non vede nulla
        $this->actingAs(User::factory()->create())->get('/area/statini')->assertNotFound();
    }

    public function test_consultant_cannot_open_a_statement_of_an_open_month(): void
    {
        [, $p, $a] = $this->october();
        $ua = User::factory()->create();
        $a->update(['user_id' => $ua->id]);

        $this->actingAs($ua)->get('/area/statini/2026-10')->assertNotFound();
    }

    public function test_exports_need_permission_and_write_the_audit_log(): void
    {
        [, $p, $a] = $this->october();
        $this->close($p);
        $this->svc()->preparePayouts($p->refresh());

        $this->actingAs(User::factory()->create())->get('/admin-files/export/commissions/2026-10')->assertForbidden();

        $this->superAdmin();
        $res = $this->get('/admin-files/export/commissions/2026-10')->assertOk();
        $this->assertStringContainsString('Team Bonus', $res->getContent());
        $this->assertStringContainsString('720,00', $res->getContent());
        $this->assertTrue(AuditEntry::where('action', 'export.commissions')->exists());

        $res2 = $this->get('/admin-files/export/payouts')->assertOk();
        $this->assertStringContainsString('IT60X0542811101000000123456', $res2->getContent());
        $this->assertTrue(AuditEntry::where('action', 'export.payouts')->exists());
        $this->assertNotNull(Payout::first()->exported_at);

        $this->get("/admin-files/statement/2026-10/{$a->id}")->assertOk();
    }

    public function test_admin_pages_render_and_actions_work(): void
    {
        [, $p] = $this->october();
        $this->close($p);
        $this->superAdmin();

        $this->get('/admin/settlements')->assertOk();
        $this->get('/admin/payouts')->assertOk();
        $this->get('/admin/periods/'.$p->id)->assertOk();

        Livewire::test(ListPayouts::class)
            ->callAction('prepare', ['code' => '2026-10'])->assertNotified();
        $this->assertSame(2, Payout::count());

        Livewire::test(ListPayouts::class)
            ->callTableAction('paid', Payout::first(), ['reference' => 'CRO9', 'paid_at' => now()->toDateString()])->assertNotified();
        $this->assertSame(1, Payout::where('status', 'paid')->count());
    }

    public function test_finance_profile_can_manage_payouts_but_board_only_views(): void
    {
        $this->assertContains('payouts.manage', Roles::MATRIX['admin_finance']);
        $this->assertNotContains('payouts.manage', Roles::MATRIX['board']);
        $this->assertContains('payouts.view', Roles::MATRIX['board']);
    }

    public function test_automatic_preparation_waits_for_the_payment_day_and_runs_once(): void
    {
        [, $p] = $this->october();
        $this->close($p);

        $this->assertSame(0, $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 14)->setTime(23, 0))['periods'], 'prima del 15 non si prepara nulla');
        $this->assertSame(0, Payout::count());

        $r = $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 15)->setTime(5, 0));
        $this->assertSame(['periods' => 1, 'created' => 2, 'amount' => '2568.00'], $r);
        $this->assertNotNull($p->refresh()->payouts_prepared_at);

        Payout::first()->update(['status' => Payout::CANCELLED, 'cancel_reason' => 'prova']);
        $this->assertSame(0, $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 20))['periods'], 'un pagamento annullato non viene ricreato da solo');
    }

    public function test_automatic_preparation_is_skipped_when_payment_is_on_request_and_resets_on_reopen(): void
    {
        $plan = $this->plan(['payout_mode' => 'on_request']);
        $p = $this->openPeriod('2026-10', $plan);
        $a = $this->payable($this->consultant('A', null, 'CONSULTANT'));
        $this->sale($p, $a, '1000');
        $this->close($p);
        $this->assertSame(0, $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 20))['periods']);

        $p->update(['payouts_prepared_at' => now()]);
        $this->superAdmin();
        app(PeriodService::class)->reopen($p->refresh(), 'correzione');
        $this->assertNull($p->refresh()->payouts_prepared_at);
    }

    public function test_command_and_scheduler_status_widget(): void
    {
        $this->artisan('lamess:pagamenti')->assertSuccessful();

        $this->superAdmin();
        Cache::forget('lamess.scheduler.heartbeat');
        Livewire::test(SchedulerStatus::class)->assertSee('Mai partito');
        Cache::forever('lamess.scheduler.heartbeat', now()->toIso8601String());
        Livewire::test(SchedulerStatus::class)->assertSee('Attivo');
        Cache::forever('lamess.scheduler.heartbeat', now()->subHour()->toIso8601String());
        Livewire::test(SchedulerStatus::class)->assertSee('Fermo');
    }

    private function auto(string $mode, string $at): array
    {
        config(['lamess.period_auto_close' => $mode]);

        return app(PeriodAutomation::class)->run(CarbonImmutable::parse($at, 'Europe/Rome'));
    }

    public function test_auto_close_in_calculate_mode_leaves_the_month_in_review(): void
    {
        [, $p] = $this->october();

        $this->assertSame('none', $this->auto('calculate', '2026-10-20 01:00')['action'], 'il mese non è ancora finito');
        $this->assertSame('calculated', $this->auto('calculate', '2026-11-01 01:00')['action']);
        $this->assertSame(Period::REVIEW, $p->refresh()->status);
        $this->assertSame(0, Settlement::count());
        $this->assertSame('waiting', $this->auto('calculate', '2026-11-02 01:00')['action']);
    }

    public function test_auto_close_in_close_mode_closes_then_pays_on_the_15th(): void
    {
        [, $p] = $this->october();

        $this->assertSame('closed', $this->auto('close', '2026-11-01 01:00')['action']);
        $this->assertTrue($p->refresh()->isClosed());
        $this->assertSame(2, Settlement::count());
        $this->assertTrue(AuditEntry::where('action', 'period.auto_closed')->exists());

        $this->assertSame(0, $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 14))['created']);
        $this->assertSame(2, $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 15))['created']);
    }

    public function test_auto_close_stops_on_pending_payments_and_retries(): void
    {
        [, $p] = $this->october();
        $p->movements()->first()->update(['status' => 'pending_payment']);

        $r = $this->auto('close', '2026-11-01 01:00');
        $this->assertSame('blocked', $r['action']);
        $this->assertStringContainsString('in attesa di pagamento', $r['message']);
        $this->auto('close', '2026-11-02 01:00');
        $this->assertSame(1, AuditEntry::where('action', 'period.auto_blocked')->count(), 'il blocco si scrive nel registro una volta sola');

        $p->movements()->first()->update(['status' => 'confirmed']);
        $this->assertSame('closed', $this->auto('close', '2026-11-03 01:00')['action']);
    }

    public function test_auto_close_ignores_old_months_and_can_be_switched_off(): void
    {
        [, $p] = $this->october();

        $this->assertSame('skipped', $this->auto('close', '2027-01-15 01:00')['action'], 'i mesi arretrati si chiudono a mano');
        $this->assertSame('off', $this->auto('off', '2026-11-01 01:00')['action']);
        $this->assertSame(Period::OPEN, $p->refresh()->status);
        $this->artisan('lamess:chiusura-mese')->assertSuccessful();
    }

    public function test_historical_month_is_marked_paid_and_never_enters_a_new_payout(): void
    {
        $plan = $this->plan();
        $sep = $this->openPeriod('2026-09', $plan);
        $a = $this->payable($this->consultant('A', null, 'CONSULTANT', '2026-08-01 08:00'));
        $this->sale($sep, $a, '1000', '2026-09-10 10:00');
        $this->close($sep);
        Settlement::query()->delete();   // chiuso prima della Fase 6: nessuna liquidazione

        $this->superAdmin();
        Livewire::test(ViewPeriod::class, ['record' => $sep->getKey()])->callAction('historical')->assertNotified();
        $this->assertSame(1, Settlement::where('status', 'paid')->count());
        $this->svc()->generateSettlements($sep->refresh());
        $this->assertSame(1, Settlement::where('status', 'paid')->count(), 'rigenerare le liquidazioni non riapre un mese storico');

        $oct = $this->openPeriod('2026-10', $plan);
        $this->sale($oct, $a, '1000');
        $this->close($oct);
        $r = $this->svc()->prepareDue(now('Europe/Rome')->setDate(2026, 11, 15));
        $this->assertSame(1, $r['created']);
        $this->assertSame((string) Settlement::where('period_id', $oct->id)->firstOrFail()->accrued, (string) Payout::firstOrFail()->amount, 'solo ottobre, senza settembre');
        $this->assertSame(1, Settlement::where('payout_id', Payout::firstOrFail()->id)->count());
        $this->assertTrue(AuditEntry::where('action', 'payouts.historical')->exists());
    }
}
