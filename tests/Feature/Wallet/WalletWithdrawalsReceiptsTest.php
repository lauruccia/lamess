<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domain\Access\Roles;
use App\Domain\Payouts\FiscalCalculator;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\ReceiptService;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Domain\Settings\SystemSettings;
use App\Domain\Support\SupportException;
use App\Domain\System\GoLiveCheck;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Domain\Wallet\WithdrawalService;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Models\CommissionReceipt;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Wallet, prelievi su richiesta, trattenute e ricevute provvigionali. */
final class WalletWithdrawalsReceiptsTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function fiscalOn(): void
    {
        config(['lamess.fiscal.receipts_enabled' => true, 'lamess.fiscal.withholding_enabled' => true]);
    }

    private function payable(Consultant $c): Consultant
    {
        $c->update(['user_id' => User::factory()->create()->id, 'kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => $c->name]);

        return $c->refresh();
    }

    /** A Diamond sopra B Crystal; B vende 8.000 PV ⇒ B 1.680 €, A 888 €. Mese chiuso con la modalità indicata. */
    private function closedOctober(string $mode = 'on_request'): array
    {
        $p = $this->openPeriod('2026-10', $this->plan(['payout_mode' => $mode]));
        $a = $this->payable($this->consultant('A', null, 'DIAMOND'));
        $b = $this->payable($this->consultant('B', $a, 'CRYSTAL'));
        $this->sale($p, $b, '8000');
        app(PeriodService::class)->calculate($p, 'official');
        app(PeriodService::class)->close($p);

        return [$p->refresh(), $a, $b];
    }

    private function admin(): User
    {
        $u = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($u);

        return $u;
    }

    // ── trattenute ──────────────────────────────────────────────────────────

    public function test_withholding_is_23_percent_of_78_percent_and_g21_net(): void
    {
        $this->fiscalOn();
        $f = FiscalCalculator::compute('occasional', '100', '0', '2.50');

        $this->assertSame('78.00', $f['taxable_base']);
        $this->assertSame('17.94', $f['withholding']);
        $this->assertSame('79.56', $f['net']); // 100 − 17,94 − 2,50
    }

    public function test_with_fiscal_off_net_is_gross_minus_fee_only(): void
    {
        $f = FiscalCalculator::compute('occasional', '100', '0', '2.50');
        $this->assertSame('0.00', $f['withholding']);
        $this->assertSame('97.50', $f['net']);
        $this->assertSame('100.00', FiscalCalculator::compute('occasional', '100')['net']);
    }

    public function test_vat_holders_get_no_withholding_and_no_inps(): void
    {
        $this->fiscalOn();
        config(['lamess.fiscal.inps_enabled' => true]);
        $f = FiscalCalculator::compute('vat_holder', '10000', '0');

        $this->assertSame('0.00', $f['withholding']);
        $this->assertSame('0.00', $f['inps_worker']);
        $this->assertSame('10000.00', $f['net']);
    }

    public function test_inps_applies_only_above_the_yearly_threshold(): void
    {
        $this->fiscalOn();
        config(['lamess.fiscal.inps_enabled' => true]);

        $below = FiscalCalculator::compute('occasional', '300', '4000');
        $this->assertSame('0.00', $below['inps_total']);

        // 4.900 già incassati + 300: oltre soglia solo 200 → base 78% = 156 → 33,72% = 52,60 → quota incaricato 33,33% = 17,53
        $over = FiscalCalculator::compute('occasional', '300', '4900');
        $this->assertSame('156.00', $over['inps_base']);
        $this->assertSame('52.60', $over['inps_total']);
        $this->assertSame('17.53', $over['inps_worker']);
        $this->assertSame('35.07', $over['inps_company']);

        // già oltre soglia: tutto il nuovo lordo contribuisce
        $this->assertSame('234.00', FiscalCalculator::compute('occasional', '300', '6000')['inps_base']);
    }

    // ── wallet ──────────────────────────────────────────────────────────────

    public function test_monthly_auto_never_touches_the_wallet_and_pays_gross_with_fiscal_off(): void
    {
        [$p, $a] = $this->closedOctober('monthly_auto');
        $this->assertSame(0, WalletTransaction::count());

        app(PayoutService::class)->preparePayouts($p);
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();
        $this->assertSame('888.00', (string) $payout->amount);
        $this->assertSame('888.00', (string) $payout->gross_amount);
    }

    public function test_monthly_auto_with_withholding_pays_net_and_issues_the_receipt_on_payment(): void
    {
        $this->fiscalOn();
        [$p, $a] = $this->closedOctober('monthly_auto');
        $this->admin();
        app(PayoutService::class)->preparePayouts($p);
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();

        // 888 × 78% = 692,64 × 23% = 159,31
        $this->assertSame('159.31', (string) $payout->withholding_amount);
        $this->assertSame('728.69', (string) $payout->amount);

        app(PayoutService::class)->markPaid($payout, 'CRO123', new \DateTimeImmutable('2026-11-16'));
        $r = CommissionReceipt::where('payout_id', $payout->id)->firstOrFail();
        $this->assertMatchesRegularExpression('#^RP/\d{4}/00001$#', $r->number);
        $this->assertSame('888.00', (string) $r->gross);
        $this->assertSame('728.69', (string) $r->net);
        $this->assertSame('published', $r->status);
        $this->assertNotEmpty(app(ReceiptService::class)->pdf($r));
    }

    public function test_no_receipt_when_receipts_are_off(): void
    {
        [$p, $a] = $this->closedOctober('monthly_auto');
        $this->admin();
        app(PayoutService::class)->preparePayouts($p);
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();
        app(PayoutService::class)->markPaid($payout, 'CRO1', new \DateTimeImmutable('2026-11-16'));

        $this->assertSame(0, CommissionReceipt::count());
    }

    public function test_on_request_closing_credits_the_wallet_and_nothing_goes_to_payouts(): void
    {
        [$p, $a, $b] = $this->closedOctober();

        $this->assertSame('888.00', (string) app(WalletService::class)->balance($a->id));
        $this->assertSame('1680.00', (string) app(WalletService::class)->balance($b->id));
        $this->assertSame(['credited'], Settlement::pluck('status')->unique()->values()->all());
        $this->assertSame(0, app(PayoutService::class)->preparePayouts($p)['created']);
        // idempotente
        $this->assertSame(0, app(WalletService::class)->creditPeriod($p));
    }

    public function test_balance_is_always_the_sum_of_transactions(): void
    {
        [, $a] = $this->closedOctober();
        $this->admin();
        $w = app(WalletService::class);
        $w->adjust($a, '-100.50', 'Storno concordato');
        $w->adjust($a, '20', 'Premio una tantum');

        $sum = WalletTransaction::where('consultant_id', $a->id)->get()->reduce(fn ($c, $t) => (string) BigDecimal::of($c)->plus((string) $t->amount)->toScale(2), '0.00');
        $this->assertSame($sum, (string) $w->balance($a->id));
        $this->assertSame($sum, (string) WalletTransaction::where('consultant_id', $a->id)->orderByDesc('id')->first()->balance_after);
    }

    public function test_a_negative_balance_has_nothing_available_and_manual_adjustment_needs_a_reason(): void
    {
        [, $a] = $this->closedOctober();
        $this->admin();
        $w = app(WalletService::class);
        $w->adjust($a, '-1000', 'Recupero');

        $this->assertSame('0.00', $w->situation($a)['available']);
        $this->expectException(WalletException::class);
        $w->adjust($a, '5', ' ');
    }

    // ── prelievi ────────────────────────────────────────────────────────────

    public function test_withdrawal_rules_minimum_available_and_blocked_balance(): void
    {
        [, $a, $b] = $this->closedOctober();
        $svc = app(WithdrawalService::class);

        foreach ([['14.99', 'minimo'], ['900', 'al massimo 888.00']] as [$amount, $needle]) {
            try {
                $svc->request($a->refresh(), $amount);
                $this->fail("Prelievo di {$amount} non dovrebbe passare.");
            } catch (WalletException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $b->update(['kyc_status' => 'none']);
        try {
            $svc->request($b->refresh(), '100');
            $this->fail('Con KYC non verificato il saldo è bloccato.');
        } catch (WalletException $e) {
            $this->assertStringContainsString('KYC', $e->getMessage());
        }
        $this->assertSame('1680.00', app(WalletService::class)->situation($b)['blocked']);
        $this->assertSame('0.00', app(WalletService::class)->situation($b)['available']);
    }

    public function test_withdrawals_are_off_when_the_plan_pays_monthly(): void
    {
        [, $a] = $this->closedOctober('monthly_auto');

        $this->expectException(WalletException::class);
        app(WithdrawalService::class)->request($a, '100');
    }

    public function test_full_withdrawal_flow_approve_pay_and_ledger(): void
    {
        $this->fiscalOn();
        [, $a] = $this->closedOctober();
        $this->admin();
        $svc = app(WithdrawalService::class);

        $r = $svc->request($a, '100');
        $this->assertSame('17.94', (string) $r->withholding);
        $this->assertSame('2.50', (string) $r->fee);
        $this->assertSame('79.56', (string) $r->net);
        $this->assertSame('788.00', app(WalletService::class)->situation($a)['available']); // 100 impegnati

        $r = $svc->approve($r);
        $payout = $r->payout;
        $this->assertSame(Payout::TO_PAY, $payout->status);
        $this->assertSame('79.56', (string) $payout->amount);
        $this->assertSame('100.00', (string) $payout->gross_amount);

        app(PayoutService::class)->markPaid($payout, 'CRO999', new \DateTimeImmutable('2026-11-18'));

        $this->assertSame('paid', $r->refresh()->status);
        $types = WalletTransaction::where('withdrawal_request_id', $r->id)->pluck('amount', 'type')->map(fn ($x) => (string) $x)->all();
        $this->assertSame(['withholding_tax' => '-17.94', 'transfer_fee' => '-2.50', 'withdrawal' => '-79.56'], $types);
        $this->assertSame('788.00', (string) app(WalletService::class)->balance($a->id)); // 888 − 100
        $this->assertSame(1, CommissionReceipt::count());
        $this->assertSame('79.56', (string) CommissionReceipt::first()->net);
    }

    public function test_reserved_amounts_cannot_be_withdrawn_twice(): void
    {
        [, $a] = $this->closedOctober();
        $svc = app(WithdrawalService::class);
        $svc->request($a, '800');

        $this->expectException(WalletException::class);
        $svc->request($a, '100'); // restano 88
    }

    public function test_cancel_reject_and_payout_cancel_release_the_balance(): void
    {
        [, $a] = $this->closedOctober();
        $this->admin();
        $svc = app(WithdrawalService::class);

        $r1 = $svc->cancel($svc->request($a, '100'));
        $this->assertSame('cancelled', $r1->status);

        $r2 = $svc->reject($svc->request($a, '100'), 'IBAN da verificare');
        $this->assertSame('rejected', $r2->status);
        $this->assertSame('888.00', app(WalletService::class)->situation($a)['available']);

        $r3 = $svc->approve($svc->request($a, '100'));
        app(PayoutService::class)->cancel($r3->payout, 'Errore IBAN');
        $this->assertSame('requested', $r3->refresh()->status);
        $this->assertNull($r3->payout_id);
    }

    public function test_due_date_is_five_business_days(): void
    {
        $friday = CarbonImmutable::parse('2026-10-09 10:00', 'Europe/Rome');
        $this->assertSame('2026-10-16', WithdrawalService::dueBy($friday)->format('Y-m-d'));
        $this->assertSame('2026-10-14', WithdrawalService::dueBy(CarbonImmutable::parse('2026-10-07 09:00', 'Europe/Rome'))->format('Y-m-d'));
    }

    public function test_reopening_reverses_credits_unless_withdrawals_were_paid(): void
    {
        [$p, $a] = $this->closedOctober();
        $admin = $this->admin();
        $svc = app(WithdrawalService::class);
        $r = $svc->approve($svc->request($a, '100'));
        app(PayoutService::class)->markPaid($r->payout, 'CRO1', new \DateTimeImmutable('today'));

        try {
            app(PeriodService::class)->reopen($p, 'Prova');
            $this->fail('Con prelievi pagati la riapertura è vietata.');
        } catch (PeriodStateException $e) {
            $this->assertStringContainsString('prelievi', $e->getMessage());
        }
        $this->assertNotNull($admin);
    }

    public function test_reopening_without_withdrawals_removes_the_credits(): void
    {
        [$p, $a] = $this->closedOctober();
        $this->admin();

        app(PeriodService::class)->reopen($p, 'Correzione');

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(['open'], Settlement::query()->pluck('status')->unique()->values()->all() ?: ['open']);
        $this->assertSame('0.00', (string) app(WalletService::class)->balance($a->id));
    }

    // ── ricevute e reclami ──────────────────────────────────────────────────

    public function test_receipt_dispute_within_ten_days_then_tacit_acceptance(): void
    {
        $this->fiscalOn();
        [$p, $a] = $this->closedOctober('monthly_auto');
        $this->admin();
        app(PayoutService::class)->preparePayouts($p);
        $payout = Payout::where('consultant_id', $a->id)->firstOrFail();
        app(PayoutService::class)->markPaid($payout, 'CRO1', new \DateTimeImmutable('today'));
        $r = CommissionReceipt::firstOrFail();
        $svc = app(ReceiptService::class);

        $ticket = $svc->dispute($a->user, $r, 'La ritenuta indicata non corrisponde al 23% della quota imponibile.');
        $this->assertSame('receipt_dispute', $ticket->category);
        $this->assertSame('disputed', $r->refresh()->status);

        $svc->resolve($r, 'upheld', 'Verificato: corretta con rettifica.', User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertSame('resolved', $r->refresh()->status);

        // un'altra ricevuta non contestata decade dopo il termine
        $this->travel(11)->days();
        $this->assertSame(0, $svc->acceptDue(), 'La ricevuta già decisa non si tocca.');
    }

    public function test_receipt_cannot_be_disputed_after_deadline_or_by_others(): void
    {
        $this->fiscalOn();
        [$p, $a, $b] = $this->closedOctober('monthly_auto');
        $this->admin();
        app(PayoutService::class)->preparePayouts($p);
        app(PayoutService::class)->markPaid(Payout::where('consultant_id', $a->id)->firstOrFail(), 'CRO1', new \DateTimeImmutable('today'));
        $r = CommissionReceipt::firstOrFail();
        $svc = app(ReceiptService::class);

        try {
            $svc->dispute($b->user, $r, 'Contesto una ricevuta che non è la mia, per prova.');
            $this->fail('Solo il titolare può segnalare.');
        } catch (SupportException) {
            $this->assertTrue(true);
        }
        $this->travel(11)->days();
        $this->assertSame(1, $svc->acceptDue());
        $this->expectException(SupportException::class);
        $svc->dispute($a->user, $r->refresh(), 'Troppo tardi per segnalare questa anomalia sulla ricevuta.');
    }

    // ── area e back-office ──────────────────────────────────────────────────

    public function test_area_wallet_withdrawal_and_receipts_are_private(): void
    {
        $this->fiscalOn();
        [, $a, $b] = $this->closedOctober();

        $this->actingAs($a->user)->get('/area/wallet')->assertOk()->assertSee('888,00');
        $this->actingAs($a->user)->post('/area/wallet/prelievo', ['amount' => '100,00'])->assertRedirect();
        $w = WithdrawalRequest::firstOrFail();
        $this->assertSame('100.00', (string) $w->gross);
        $this->actingAs($a->user)->post('/area/wallet/prelievo', ['amount' => '5'])->assertSessionHasErrors('amount');

        // un altro incaricato non può annullare la mia richiesta
        $this->actingAs($b->user)->post("/area/wallet/prelievo/{$w->id}/annulla")->assertNotFound();
        $this->actingAs($a->user)->post("/area/wallet/prelievo/{$w->id}/annulla")->assertRedirect();
        $this->assertSame('cancelled', $w->refresh()->status);

        $this->actingAs(User::factory()->create())->get('/area/wallet')->assertNotFound();
    }

    public function test_area_receipt_pages(): void
    {
        $this->fiscalOn();
        [$p, $a, $b] = $this->closedOctober('monthly_auto');
        $this->admin();
        app(PayoutService::class)->preparePayouts($p);
        app(PayoutService::class)->markPaid(Payout::where('consultant_id', $a->id)->firstOrFail(), 'CRO1', new \DateTimeImmutable('today'));
        $r = CommissionReceipt::firstOrFail();

        $this->actingAs($a->user)->get('/area/ricevute')->assertOk()->assertSee($r->number);
        $this->actingAs($a->user)->get("/area/ricevute/{$r->id}")->assertOk()->assertSee('Segnala un\'anomalia', false);
        $this->actingAs($a->user)->get("/area/ricevute/{$r->id}/pdf")->assertOk();
        $this->actingAs($b->user)->get("/area/ricevute/{$r->id}")->assertNotFound();
        $this->actingAs($a->user)->post("/area/ricevute/{$r->id}/reclamo", ['text' => 'La ritenuta non corrisponde a quanto previsto dalle regole.'])->assertRedirect();
        $this->assertSame('disputed', $r->refresh()->status);
    }

    public function test_back_office_approves_a_withdrawal_with_the_permission(): void
    {
        [, $a] = $this->closedOctober();
        $r = app(WithdrawalService::class)->request($a, '100');

        $viewer = User::factory()->role(Roles::ADMIN)->create();
        $viewer->givePermissionTo('payouts.view');
        Livewire::actingAs($viewer)->test(ListWithdrawalRequests::class)->assertCanSeeTableRecords([$r])->assertTableActionHidden('approve', $r);

        $finance = User::factory()->role('admin_finance')->create();
        Livewire::actingAs($finance)->test(ListWithdrawalRequests::class)->callTableAction('approve', $r);
        $this->assertSame('approved', $r->refresh()->status);
    }

    public function test_settings_and_go_live_check_cover_the_fiscal_parameters(): void
    {
        $this->assertArrayHasKey('fiscal_withholding_pct', SystemSettings::DEFINITIONS);
        $labels = collect((new GoLiveCheck)->run())->pluck('label');
        $this->assertTrue($labels->contains('Ricevute e trattenute fiscali'));
    }
}
