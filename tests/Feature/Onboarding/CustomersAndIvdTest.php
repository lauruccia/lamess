<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\ReferralResolver;
use App\Domain\Network\NetworkService;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\PvMovement;
use App\Notifications\ApplicationRejected;
use App\Notifications\WelcomeConsultant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class CustomersAndIvdTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    public function test_referral_resolution_order_and_default(): void
    {
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');
        $house = $this->activeSponsor('LM00003');
        config(['lamess.default_consultant_code' => 'LM00003']);
        $r = app(ReferralResolver::class);

        $this->assertSame([$a->id, 'referral'], [$r->resolve('refLM00001', 'REFLM00002', 'LM00002')[0]->id, $r->resolve('refLM00001', 'REFLM00002', 'LM00002')[1]]);
        $this->assertSame([$b->id, 'cookie'], [$r->resolve(null, 'REFLM00002', 'LM00001')[0]->id, $r->resolve(null, 'REFLM00002', 'LM00001')[1]]);
        $this->assertSame('form_code', $r->resolve(null, null, 'lm00001')[1]);
        $this->assertSame([$house->id, 'default'], [$r->resolve('xx', 'yy', 'zz')[0]->id, $r->resolve('xx', 'yy', 'zz')[1]]);

        app(NetworkService::class)->terminate($a, 'x');
        $this->assertSame('cookie', $r->resolve('REFLM00001', 'REFLM00002', null)[1]); // sponsor cessato: ignorato
        config(['lamess.default_consultant_code' => null]);
        $this->assertSame([null, 'manual'], $r->resolve(null, null, null));
    }

    public function test_customer_registration_private_and_company(): void
    {
        $s = $this->activeSponsor();
        $fan = $this->fan($s);
        $this->assertSame($s->id, $fan->assigned_consultant_id);
        $this->assertSame('form_code', $fan->source);
        $this->assertSame('private', $fan->user->profile->type);

        $co = app(CustomerService::class)->register([
            'email' => 'azienda@example.test', 'password' => 'password-123', 'type' => 'company', 'company_name' => 'Acme Srl',
            'vat_number' => '12345678901', 'pec' => 'acme@pec.it',
        ], link: 'REFLM00001');
        $this->assertSame('referral', $co->source);

        $this->expectException(InvalidArgumentException::class);
        app(CustomerService::class)->register(['email' => 'x@example.test', 'password' => 'password-123', 'type' => 'company', 'company_name' => 'Senza PIVA']);
    }

    public function test_application_validations(): void
    {
        $s = $this->activeSponsor();
        $svc = app(IvdService::class);

        $company = app(CustomerService::class)->register(['email' => 'c@example.test', 'password' => 'password-123', 'type' => 'company', 'company_name' => 'Acme', 'vat_number' => '1', 'pec' => 'a@pec.it']);
        try {
            $svc->apply($company->user, $this->ivdData(), $s);
            $this->fail('azienda non ammessa');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('persone fisiche', $e->getMessage());
        }

        $minor = $this->fan($s);
        try {
            $svc->apply($minor->user, $this->ivdData(['birth_date' => now()->subYears(17)->format('Y-m-d')]), $s);
            $this->fail('minorenne non ammesso');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('maggiorenne', $e->getMessage());
        }

        $incomplete = $this->fan($s);
        $this->expectException(OnboardingException::class);
        $svc->apply($incomplete->user, ['tax_code' => 'ABC'], $s);
    }

    public function test_signing_requires_valid_otp_and_creates_consultant_immediately(): void
    {
        Storage::fake();
        $s = $this->activeSponsor();
        $fan = $this->fan($s);
        $svc = app(IvdService::class);
        $c = $svc->apply($fan->user, $this->ivdData(), null); // sponsor proposto = quello assegnato
        $this->assertSame($s->id, $c->sponsor_id);
        $req = $svc->requestSignature($c);

        try {
            $svc->sign($c, '000000x');
            $this->fail('OTP errato');
        } catch (OnboardingException) {
        }
        $this->assertSame(0, Consultant::where('user_id', $fan->user_id)->count());

        $c = $svc->sign($c, $this->otpOf((string) $req->hint), '10.0.0.1', 'UA');
        $consultant = $c->consultant;
        $this->assertSame('signed', $c->status);
        $this->assertSame('active', $consultant->status);
        $this->assertSame($s->id, $consultant->sponsor_id);
        $this->assertSame('RSSMRA80A01H501U', $consultant->person_key);
        $this->assertMatchesRegularExpression('/^LM\d{5}$/', $consultant->code);
        $this->assertNotEmpty($consultant->referral_code);
        $this->assertFalse($consultant->onboarding_complete);
        Storage::assertExists($consultant->refresh()->badge_path); // il tesserino nasce alla firma, senza aspettare l'accettazione
        $this->assertSame($consultant->code, $consultant->badge_number);
        $this->assertNotNull($consultant->badge_valid_until);
        Storage::assertExists($c->pdf_path);
        $this->assertSame(hash('sha256', Storage::get($c->pdf_path)), $c->pdf_hash);
        $this->assertSame('10.0.0.1', $c->evidence['ip']);
        $this->assertEqualsWithDelta(365, $c->starts_at->diffInDays($c->expires_at), 1);
        // l'OTP non è riutilizzabile
        $this->expectException(OnboardingException::class);
        $svc->sign($c, $this->otpOf((string) $req->hint));
    }

    public function test_accept_generates_badge_and_sends_welcome(): void
    {
        Notification::fake();
        $fan = $this->fan($this->activeSponsor());
        $c = $this->signedContract($fan);
        $c = app(IvdService::class)->accept($c, 'admin@test');

        $this->assertSame('accepted', $c->status);
        Storage::assertExists($c->consultant->refresh()->badge_path);
        $this->assertSame($c->consultant->code, $c->consultant->badge_number);
        Notification::assertSentTo($fan->user, WelcomeConsultant::class);
    }

    public function test_reject_returns_to_customer_and_moves_points_to_sponsor(): void
    {
        Notification::fake();
        $s = $this->activeSponsor();
        $fan = $this->fan($s);
        $period = $this->openPeriod('2026-10');
        $c = $this->signedContract($fan, $s);
        $m = $this->sale($period, $c->consultant, '100', kind: 'pvc');

        app(IvdService::class)->reject($c, 'admin@test', 'documentazione insufficiente');

        $this->assertSame('rejected', $c->refresh()->status);
        $this->assertSame('terminated', $c->consultant->refresh()->status);
        $m->refresh();
        $this->assertSame([$s->id, 'pvf'], [$m->consultant_id, $m->kind]);
        $this->assertSame($s->id, Customer::where('user_id', $fan->user_id)->value('assigned_consultant_id'));
        Notification::assertSentTo($fan->user, ApplicationRejected::class);
        $this->assertSame(1, PvMovement::count());
    }

    public function test_reenrollment_blocked_after_termination(): void
    {
        $s = $this->activeSponsor();
        $fan = $this->fan($s);
        $c = $this->signedContract($fan, $s);
        app(IvdService::class)->reject($c, 'a');
        $this->expectException(OnboardingException::class);
        $this->expectExceptionMessage('180 giorni');
        app(IvdService::class)->apply($fan->user, $this->ivdData(), $s);
    }
}
