<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Customers\ApplicationService;
use App\Models\Customer;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Notifications\MembershipApplicationApproved;
use App\Notifications\MembershipApplicationRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

/** «Candidati»: richiesta pubblica, valutazione dell'amministrazione e registrazione dal link personale. */
final class MembershipApplicationTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.registration_invite_only' => true, 'lamess.mail_enabled' => true]);
    }

    private function formData(array $over = []): array
    {
        return $over + [
            'first_name' => 'Luca', 'last_name' => 'Verdi', 'email' => 'Luca@Example.test', 'phone' => '3331234567', 'city' => 'Roma',
            'interest' => 'consultant', 'privacy' => '1',
        ];
    }

    private function application(array $over = []): MembershipApplication
    {
        return app(ApplicationService::class)->submit($this->formData($over));
    }

    private function regData(MembershipApplication $a, array $over = []): array
    {
        return $over + [
            'candidatura' => $a->token, 'type' => 'private', 'first_name' => 'Luca', 'last_name' => 'Verdi', 'email' => $a->email,
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga', 'privacy' => '1',
        ];
    }

    public function test_the_page_is_public_and_a_request_is_stored_without_creating_an_account(): void
    {
        $this->get('/candidati')->assertOk()->assertSee('Candidati');
        $this->post('/candidati', $this->formData())->assertRedirect('/candidati/grazie');
        $this->get('/candidati/grazie')->assertOk()->assertSee('riceverai una risposta via email');

        $a = MembershipApplication::firstOrFail();
        $this->assertSame('pending', $a->status);
        $this->assertSame('luca@example.test', $a->email);
        $this->assertSame('consultant', $a->interest);
        $this->assertNull(User::where('email', 'luca@example.test')->first());
    }

    public function test_required_fields_privacy_and_interest(): void
    {
        $this->post('/candidati', ['first_name' => 'Luca'])->assertSessionHasErrors(['last_name', 'email', 'phone', 'city', 'interest', 'privacy']);
        $this->post('/candidati', $this->formData(['privacy' => null]))->assertSessionHasErrors('privacy');
        $this->post('/candidati', $this->formData(['interest' => 'altro']))->assertSessionHasErrors('interest');
        $this->assertSame(0, MembershipApplication::count());
    }

    public function test_honeypot_and_duplicate_open_request_store_nothing_new(): void
    {
        $this->post('/candidati', $this->formData(['website' => 'http://spam']))->assertRedirect('/candidati/grazie');
        $this->assertSame(0, MembershipApplication::count());

        $this->post('/candidati', $this->formData());
        $this->post('/candidati', $this->formData())->assertRedirect('/candidati/grazie');
        $this->assertSame(1, MembershipApplication::count());
    }

    public function test_closed_registration_page_offers_the_apply_link(): void
    {
        $this->get('/registrazione')->assertForbidden()->assertSee('/candidati', false);
    }

    public function test_approval_sends_the_link_and_the_link_opens_the_form_prefilled(): void
    {
        Notification::fake();
        $sponsor = $this->activeSponsor('LM00001');
        $a = $this->application();
        app(ApplicationService::class)->approve($a, $sponsor, 'Benvenuto', null);
        $a->refresh();

        Notification::assertSentOnDemand(MembershipApplicationApproved::class);
        $this->assertSame('approved', $a->status);
        $this->assertNotNull($a->email_sent_at);

        $this->get('/registrazione?candidatura='.$a->token)->assertOk()->assertSee('luca@example.test')->assertSee('Luca')->assertSee('Diventa incaricato');
        $this->assertStringContainsString('Diventa incaricato', (new MembershipApplicationApproved($a))->toMail(new \stdClass)->render()->toHtml());
        $this->get('/registrazione?candidatura=sbagliato')->assertForbidden();
    }

    public function test_registration_with_the_link_assigns_the_sponsor_and_burns_the_link(): void
    {
        Notification::fake();
        $sponsor = $this->activeSponsor('LM00001');
        $a = $this->application();
        app(ApplicationService::class)->approve($a, $sponsor, null, null);
        $a->refresh();

        $this->post('/registrazione', $this->regData($a, ['email' => 'altra@example.test']))->assertRedirect();
        $user = User::where('email', 'luca@example.test')->firstOrFail();
        $this->assertNull(User::where('email', 'altra@example.test')->first());
        $this->assertSame($sponsor->id, Customer::where('user_id', $user->id)->firstOrFail()->assigned_consultant_id);

        $a->refresh();
        $this->assertSame('registered', $a->status);
        $this->assertNull($a->token);
        $this->assertSame($user->id, $a->registered_user_id);

        auth()->logout();
        $this->post('/registrazione', $this->regData($a, ['email' => 'x@example.test']))->assertForbidden();
    }

    public function test_without_sponsor_nobody_is_assigned_when_no_house_consultant(): void
    {
        Notification::fake();
        config(['lamess.default_consultant_code' => null]);
        $a = $this->application();
        app(ApplicationService::class)->approve($a, null, null, null);
        $a->refresh();

        $this->post('/registrazione', $this->regData($a))->assertRedirect();
        $this->assertNull(Customer::firstOrFail()->assigned_consultant_id);
    }

    public function test_expired_or_pending_or_rejected_links_do_not_work(): void
    {
        Notification::fake();
        $a = $this->application();
        $this->assertNull(app(ApplicationService::class)->findUsable('qualsiasi'));
        app(ApplicationService::class)->approve($a, null, null, null);
        $a->refresh();
        $a->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->get('/registrazione?candidatura='.$a->token)->assertForbidden();
        $this->post('/registrazione', $this->regData($a))->assertForbidden();
        $this->assertSame(0, User::where('email', 'luca@example.test')->count());
    }

    public function test_rejection_sends_a_polite_email_with_the_reason_and_kills_the_link(): void
    {
        Notification::fake();
        $a = $this->application();
        app(ApplicationService::class)->reject($a, 'Per ora non cerchiamo nella tua zona', null);
        $a->refresh();

        Notification::assertSentOnDemand(MembershipApplicationRejected::class);
        $this->assertSame('rejected', $a->status);
        $this->assertNull($a->token);
        $this->assertStringContainsString('zona', (new MembershipApplicationRejected($a))->toMail(new \stdClass)->render()->toHtml());
    }

    public function test_with_email_off_the_outcome_is_kept_and_can_be_resent(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => false]);
        $a = $this->application();
        app(ApplicationService::class)->approve($a, null, null, null);
        $a->refresh();
        Notification::assertNothingSent();
        $this->assertNull($a->email_sent_at);
        $this->assertNotNull($a->email_note);

        config(['lamess.mail_enabled' => true]);
        app(ApplicationService::class)->resend($a);
        Notification::assertSentOnDemand(MembershipApplicationApproved::class);
        $this->assertNotNull($a->refresh()->email_sent_at);
    }

    public function test_cannot_approve_when_an_account_already_exists_or_decide_twice(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'luca@example.test']);
        $a = $this->application();
        try {
            app(ApplicationService::class)->approve($a, null, null, null);
            $this->fail('atteso errore');
        } catch (InvalidArgumentException) {
            $this->assertSame('pending', $a->refresh()->status);
        }
        $b = $this->application(['email' => 'b@example.test']);
        app(ApplicationService::class)->reject($b, null, null);
        $this->expectException(InvalidArgumentException::class);
        app(ApplicationService::class)->reject($b->refresh(), null, null);
    }
}
