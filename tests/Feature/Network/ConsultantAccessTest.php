<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Domain\Access\Roles;
use App\Domain\Network\ConsultantAccess;
use App\Domain\Network\NetworkException;
use App\Filament\Resources\Consultants\Pages\CreateConsultant;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Models\Consultant;
use App\Models\User;
use App\Notifications\InvitoAccessoIt;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class ConsultantAccessTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.mail_enabled' => true]);
    }

    private function admin(): User
    {
        Roles::sync();

        return User::factory()->role(Roles::SUPER_ADMIN)->create();
    }

    private function access(): ConsultantAccess
    {
        return app(ConsultantAccess::class);
    }

    public function test_creating_with_email_creates_the_user_links_it_and_sends_the_invite(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $this->openPeriod('2026-10');

        Livewire::test(CreateConsultant::class)
            ->fillForm(['first_name' => 'Linda', 'last_name' => 'Gean', 'person_key' => 'gnelnd80a41h501x', 'email' => ' Linda@Example.com ', 'since' => '2026-10-14 08:00:00'])
            ->call('create')->assertHasNoFormErrors();

        $c = Consultant::where('name', 'Linda Gean')->firstOrFail();
        $user = User::where('email', 'linda@example.com')->firstOrFail();
        $this->assertSame($user->id, $c->user_id);
        $this->assertTrue($user->hasRole('consultant'));
        $this->assertNotNull($c->referral_code);
        $this->assertSame($c->code, $c->badge_number, 'iscritto dall\'admin: il tesserino nasce subito');
        $this->assertSame(['Linda', 'Gean', 'GNELND80A41H501X'], [$user->profile->first_name, $user->profile->last_name, $user->profile->tax_code]);
        $this->assertSame($c->id, $user->consultant->id);
        Notification::assertSentTo($user, InvitoAccessoIt::class);
    }

    public function test_creating_without_email_creates_no_user(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $this->openPeriod('2026-10');
        $before = User::count();

        Livewire::test(CreateConsultant::class)->fillForm(['first_name' => 'Senza', 'last_name' => 'Email', 'since' => '2026-10-14 08:00:00'])->call('create')->assertHasNoFormErrors();

        $this->assertNull(Consultant::where('name', 'Senza Email')->firstOrFail()->user_id);
        $this->assertSame($before, User::count());
        Notification::assertNothingSent();
    }

    public function test_creating_with_full_personal_data_saves_the_profile_and_sponsor_is_optional(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $this->openPeriod('2026-10');

        Livewire::test(CreateConsultant::class)
            ->fillForm([
                'first_name' => 'Maria Rosa', 'last_name' => 'De Luca', 'person_key' => 'dlcmrs86c52h224x', 'birth_date' => '1986-03-12', 'birth_place' => 'Reggio Calabria',
                'address' => 'Via Marina 12', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC', 'country_code' => 'IT', 'phone' => '+39 347 000 0000',
                'email' => 'maria@example.com', 'iban' => 'IT60 X054 2811 1010 0000 0123 456', 'since' => '2026-10-14 08:00:00',
            ])->call('create')->assertHasNoFormErrors();

        $c = Consultant::where('name', 'Maria Rosa De Luca')->firstOrFail();
        $this->assertNull($c->sponsor_id);
        $p = User::where('email', 'maria@example.com')->firstOrFail()->profile;
        $this->assertSame(['Maria Rosa', 'De Luca', 'DLCMRS86C52H224X', 'Via Marina 12', '89100', 'RC'], [$p->first_name, $p->last_name, $p->tax_code, $p->address, $p->zip, $p->province]);
        $this->assertSame('1986-03-12', $p->birth_date->format('Y-m-d'));
        $this->assertSame('+39 347 000 0000', $p->phone);
        $this->assertSame(['IT60X0542811101000000123456', 'Maria Rosa De Luca'], [$c->iban, $c->iban_holder]);
    }

    public function test_personal_data_without_email_is_refused_and_minors_are_refused(): void
    {
        $this->actingAs($this->admin());
        $this->openPeriod('2026-10');

        Livewire::test(CreateConsultant::class)
            ->fillForm(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'city' => 'Roma', 'since' => '2026-10-14 08:00:00'])
            ->call('create')->assertHasFormErrors(['email']);
        Livewire::test(CreateConsultant::class)
            ->fillForm(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'birth_date' => now()->subYears(16)->toDateString(), 'email' => 'anna@example.com', 'since' => '2026-10-14 08:00:00'])
            ->call('create')->assertHasFormErrors(['birth_date']);
        Livewire::test(CreateConsultant::class)
            ->fillForm(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'iban' => 'IT00', 'since' => '2026-10-14 08:00:00'])
            ->call('create')->assertHasFormErrors(['iban']);
    }

    public function test_the_invited_user_can_choose_a_password_and_log_in(): void
    {
        Notification::fake();
        $c = $this->consultant('A1');
        $user = $this->access()->grant($c, 'nuova@example.com');
        $token = null;
        Notification::assertSentTo($user, InvitoAccessoIt::class, function (ResetPassword $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        $this->post('/password-reset', ['token' => $token, 'email' => 'nuova@example.com', 'password' => 'una-password-lunga-1', 'password_confirmation' => 'una-password-lunga-1'])->assertRedirect();
        $this->post('/esci');
        $this->post('/accedi', ['email' => 'nuova@example.com', 'password' => 'una-password-lunga-1'])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_an_existing_customer_is_linked_instead_of_duplicated(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'cliente@example.com']);
        $c = $this->consultant('A1');

        $user = $this->access()->grant($c, 'CLIENTE@example.com');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame($existing->id, $c->refresh()->user_id);
        $this->assertTrue($existing->refresh()->hasRole('consultant'));
        $this->assertSame(1, User::where('email', 'cliente@example.com')->count());
    }

    public function test_refuses_backoffice_users_linked_emails_and_double_access(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $a = $this->consultant('A1');
        $b = $this->consultant('B1');
        $this->access()->grant($a, 'a@example.com');

        foreach ([[$b, $admin->email], [$b, 'a@example.com'], [$a, 'altra@example.com'], [$b, 'non-una-email']] as [$consultant, $email]) {
            try {
                $this->access()->grant($consultant, $email);
                $this->fail("Doveva rifiutare {$email}");
            } catch (NetworkException) {
                $this->assertTrue(true);
            }
        }
        $this->assertNull($b->refresh()->user_id);
        $this->assertFalse($admin->refresh()->hasRole('consultant'));
    }

    public function test_terminated_consultant_gets_no_access(): void
    {
        $c = $this->consultant('A1');
        $c->update(['status' => 'terminated']);

        $this->expectException(NetworkException::class);
        $this->access()->grant($c, 'x@example.com');
    }

    public function test_with_email_sending_off_the_access_is_created_but_no_invite_goes_out(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => false]);
        $this->actingAs($this->admin());
        $c = $this->consultant('A1');

        Livewire::test(ViewConsultant::class, ['record' => $c->getKey()])
            ->callAction('grantAccess', ['email' => 'a@example.com'])->assertNotified('Accesso creato, invito non inviato');

        $this->assertNotNull($c->refresh()->user_id);
        Notification::assertNothingSent();
    }

    public function test_grant_access_from_the_card_and_the_list(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $c = $this->consultant('A1');
        $d = $this->consultant('D1');

        Livewire::test(ViewConsultant::class, ['record' => $c->getKey()])
            ->assertActionVisible('grantAccess')
            ->callAction('grantAccess', ['email' => 'a@example.com'])->assertNotified('Accesso creato');
        $this->assertNotNull($c->refresh()->user_id);
        Notification::assertSentTo($c->user, InvitoAccessoIt::class);

        Livewire::test(ViewConsultant::class, ['record' => $c->getKey()])->assertActionHidden('grantAccess');

        Livewire::test(ListConsultants::class)->callTableAction('grantAccess', $d, ['email' => 'd@example.com'])->assertNotified('Accesso creato');
        $this->assertNotNull($d->refresh()->user_id);
    }
}
