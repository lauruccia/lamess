<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\EmailSignatureProvider;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\SignatureProvider;
use App\Notifications\SignatureOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class EmailSignatureTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.signature_driver' => 'email', 'lamess.mail_enabled' => true]);
        Storage::fake();
        Notification::fake();
    }

    private function contract(): array
    {
        $fan = $this->fan($this->activeSponsor());

        return [$fan, app(IvdService::class)->apply($fan->user, $this->ivdData(), null)];
    }

    private function sentCode($user): string
    {
        $codes = Notification::sent($user, SignatureOtpCode::class);
        $this->assertNotEmpty($codes);

        return $codes->last()->code;
    }

    public function test_the_code_goes_by_email_only_and_signs_once(): void
    {
        [$fan, $c] = $this->contract();
        $svc = app(IvdService::class);

        $req = $svc->requestSignature($c);
        $code = $this->sentCode($fan->user);

        $this->assertInstanceOf(EmailSignatureProvider::class, app(SignatureProvider::class));
        $this->assertSame('email', $c->refresh()->signature_provider);
        $this->assertStringNotContainsString($code, (string) $req->hint);
        $this->assertStringContainsString('***@', (string) $req->hint);

        try {
            $svc->sign($c, $code === '000000' ? '111111' : '000000');
            $this->fail('codice errato');
        } catch (OnboardingException) {
        }
        $signed = $svc->sign($c, $code, '10.0.0.1', 'UA');
        $this->assertSame('signed', $signed->status);
        $this->assertSame('email', $signed->evidence['provider']);

        $this->expectException(OnboardingException::class);
        $svc->sign($signed, $code);
    }

    public function test_five_wrong_codes_lock_the_current_code(): void
    {
        [$fan, $c] = $this->contract();
        $svc = app(IvdService::class);
        $svc->requestSignature($c);
        $code = $this->sentCode($fan->user);
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < EmailSignatureProvider::MAX_ATTEMPTS; $i++) {
            try {
                $svc->sign($c->refresh(), $wrong);
            } catch (OnboardingException) {
            }
        }

        $this->expectException(OnboardingException::class);
        $svc->sign($c->refresh(), $code);
    }

    public function test_a_new_code_cannot_be_asked_within_a_minute_and_replaces_the_old_one(): void
    {
        [$fan, $c] = $this->contract();
        $svc = app(IvdService::class);
        $svc->requestSignature($c);
        $first = $this->sentCode($fan->user);

        try {
            $svc->requestSignature($c->refresh());
            $this->fail('invio troppo ravvicinato');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('appena inviato', $e->getMessage());
        }

        $this->travel(61)->seconds();
        $svc->requestSignature($c->refresh());
        $second = $this->sentCode($fan->user);

        if ($first !== $second) {
            try {
                $svc->sign($c->refresh(), $first);
                $this->fail('il codice precedente non vale più');
            } catch (OnboardingException) {
            }
        }
        $this->assertSame('signed', $svc->sign($c->refresh(), $second)->status);
    }

    public function test_nothing_is_sent_and_it_says_so_when_email_sending_is_off(): void
    {
        config(['lamess.mail_enabled' => false]);
        [, $c] = $this->contract();

        try {
            app(IvdService::class)->requestSignature($c);
            $this->fail('invio spento');
        } catch (OnboardingException $e) {
            $this->assertStringContainsString('non è attivo', $e->getMessage());
        }
        Notification::assertNothingSent();
        $this->assertNull($c->refresh()->signature_ref);
    }

    public function test_the_page_offers_a_new_code_and_never_shows_it(): void
    {
        [$fan] = $this->contract();
        $this->actingAs($fan->user);
        config(['site.open' => true]);

        $this->post('/area/diventa-incaricato/codice')->assertRedirect('/area/diventa-incaricato');
        $code = $this->sentCode($fan->user);

        $this->get('/area/diventa-incaricato')
            ->assertOk()
            ->assertSee('Invia di nuovo il codice')
            ->assertSee('Ti abbiamo inviato un codice a')
            ->assertDontSee($code);
    }
}
