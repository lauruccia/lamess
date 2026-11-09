<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** Registrazione solo su invito: senza link referral (o invito) valido non si vede il modulo e non si crea nessun account. */
final class InviteOnlyRegistrationTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.registration_invite_only' => true]);
    }

    private function regData(array $over = []): array
    {
        return $over + [
            'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.test',
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga', 'privacy' => '1',
        ];
    }

    public function test_without_an_invite_the_form_and_the_post_are_closed(): void
    {
        $this->get('/registrazione')->assertForbidden()->assertSee('Registrazione solo su invito')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('/registrazione', $this->regData())->assertForbidden();

        $this->assertNull(User::where('email', 'anna@example.test')->first());
        $this->assertGuest();
    }

    public function test_the_referral_link_opens_the_registration_and_assigns_the_sponsor(): void
    {
        $s = $this->activeSponsor();
        $this->get('/r/'.$s->referral_code)->assertRedirect('/registrazione');

        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->get('/registrazione')->assertOk()->assertSee($s->name);
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->post('/registrazione', $this->regData())->assertRedirect('/area');

        $this->assertAuthenticated();
        $this->assertSame($s->id, User::where('email', 'anna@example.test')->first()->customer->assigned_consultant_id);
    }

    public function test_the_ref_parameter_works_as_a_link(): void
    {
        $s = $this->activeSponsor();

        $this->get('/registrazione?ref='.$s->referral_code)->assertOk()->assertSee($s->name);
        $this->post('/registrazione', $this->regData(['ref' => $s->referral_code]))->assertRedirect('/area');
    }

    public function test_the_activity_code_cannot_be_used_as_an_invite(): void
    {
        $s = $this->activeSponsor();

        // il codice attività (LM00001) è consecutivo e quindi indovinabile: non vale come invito
        $res = $this->get('/r/'.$s->code)->assertRedirect('/registrazione');
        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'lm_ref'));
        $this->get('/registrazione?ref='.$s->code)->assertForbidden();
        $this->withUnencryptedCookie('lm_ref', $s->code)->post('/registrazione', $this->regData())->assertForbidden();
        $this->assertNull(User::where('email', 'anna@example.test')->first());
    }

    public function test_a_sponsor_typed_by_hand_is_ignored(): void
    {
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');

        $this->withUnencryptedCookie('lm_ref', $a->referral_code)->post('/registrazione', $this->regData(['sponsor_code' => $b->referral_code]))->assertRedirect('/area');

        $this->assertSame($a->id, User::where('email', 'anna@example.test')->first()->customer->assigned_consultant_id);
    }

    public function test_an_unknown_link_does_not_open_the_registration(): void
    {
        $this->get('/r/NONESISTE')->assertRedirect('/registrazione');
        $this->withUnencryptedCookie('lm_ref', 'NONESISTE')->get('/registrazione')->assertForbidden();
    }

    public function test_switching_the_setting_off_opens_the_registration_to_everyone(): void
    {
        config(['lamess.registration_invite_only' => false]);

        $this->get('/registrazione')->assertOk();
        $this->post('/registrazione', $this->regData())->assertRedirect('/area');
    }

    public function test_public_pages_do_not_offer_registration_without_an_invite(): void
    {
        $this->get('/')->assertOk()->assertDontSee('href="/registrazione"', false);
        $this->get('/accedi')->assertOk()->assertDontSee('href="/registrazione"', false)->assertSee('riservata a chi riceve un invito');

        $s = $this->activeSponsor();
        $this->withUnencryptedCookie('lm_ref', $s->referral_code)->get('/accedi')->assertSee('href="/registrazione"', false);
    }
}
