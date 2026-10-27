<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Domain\Onboarding\Iban;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Payouts\PayoutException;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\SepaExport;
use App\Domain\Periods\PeriodService;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Periods\Pages\ViewPeriod;
use App\Filament\Widgets\SchedulerStatus;
use App\Models\AuditEntry;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Settlement;
use App\Models\User;
use App\Notifications\OrderPaid;
use App\Notifications\PayoutPaid;
use App\Notifications\StatementReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class SepaAndNoticesTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private const DEBTOR = ['name' => 'Società Lamèss Srl', 'iban' => 'IT60X0542811101000000123456', 'bic' => ''];

    private function superAdmin(): User
    {
        $u = User::factory()->role('super_admin')->create();
        $this->actingAs($u);

        return $u;
    }

    /** Un incaricato pagabile con utente collegato. */
    private function payable(string $code, ?Consultant $sponsor = null, ?string $rank = null): Consultant
    {
        $c = $this->consultant($code, $sponsor, $rank);
        $user = User::factory()->create();
        $c->update(['user_id' => $user->id, 'kyc_status' => 'verified', 'iban' => 'IT60 X054 2811 1010 0000 0123 456', 'iban_holder' => "Mario Rossi è L'Ottimo {$code}"]);

        return $c->refresh();
    }

    /** @return array{0: Period, 1: Consultant, 2: Consultant} */
    private function closedOctober(): array
    {
        $plan = $this->plan();
        $p = $this->openPeriod('2026-10', $plan);
        $a = $this->payable('A', null, 'DIAMOND');
        $b = $this->payable('B', $a, 'CRYSTAL');
        $this->sale($p, $b, '8000');
        app(PeriodService::class)->calculate($p, 'official');

        return [$p, $a, $b];
    }

    public function test_sepa_file_contains_valid_payouts_and_skips_bad_iban(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        $b->update(['iban' => 'IT00INVALID']);
        Payout::where('consultant_id', $b->id)->update(['iban' => 'IT00INVALID']);

        $r = (new SepaExport)->build(Payout::with('consultant', 'period')->where('status', Payout::TO_PAY)->get(), self::DEBTOR, now());

        $this->assertSame(1, $r['count']);
        $this->assertCount(1, $r['skipped']);
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($r['xml']));
        $this->assertStringContainsString('pain.001.001.03', $r['xml']);
        $this->assertStringContainsString('<IBAN>IT60X0542811101000000123456</IBAN>', $r['xml']);
        $this->assertStringContainsString((string) $r['total'], $r['xml']);
        $this->assertStringContainsString('Mario Rossi e L\'Ottimo A', $r['xml'], 'nome ripulito dai caratteri non SEPA');
    }

    public function test_payouts_with_an_iban_outside_sepa_stay_out_of_the_file_and_are_listed_apart(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        // La Tunisia non è nell'area SEPA: IBAN valido, ma il pagamento non può stare nel file.
        Payout::where('consultant_id', $b->id)->update(['iban' => 'TN5910006035183598478831', 'bic' => 'ATBKTNTT']);

        $payouts = Payout::with('consultant', 'period')->where('status', Payout::TO_PAY)->get();
        $this->assertGreaterThan(1, $payouts->count());
        $r = (new SepaExport)->build($payouts, self::DEBTOR, now());

        $this->assertSame([], $r['skipped']);
        $this->assertCount(1, $r['outside']);
        $this->assertStringContainsString('TN', $r['outside'][0]);
        $this->assertStringNotContainsString('TN5910006035183598478831', $r['xml']);
        $this->assertSame(Payout::where('consultant_id', $b->id)->pluck('id')->map(fn ($i) => (int) $i)->all(), $r['outside_ids']);
        $this->assertNotContains($r['outside_ids'][0], $r['ids']);
        $this->assertSame($payouts->count() - 1, $r['count']);
    }

    public function test_sepa_action_marks_only_sepa_payouts_as_exported_and_warns_about_the_others(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        Payout::where('consultant_id', $b->id)->update(['iban' => 'TN5910006035183598478831', 'bic' => 'ATBKTNTT']);
        config(['lamess.sepa.debtor_name' => 'Lamess', 'lamess.sepa.debtor_iban' => 'IT60X0542811101000000123456']);

        $this->superAdmin();
        Livewire::test(ListPayouts::class)->callAction('sepa')->assertFileDownloaded()->assertNotified();
        $this->assertNull(Payout::where('consultant_id', $b->id)->first()->exported_at, 'il pagamento fuori SEPA non risulta esportato');
        $this->assertNotNull(Payout::where('consultant_id', $a->id)->first()->exported_at);
        $this->assertSame(Payout::TO_PAY, Payout::where('consultant_id', $b->id)->first()->status);
    }

    public function test_the_outside_sepa_csv_lists_country_bic_and_amount(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        Payout::where('consultant_id', $b->id)->update(['iban' => 'TN5910006035183598478831', 'bic' => 'ATBKTNTT']);

        $this->superAdmin();
        $res = $this->get('/admin-files/export/payouts-fuori-sepa')->assertOk();
        $csv = $res->getContent();
        $this->assertStringContainsString('TN5910006035183598478831;TN;ATBKTNTT', $csv);
        $this->assertStringNotContainsString('IT60X0542811101000000123456', $csv);
    }

    public function test_iban_and_bic_checks(): void
    {
        $this->assertTrue(Iban::isValid('TN59 1000 6035 1835 9847 8831'));
        $this->assertFalse(Iban::isValid('TN59 1000 6035 1835 9847 883'), 'lunghezza tunisina sbagliata');
        $this->assertTrue(Iban::isSepa('IT60X0542811101000000123456'));
        $this->assertFalse(Iban::isSepa('TN5910006035183598478831'));
        $this->assertTrue(Iban::isValidBic('ATBKTNTT'));
        $this->assertTrue(Iban::isValidBic('bcit itmm 123'));
        $this->assertFalse(Iban::isValidBic('BIAT'));
    }

    public function test_an_iban_outside_sepa_needs_a_bic_when_saved(): void
    {
        $c = $this->payable('Z');
        $svc = app(OnboardingService::class);

        try {
            $svc->saveBankDetails($c, 'TN59 1000 6035 1835 9847 8831', 'Ali Ben Salah');
            $this->fail('Doveva chiedere il BIC.');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('BIC', $e->getMessage());
        }
        $this->assertSame('IT60X0542811101000000123456', $c->fresh()->iban, 'nulla è stato salvato');

        $svc->saveBankDetails($c, 'TN59 1000 6035 1835 9847 8831', 'Ali Ben Salah', 'atbk tntt');
        $this->assertSame('TN5910006035183598478831', $c->fresh()->iban);
        $this->assertSame('ATBKTNTT', $c->fresh()->bic);

        $this->expectException(OnboardingException::class);
        $svc->saveBic($c, '');
    }

    public function test_payout_preparation_copies_the_bic_and_blocks_a_missing_one_for_non_sepa_iban(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        $b->update(['iban' => 'TN5910006035183598478831', 'bic' => null]);
        app(PeriodService::class)->close($p);
        $r = app(PayoutService::class)->preparePayouts($p->refresh());
        $this->assertSame(0, Payout::where('consultant_id', $b->id)->count(), 'senza BIC il pagamento non nasce');
        $this->assertGreaterThanOrEqual(1, $r['blocked']);

        $b->update(['bic' => 'ATBKTNTT']);
        app(PayoutService::class)->preparePayouts($p->refresh());
        $this->assertSame('ATBKTNTT', Payout::where('consultant_id', $b->id)->first()->bic);
    }

    public function test_sepa_requires_debtor_account_and_refuses_empty_selection(): void
    {
        $this->expectException(PayoutException::class);
        (new SepaExport)->build([], ['name' => '', 'iban' => ''], now());
    }

    public function test_iban_checksum(): void
    {
        $this->assertTrue(SepaExport::validIban('IT60X0542811101000000123456'));
        $this->assertFalse(SepaExport::validIban('IT61X0542811101000000123456'));
    }

    public function test_sepa_action_downloads_and_marks_exported_with_audit(): void
    {
        [$p] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        config(['lamess.sepa.debtor_name' => 'Lamess', 'lamess.sepa.debtor_iban' => 'IT60X0542811101000000123456']);

        $this->superAdmin();
        Livewire::test(ListPayouts::class)->callAction('sepa')->assertFileDownloaded();
        $this->assertSame(0, Payout::whereNull('exported_at')->count());
        $this->assertTrue(AuditEntry::where('action', 'export.sepa')->exists());
    }

    public function test_sepa_action_without_debtor_account_explains_and_downloads_nothing(): void
    {
        [$p] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        config(['lamess.sepa.debtor_name' => '', 'lamess.sepa.debtor_iban' => '']);

        $this->superAdmin();
        Livewire::test(ListPayouts::class)->callAction('sepa')->assertNotified('File SEPA non creato');
        $this->assertSame(0, Payout::whereNotNull('exported_at')->count());
    }

    public function test_statement_notice_is_silent_when_mail_is_off(): void
    {
        [$p] = $this->closedOctober();
        $customer = User::factory()->create();
        Notification::fake();

        config(['lamess.mail_enabled' => false]);
        app(PeriodService::class)->close($p);
        Notification::assertNothingSent();
        $this->assertNotNull($customer->id);
    }

    public function test_statement_notice_when_enabled_reaches_consultants_not_customers(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        $customer = User::factory()->create();
        Notification::fake();
        config(['lamess.mail_enabled' => true, 'lamess.notify_statement_ready' => true]);

        app(PeriodService::class)->close($p);

        Notification::assertSentTo($a->user, StatementReady::class);
        Notification::assertSentTo($b->user, StatementReady::class);
        Notification::assertNotSentTo($customer, StatementReady::class);
        Notification::assertNotSentTo($customer, OrderPaid::class);
    }

    public function test_statement_notice_flag_off_sends_nothing(): void
    {
        [$p] = $this->closedOctober();
        Notification::fake();
        config(['lamess.mail_enabled' => true, 'lamess.notify_statement_ready' => false]);
        app(PeriodService::class)->close($p);
        Notification::assertNothingSent();
    }

    public function test_paid_notice_respects_mail_switch_and_flag(): void
    {
        [$p, $a, $b] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        app(PayoutService::class)->preparePayouts($p->refresh());
        $payouts = Payout::where('status', Payout::TO_PAY)->orderBy('id')->get();
        Notification::fake();

        config(['lamess.mail_enabled' => false]);
        app(PayoutService::class)->markPaid($payouts[0], 'CRO1', now());
        Notification::assertNothingSent();

        config(['lamess.mail_enabled' => true, 'lamess.notify_payout_paid' => true]);
        app(PayoutService::class)->markPaid($payouts[1], 'CRO2', now());
        Notification::assertSentTo($payouts[1]->consultant->user, PayoutPaid::class);
    }

    public function test_a_mail_failure_never_blocks_the_close(): void
    {
        [$p] = $this->closedOctober();
        config(['lamess.mail_enabled' => true]);
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp giù'));

        $this->assertSame(Period::CLOSED, app(PeriodService::class)->close($p)->status);
    }

    public function test_global_block_stops_any_outgoing_message_when_mail_is_off(): void
    {
        if (config('lamess.mail_enabled')) {
            $this->markTestSkipped('Il blocco si applica solo all\'avvio con invio spento.');
        }
        config(['logging.channels.t' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'logging.default' => 't']);
        Log::forgetChannel('t');
        Mail::raw('prova', fn ($m) => $m->to('cliente@example.com')->subject('prova'));
        $handler = Log::channel('t')->getLogger()->getHandlers()[0];
        $this->assertTrue($handler->hasWarningThatContains('EMAIL BLOCCATA'));
    }

    public function test_historical_button_disappears_after_use_and_returns_if_a_settlement_reopens(): void
    {
        [$p] = $this->closedOctober();
        app(PeriodService::class)->close($p);
        Settlement::query()->delete();
        $this->superAdmin();

        Livewire::test(ViewPeriod::class, ['record' => $p->getKey()])->assertActionVisible('historical')->callAction('historical');
        Livewire::test(ViewPeriod::class, ['record' => $p->getKey()])->assertActionHidden('historical')->assertActionHidden('settle');

        Settlement::where('period_id', $p->id)->limit(1)->update(['status' => 'open']);
        Livewire::test(ViewPeriod::class, ['record' => $p->getKey()])->assertActionVisible('historical');
    }

    public function test_dashboard_warns_while_seller_name_is_still_the_placeholder(): void
    {
        $this->superAdmin();
        config(['lamess.seller.name' => 'Lamèss — ragione sociale da configurare']);
        Livewire::test(SchedulerStatus::class)->assertSee('Dati del venditore');
        config(['lamess.seller.name' => 'Mario Rossi']);
        Livewire::test(SchedulerStatus::class)->assertDontSee('Dati del venditore');
    }
}
