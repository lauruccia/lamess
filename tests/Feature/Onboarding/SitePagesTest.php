<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class SitePagesTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private function regData(array $over = []): array
    {
        return $over + [
            'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.test',
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga', 'privacy' => '1',
        ];
    }

    public function test_referral_link_sets_15_day_cookie_and_assigns_customer(): void
    {
        $s = $this->activeSponsor();
        $res = $this->get('/r/'.$s->referral_code)->assertRedirect('/registrazione');
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'lm_ref');
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(time() + 15 * 86400, $cookie->getExpiresTime(), 120);

        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->get('/registrazione')->assertOk()->assertSee($s->name);
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->post('/registrazione', $this->regData())->assertRedirect('/area');
        $this->assertAuthenticated();
        $this->assertSame($s->id, User::where('email', 'anna@example.test')->first()->customer->assigned_consultant_id);
    }

    public function test_unknown_referral_code_is_harmless(): void
    {
        $this->get('/r/NONESISTE')->assertRedirect('/registrazione');
        $this->get('/registrazione')->assertOk();
    }

    public function test_registration_validation_and_login_logout(): void
    {
        $this->post('/registrazione', $this->regData(['password' => 'corta', 'password_confirmation' => 'corta']))->assertSessionHasErrors('password');
        $this->post('/registrazione', $this->regData(['privacy' => null]))->assertSessionHasErrors('privacy');
        $this->post('/registrazione', $this->regData(['type' => 'company', 'company_name' => 'Acme', 'vat_number' => '1']))->assertSessionHasErrors('sdi_code');

        $this->post('/registrazione', $this->regData())->assertRedirect('/area');
        $this->post('/esci')->assertRedirect('/accedi');
        $this->post('/accedi', ['email' => 'anna@example.test', 'password' => 'sbagliata'])->assertSessionHasErrors('email');
        $this->post('/accedi', ['email' => 'anna@example.test', 'password' => 'una-password-lunga'])->assertRedirect('/area');
    }

    public function test_sponsor_from_the_referral_link_cannot_be_changed_when_applying(): void
    {
        Storage::fake();
        $s = $this->activeSponsor();
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->post('/registrazione', $this->regData())->assertRedirect('/area');

        $this->get('/area/diventa-incaricato')->assertOk()->assertSee('Il tuo sponsor')->assertSee($s->code)->assertDontSee('name="sponsor_code"', false);
        $form = ['sponsor_code' => 'ALTRO99', 'tax_code' => 'BNCNNA85C41H501Z', 'birth_date' => '1985-03-01', 'birth_place' => 'Roma', 'address' => 'Via Po 2', 'city' => 'Roma', 'zip' => '00100', 'country_code' => 'IT', 'pec' => 'anna@pec.it', 'accept' => '1'];
        $this->post('/area/diventa-incaricato', $form)->assertRedirect('/area/diventa-incaricato');

        $this->assertSame($s->id, IvdContract::firstOrFail()->sponsor_id);
    }

    public function test_area_requires_login(): void
    {
        $this->get('/area')->assertRedirect('/accedi');
    }

    public function test_full_consultant_journey_through_the_site(): void
    {
        Storage::fake();
        $s = $this->activeSponsor();
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->post('/registrazione', $this->regData())->assertRedirect('/area');

        $this->get('/area')->assertOk()->assertSee('Invia la richiesta');
        $form = ['tax_code' => 'BNCNNA85C41H501Z', 'birth_date' => '1985-03-01', 'birth_place' => 'Roma', 'address' => 'Via Po 2', 'city' => 'Roma', 'zip' => '00100', 'accept' => '1'];
        $this->post('/area/diventa-incaricato', $form)->assertRedirect('/area/diventa-incaricato');
        $page = $this->get('/area/diventa-incaricato')->assertOk()->assertSee('OTP');
        preg_match('/note-info"><div>[^<]*?(\d{6})/', $page->getContent(), $m); // il codice è nel riquadro informativo, non nei numeri di versione dei file CSS/JS
        $this->assertNotEmpty($m, 'Il codice OTP di prova deve comparire nel riquadro informativo');

        $this->post('/area/diventa-incaricato/firma', ['otp' => '999999'])->assertSessionHasErrors('otp');
        $this->post('/area/diventa-incaricato/firma', ['otp' => $m[1]])->assertRedirect('/area');
        $this->get('/area')->assertSee('in attesa di accettazione')->assertSee('Onboarding da completare');

        $contract = IvdContract::firstOrFail();
        $this->get('/area/file/lettera')->assertOk();
        $this->get('/area/file/tesserino')->assertNotFound(); // non ancora accettata

        $this->post('/area/onboarding/documento', ['type' => 'photo', 'file' => UploadedFile::fake()->image('f.jpg')])->assertSessionHasNoErrors();
        $this->post('/area/onboarding/documento', ['type' => 'photo', 'file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->post('/area/onboarding/iban', ['iban' => 'IT00', 'iban_holder' => 'Anna'])->assertSessionHasErrors('iban');
        $this->post('/area/onboarding/iban', ['iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'Anna Bianchi'])->assertSessionHasNoErrors();
        $this->get('/area/onboarding')->assertOk()->assertSee('in verifica');

        $this->assertSame($s->id, Consultant::where('user_id', $contract->user_id)->first()->sponsor_id);
    }

    public function test_files_are_private_to_their_owner(): void
    {
        Storage::fake();
        $fan = $this->fan($this->activeSponsor());
        $this->signedContract($fan);
        $other = User::factory()->create();
        $this->actingAs($other)->get('/area/file/lettera')->assertNotFound();
        $this->actingAs($fan->user)->get('/area/file/lettera')->assertOk();
    }
}
