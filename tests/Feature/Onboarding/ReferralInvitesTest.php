<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Access\Roles;
use App\Domain\Customers\CustomerService;
use App\Domain\Customers\ReferralInviteException;
use App\Domain\Customers\ReferralInviteService;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\ReferralInvite;
use App\Models\User;
use App\Notifications\ReferralInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OnboardingDb;
use Tests\TestCase;

final class ReferralInvitesTest extends TestCase
{
    use OnboardingDb;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1';

    private function invites(): ReferralInviteService
    {
        return app(ReferralInviteService::class);
    }

    private function emailInvite(Consultant $c, string $email = 'amica@example.test'): ReferralInvite
    {
        return $this->invites()->create($c, ['channel' => 'email', 'email' => $email, 'recipient_name' => 'Giulia']);
    }

    private function asConsultant(Consultant $c): void
    {
        $u = User::factory()->create(['name' => $c->name]);
        $c->update(['user_id' => $u->id]);
        $this->actingAs($u);
    }

    private function paidOrder(int $userId): Order
    {
        static $n = 0;
        $n++;

        return Order::create([
            'number' => "ORD-INV-{$n}", 'buyer_user_id' => $userId, 'buyer_type' => 'customer', 'status' => 'paid',
            'payment_method' => 'card', 'subtotal_net' => '123.00', 'vat_total' => '27.06', 'total' => '150.06', 'paid_at' => now(), 'billing' => [],
        ]);
    }

    public function test_email_invite_is_sent_by_the_portal_with_a_personal_link(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true]);
        $sponsor = $this->activeSponsor();

        $invite = $this->emailInvite($sponsor);

        $this->assertSame('sent', $invite->status);
        $this->assertNotNull($invite->sent_at);
        $this->assertSame($sponsor->id, $invite->consultant_id);
        $this->assertStringContainsString('/r/REFLM00001?i='.$invite->token, $this->invites()->url($invite));
        Notification::assertSentOnDemand(ReferralInvitation::class, fn (ReferralInvitation $n, array $channels, object $notifiable) => $n->invite->is($invite) && $notifiable->routes['mail'] !== []);
    }

    public function test_with_mail_switched_off_the_invite_stays_created_and_says_why(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => false]);

        $invite = $this->emailInvite($this->activeSponsor());

        $this->assertSame('created', $invite->status);
        $this->assertStringContainsString('Invio email non attivo', (string) $invite->failure_reason);
        Notification::assertNothingSent();
    }

    public function test_whatsapp_and_link_invites_are_declared_by_the_consultant(): void
    {
        $sponsor = $this->activeSponsor();

        $wa = $this->invites()->create($sponsor, ['channel' => 'whatsapp', 'phone' => '333 1234567', 'recipient_name' => 'Luca']);
        $link = $this->invites()->create($sponsor, ['channel' => 'link']);

        $this->assertSame('sent', $wa->status);
        $this->assertStringStartsWith('https://wa.me/393331234567?text=', $this->invites()->whatsappUrl($wa));
        $this->assertSame('created', $link->status);
    }

    public function test_click_on_the_personal_link_marks_it_opened_and_keeps_the_token(): void
    {
        $sponsor = $this->activeSponsor();
        $invite = $this->emailInvite($sponsor);

        $this->withHeader('User-Agent', self::BROWSER)->get('/r/REFLM00001?i='.$invite->token)->assertRedirect('/registrazione')
            ->assertCookie(config('lamess.referral_invite_cookie_name'));

        $invite->refresh();
        $this->assertSame('opened', $invite->status);
        $this->assertSame(1, $invite->opens_count);
        $this->assertNotNull($invite->opened_at);
    }

    public function test_link_previews_and_foreign_tokens_do_not_count_as_opens(): void
    {
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');
        $invite = $this->emailInvite($a);

        $this->withHeader('User-Agent', 'WhatsApp/2.23.20 A')->get('/r/REFLM00001?i='.$invite->token)->assertRedirect('/registrazione');
        $this->withHeader('User-Agent', self::BROWSER)->get('/r/REFLM00002?i='.$invite->token)->assertRedirect('/registrazione');

        $this->assertNotSame('opened', $invite->refresh()->status);
        $this->assertSame(0, $invite->opens_count);
        $this->assertNotNull($b);
    }

    public function test_registration_from_the_link_marks_the_invite_registered(): void
    {
        $sponsor = $this->activeSponsor();
        $invite = $this->emailInvite($sponsor);
        $this->withHeader('User-Agent', self::BROWSER)->get('/r/REFLM00001?i='.$invite->token);

        $customer = app(CustomerService::class)->register($this->fanData(['email' => 'altra-mail@example.test']), cookie: 'REFLM00001', inviteToken: $invite->token);

        $invite->refresh();
        $this->assertSame('registered', $invite->status);
        $this->assertSame($customer->user_id, $invite->registered_user_id);
        $this->assertNotNull($invite->registered_at);
        $this->assertSame($sponsor->id, $customer->assigned_consultant_id);
    }

    public function test_registration_by_email_without_referral_goes_to_who_invited(): void
    {
        $sponsor = $this->activeSponsor();
        config(['lamess.default_consultant_code' => null]);
        $invite = $this->emailInvite($sponsor, 'giulia@example.test');

        $customer = app(CustomerService::class)->register($this->fanData(['email' => 'Giulia@Example.test']));

        $this->assertSame('registered', $invite->refresh()->status);
        $this->assertSame($sponsor->id, $customer->assigned_consultant_id);
        $this->assertSame('invite', $customer->source);
    }

    public function test_registered_invite_becomes_ordered_after_a_paid_order(): void
    {
        $sponsor = $this->activeSponsor();
        $invite = $this->emailInvite($sponsor, 'giulia@example.test');
        $customer = app(CustomerService::class)->register($this->fanData(['email' => 'giulia@example.test']), link: 'REFLM00001');

        $this->assertSame('registered', ReferralInvite::withFirstPaidAt()->findOrFail($invite->id)->displayStatus());
        $this->assertSame(1, ReferralInvite::registeredWithoutOrder()->count());

        $this->paidOrder($customer->user_id);

        $row = ReferralInvite::withFirstPaidAt()->findOrFail($invite->id);
        $this->assertSame('ordered', $row->displayStatus());
        $this->assertSame('Ha ordinato', $row->displayLabel());
        $this->assertSame(1, ReferralInvite::ordered()->count());
        $this->assertSame(0, ReferralInvite::registeredWithoutOrder()->count());
    }

    public function test_unregistered_invites_expire_after_the_referral_days(): void
    {
        $sponsor = $this->activeSponsor();
        $old = $this->emailInvite($sponsor, 'vecchia@example.test');
        $fresh = $this->emailInvite($sponsor, 'nuova@example.test');
        $old->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(1, $this->invites()->expireOverdue());

        $this->assertSame('expired', $old->refresh()->status);
        $this->assertNotSame('expired', $fresh->refresh()->status);
        $this->assertSame(1, $this->invites()->stats($sponsor)['expired']);
    }

    public function test_duplicates_existing_users_and_daily_limit_are_refused(): void
    {
        $sponsor = $this->activeSponsor();
        $this->emailInvite($sponsor, 'giulia@example.test');
        User::factory()->create(['email' => 'iscritta@example.test']);

        foreach (['giulia@example.test' => 'già invitato', 'iscritta@example.test' => 'già iscritta'] as $email => $needle) {
            try {
                $this->emailInvite($sponsor, $email);
                $this->fail("L'invito a {$email} doveva essere rifiutato.");
            } catch (ReferralInviteException $e) {
                $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
            }
        }

        config(['lamess.referral_invites_per_day' => 1]);
        $this->expectException(ReferralInviteException::class);
        $this->invites()->create($sponsor, ['channel' => 'link']);
    }

    public function test_reminder_rules(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true]);
        $invite = $this->emailInvite($this->activeSponsor());

        try {
            $this->invites()->remind($invite);
            $this->fail('Il promemoria dopo pochi minuti doveva essere rifiutato.');
        } catch (ReferralInviteException $e) {
            $this->assertStringContainsString('24 ore', $e->getMessage());
        }

        $invite->forceFill(['last_sent_at' => now()->subDays(2)])->save();
        $this->assertTrue($this->invites()->remind($invite));
        $this->assertSame(1, $invite->refresh()->reminders_count);

        $invite->forceFill(['last_sent_at' => now()->subDays(2), 'reminders_count' => ReferralInviteService::MAX_REMINDERS])->save();
        $this->expectException(ReferralInviteException::class);
        $this->invites()->remind($invite);
    }

    public function test_consultant_page_creates_and_lists_invites_and_hides_the_others(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true]);
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');
        $this->emailInvite($b, 'dell-altro@example.test');
        $this->asConsultant($a);

        $this->post('/area/referral/inviti', ['channel' => 'email', 'email' => 'mia@example.test', 'recipient_name' => 'Marta'])
            ->assertRedirect('/area/referral')->assertSessionHas('status');
        $this->post('/area/referral/inviti', ['channel' => 'email'])->assertSessionHasErrors('email');

        $this->get('/area/referral')->assertOk()->assertSee('Marta')->assertSee('mia@example.test')->assertSee('Inviato')->assertDontSee('dell-altro@example.test');
        $this->assertSame(1, ReferralInvite::where('consultant_id', $a->id)->count());
    }

    public function test_a_consultant_cannot_remind_someone_elses_invite(): void
    {
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');
        $theirs = $this->emailInvite($b, 'dell-altro@example.test');
        $this->asConsultant($a);

        $this->post("/area/referral/inviti/{$theirs->id}/ricorda")->assertNotFound();
    }

    public function test_admin_list_shows_all_invites_for_back_office_users(): void
    {
        $a = $this->activeSponsor('LM00001');
        $b = $this->activeSponsor('LM00002');
        $this->emailInvite($a, 'uno@example.test');
        $this->emailInvite($b, 'due@example.test');

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->get('/admin/referral-invites')->assertOk()->assertSee('uno@example.test')->assertSee('due@example.test');
    }
}
