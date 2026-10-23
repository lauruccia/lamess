<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Network\NetworkService;
use App\Domain\Onboarding\FeeService;
use App\Domain\Onboarding\Iban;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Models\AnnualFee;
use App\Models\Customer;
use App\Models\IvdContract;
use App\Models\Profile;
use App\Notifications\ContractTerminated;
use App\Notifications\FeeReminder;
use App\Notifications\KycReviewed;
use App\Notifications\OnboardingCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class KycAndFeesTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function acceptedConsultant(): IvdContract
    {
        $fan = $this->fan($this->activeSponsor());

        return app(IvdService::class)->accept($this->signedContract($fan), 'admin');
    }

    public function test_iban_validation(): void
    {
        $this->assertTrue(Iban::isValid('IT60 X054 2811 1010 0000 0123 456'));
        $this->assertFalse(Iban::isValid('IT60X0542811101000000123457'));
        $this->assertFalse(Iban::isValid('IT60X05428111010000001234'));
        $this->assertFalse(Iban::isValid('non un iban'));
    }

    public function test_onboarding_completes_only_with_all_requirements(): void
    {
        Notification::fake();
        $c = $this->acceptedConsultant()->consultant;
        $kyc = app(KycService::class);
        $ob = app(OnboardingService::class);

        $this->assertContains('IBAN e intestatario', $ob->missing($c));
        $photo = $kyc->upload($c, 'photo', UploadedFile::fake()->image('foto.jpg'));
        $id = $kyc->upload($c, 'id_document', UploadedFile::fake()->create('ci.pdf', 100, 'application/pdf'));
        $this->assertSame('submitted', $c->refresh()->kyc_status);

        $kyc->review($photo, true, 'admin');
        $kyc->review($id, true, 'admin');
        $this->assertSame('verified', $c->refresh()->kyc_status);
        $this->assertFalse($c->onboarding_complete); // manca l'IBAN

        $ob->saveBankDetails($c, 'IT60X0542811101000000123456', 'Mario Rossi');
        $c->refresh();
        $this->assertTrue($c->onboarding_complete);
        $this->assertNotNull($c->onboarding_completed_at);
        Notification::assertSentTo($c->user, OnboardingCompleted::class);
        Notification::assertSentTo($c->user, KycReviewed::class);
        Storage::assertExists($c->badge_path); // tesserino rigenerato con la foto approvata
    }

    public function test_rejected_document_blocks_and_can_be_replaced(): void
    {
        $c = $this->acceptedConsultant()->consultant;
        $kyc = app(KycService::class);
        $kyc->upload($c, 'photo', UploadedFile::fake()->image('foto.jpg'));
        $id = $kyc->upload($c, 'id_document', UploadedFile::fake()->image('ci.jpg'));
        $kyc->review($id, false, 'admin', 'illeggibile');
        $this->assertSame('rejected', $c->refresh()->kyc_status);
        $this->assertTrue(collect(app(OnboardingService::class)->missing($c))->contains(fn ($m) => str_contains($m, 'rifiutato')));

        $new = $kyc->upload($c, 'id_document', UploadedFile::fake()->image('ci2.jpg'));
        $this->assertSame('submitted', $c->refresh()->kyc_status); // l'ultimo documento sostituisce quello rifiutato
        $this->expectException(OnboardingException::class);
        $kyc->review($id, true, 'admin'); // già esaminato
    }

    public function test_invalid_document_type_and_bad_iban_are_rejected(): void
    {
        $c = $this->acceptedConsultant()->consultant;
        try {
            app(KycService::class)->upload($c, 'passaporto_alieno', UploadedFile::fake()->image('x.jpg'));
            $this->fail();
        } catch (OnboardingException) {
        }
        $this->expectException(OnboardingException::class);
        app(OnboardingService::class)->saveBankDetails($c, 'IT00', 'X');
    }

    public function test_first_year_is_free_and_renews_silently(): void
    {
        $c = $this->acceptedConsultant();
        $svc = app(FeeService::class);
        $this->assertSame(['renewed' => 0, 'fees_created' => 0, 'reminders' => 0, 'terminated' => 0], $svc->process($c->starts_at->modify('+100 days')));
        // alla scadenza del primo anno serve il canone del 2° anno: viene creato 30 giorni prima
        $r = $svc->process($c->expires_at->modify('-29 days'));
        $this->assertSame(1, $r['fees_created']);
        $fee = AnnualFee::firstOrFail();
        $this->assertSame(2, $fee->year_index);
        $this->assertSame('19.00', $fee->amount);
    }

    public function test_annual_fee_can_be_switched_off(): void
    {
        config(['lamess.annual_fee_enabled' => false]);
        $c = $this->acceptedConsultant();
        $svc = app(FeeService::class);
        $r = $svc->process($c->expires_at->modify('-29 days'));
        $this->assertSame(0, $r['fees_created'], 'nessun canone con l\'interruttore spento');
        $this->assertSame(0, AnnualFee::count());
        $this->assertStringContainsString('senza alcun canone annuale', (string) view('pdf.ivd-letter', [
            'contract' => $c, 'profile' => $c->user->profile ?? new Profile(['first_name' => 'A', 'last_name' => 'B']), 'consultant' => $c->consultant, 'sponsor' => null,
            'signedAt' => new \DateTimeImmutable, 'feeAmount' => '19.00', 'firstPaidYear' => 2,
        ])->render());
    }

    public function test_fee_text_says_vat_included(): void
    {
        $c = $this->acceptedConsultant();
        $html = (string) view('pdf.ivd-letter', [
            'contract' => $c, 'profile' => $c->user->profile ?? new Profile(['first_name' => 'A', 'last_name' => 'B']), 'consultant' => $c->consultant, 'sponsor' => null,
            'signedAt' => new \DateTimeImmutable, 'feeAmount' => '19.00', 'firstPaidYear' => 2,
        ])->render();
        $this->assertStringContainsString('Euro 19,00 (IVA compresa)', $html);
    }

    public function test_reminders_sent_once_per_threshold_then_paid_renews(): void
    {
        Notification::fake();
        $c = $this->acceptedConsultant();
        $svc = app(FeeService::class);
        $exp = $c->expires_at;

        $this->assertSame(1, $svc->process($exp->modify('-30 days'))['reminders']);
        $this->assertSame(0, $svc->process($exp->modify('-29 days'))['reminders']);   // stessa soglia
        $this->assertSame(1, $svc->process($exp->modify('-7 days'))['reminders']);
        $this->assertSame(1, $svc->process($exp->modify('-1 days'))['reminders']);
        $this->assertSame(0, $svc->process($exp->modify('-12 hours'))['reminders']);
        Notification::assertSentToTimes($c->user, FeeReminder::class, 3);

        $svc->markPaid(AnnualFee::firstOrFail(), 'transfer');
        $r = $svc->process($exp->modify('+1 minute'));
        $this->assertSame(1, $r['renewed']);
        $c->refresh();
        $this->assertSame(2, $c->term_index);
        $this->assertEqualsWithDelta(730, $c->starts_at->diffInDays($c->expires_at), 1);
        $this->assertSame('accepted', $c->status);
    }

    public function test_unpaid_fee_terminates_letter_relinks_downline_and_returns_to_customer(): void
    {
        Notification::fake();
        $sponsor = $this->activeSponsor('LM00009');
        $fan = $this->fan($sponsor);
        $c = app(IvdService::class)->accept($this->signedContract($fan, $sponsor), 'admin');
        $child = app(NetworkService::class)->enroll(['code' => 'K1', 'name' => 'Figlio'], $c->consultant, $this->at('2026-10-05 10:00'));

        $r = app(FeeService::class)->process($c->expires_at->modify('+1 minute'));

        $this->assertSame(1, $r['terminated']);
        $this->assertSame('expired', $c->refresh()->status);
        $this->assertSame('terminated', $c->consultant->refresh()->status);
        $this->assertSame($sponsor->id, $child->refresh()->sponsor_id);
        $this->assertSame('void', AnnualFee::firstOrFail()->status);
        $this->assertSame($sponsor->id, Customer::where('user_id', $fan->user_id)->value('assigned_consultant_id'));
        Notification::assertSentTo($fan->user, ContractTerminated::class);
        // idempotente
        $this->assertSame(0, app(FeeService::class)->process($c->expires_at->modify('+2 days'))['terminated']);
    }

    public function test_grace_period_is_configurable(): void
    {
        config(['lamess.annual_fee_grace_days' => 10]);
        $c = $this->acceptedConsultant();
        $svc = app(FeeService::class);
        $svc->process($c->expires_at->modify('-5 days'));
        $this->assertSame(0, $svc->process($c->expires_at->modify('+5 days'))['terminated']);
        $this->assertSame(1, $svc->process($c->expires_at->modify('+11 days'))['terminated']);
    }

    public function test_cannot_mark_paid_twice(): void
    {
        $c = $this->acceptedConsultant();
        $svc = app(FeeService::class);
        $svc->process($c->expires_at->modify('-10 days'));
        $fee = AnnualFee::firstOrFail();
        $svc->markPaid($fee);
        $this->expectException(OnboardingException::class);
        $svc->markPaid($fee->refresh());
    }
}
