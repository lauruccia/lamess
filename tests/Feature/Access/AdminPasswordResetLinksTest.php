<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\PasswordResetLinks;
use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\ResetPasswordIt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminPasswordResetLinksTest extends TestCase
{
    use RefreshDatabase;

    private PasswordResetLinks $links;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.mail_enabled' => true]);
        Notification::fake();
        $this->links = new PasswordResetLinks;
    }

    private function consultant(string $code, string $status = 'active'): Consultant
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.test']);

        return Consultant::create([
            'code' => $code, 'name' => "Incaricato {$code}", 'user_id' => $user->id, 'status' => $status,
            'since' => now(), 'onboarding_complete' => true,
        ]);
    }

    private function customer(string $email): Customer
    {
        $user = User::factory()->create(['email' => $email]);

        return Customer::create(['user_id' => $user->id, 'source' => 'referral', 'registered_at' => now()]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_single_send_keeps_the_password_and_is_audited(): void
    {
        $this->actingAsAdmin();
        $customer = $this->customer('fan@example.test');
        $before = $customer->user->password;

        $r = $this->links->send([$customer->user_id]);

        $this->assertSame(1, $r['sent']);
        Notification::assertSentTo($customer->user, ResetPasswordIt::class);
        $this->assertSame($before, $customer->user->fresh()->password, 'La password non cambia finché la persona non usa il link.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reset_link_sent']);
    }

    public function test_backoffice_invalid_email_and_missing_accounts_are_skipped(): void
    {
        $this->actingAsAdmin();
        $ok = $this->customer('ok@example.test');
        $admin = User::factory()->role(Roles::ADMIN)->create();
        $noEmail = User::factory()->create(['email' => 'non-una-email']);

        $r = $this->links->send([$ok->user_id, $admin->id, $noEmail->id, 999999]);

        $this->assertSame(1, $r['sent']);
        $this->assertSame(['backoffice' => 1, 'no_email' => 1, 'not_found' => 1], $r['skipped']);
        Notification::assertSentToTimes($ok->user, ResetPasswordIt::class, 1);
        Notification::assertNotSentTo($admin, ResetPasswordIt::class);
    }

    public function test_second_run_skips_recent_links_so_an_interrupted_batch_can_resume(): void
    {
        $this->actingAsAdmin();
        $a = $this->customer('a@example.test');
        $b = $this->customer('b@example.test');
        $this->links->send([$a->user_id], false);

        $r = $this->links->send([$a->user_id, $b->user_id], true);

        $this->assertSame(1, $r['sent']);
        $this->assertSame(['recent' => 1], $r['skipped']);
        Notification::assertSentToTimes($a->user, ResetPasswordIt::class, 1);
        Notification::assertSentToTimes($b->user, ResetPasswordIt::class, 1);
    }

    public function test_old_links_do_not_count_as_recent(): void
    {
        $this->actingAsAdmin();
        $a = $this->customer('a@example.test');
        DB::table('password_reset_tokens')->insert(['email' => $a->user->email, 'token' => 'x', 'created_at' => now()->subHours(PasswordResetLinks::RECENT_HOURS + 1)]);

        $this->assertSame(1, $this->links->send([$a->user_id], true)['sent']);
    }

    public function test_time_budget_reports_how_many_are_left(): void
    {
        $this->actingAsAdmin();
        $ids = [$this->customer('a@example.test')->user_id, $this->customer('b@example.test')->user_id];

        $r = $this->links->send($ids, false, 0);

        $this->assertSame(0, $r['sent']);
        $this->assertSame(2, $r['remaining']);
        Notification::assertNothingSent();
    }

    public function test_audiences(): void
    {
        $active = $this->consultant('LM00001');
        $terminated = $this->consultant('LM00002', 'terminated');
        $fan = $this->customer('fan@example.test');
        $both = $this->customer('both@example.test');
        Consultant::create(['code' => 'LM00003', 'name' => 'Doppio', 'user_id' => $both->user_id, 'status' => 'active', 'since' => now(), 'onboarding_complete' => true]);

        $this->assertEqualsCanonicalizing([$active->user_id, $both->user_id], $this->links->userIdsFor(PasswordResetLinks::AUDIENCE_CONSULTANTS));
        $this->assertEqualsCanonicalizing([$active->user_id, $terminated->user_id, $both->user_id], $this->links->userIdsFor(PasswordResetLinks::AUDIENCE_CONSULTANTS, true));
        $this->assertEqualsCanonicalizing([$fan->user_id, $both->user_id], $this->links->userIdsFor(PasswordResetLinks::AUDIENCE_CUSTOMERS));
        $this->assertEqualsCanonicalizing([$active->user_id, $fan->user_id, $both->user_id], $this->links->userIdsFor(PasswordResetLinks::AUDIENCE_BOTH), 'Chi è incaricato e cliente conta una volta sola.');
    }

    public function test_row_and_bulk_actions_on_the_lists(): void
    {
        $this->actingAsAdmin();
        $c = $this->consultant('LM00001');
        $fan = $this->customer('fan@example.test');

        Livewire::test(ListConsultants::class)->callTableAction('sendPasswordReset', $c)->assertNotified();
        Notification::assertSentTo($c->user, ResetPasswordIt::class);

        Livewire::test(ListCustomers::class)->callTableBulkAction('sendPasswordResetBulk', [$fan])->assertNotified();
        Notification::assertSentTo($fan->user, ResetPasswordIt::class);
    }

    public function test_send_to_all_needs_the_confirmation_word(): void
    {
        $this->actingAsAdmin();
        $a = $this->customer('a@example.test');
        $b = $this->customer('b@example.test');
        $consultant = $this->consultant('LM00001');

        Livewire::test(ListCustomers::class)->callAction('sendPasswordResetAll', ['audience' => 'customers', 'skip_recent' => true, 'confirm' => 'no'])->assertHasActionErrors(['confirm']);
        Notification::assertNothingSent();

        Livewire::test(ListCustomers::class)->callAction('sendPasswordResetAll', ['audience' => 'customers', 'skip_recent' => true, 'confirm' => 'INVIA'])->assertHasNoActionErrors();
        Notification::assertSentTo([$a->user, $b->user], ResetPasswordIt::class);
        Notification::assertNotSentTo($consultant->user, ResetPasswordIt::class);
    }

    public function test_nothing_is_sent_and_no_link_is_created_while_email_is_off(): void
    {
        config(['lamess.mail_enabled' => false]);
        $this->actingAsAdmin();
        $fan = $this->customer('fan@example.test');

        Livewire::test(ListCustomers::class)->callTableAction('sendPasswordReset', $fan)->assertNotified('Invio email disattivato');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_user_list_can_send_to_a_customer_too(): void
    {
        $this->actingAsAdmin();
        $fan = $this->customer('fan@example.test');

        Livewire::test(ListUsers::class)->callTableAction('sendPasswordReset', $fan->user)->assertNotified();

        Notification::assertSentTo($fan->user, ResetPasswordIt::class);
    }

    public function test_admin_without_network_permission_cannot_use_it(): void
    {
        $limited = app(UserAdministration::class)->createAdmin('Solo lettura', 'lettura@example.test', ['network.view'])['user'];
        $fan = $this->customer('fan@example.test');
        $this->actingAs($limited);

        Livewire::test(ListCustomers::class)->assertTableActionHidden('sendPasswordReset', $fan);
        Notification::assertNothingSent();
    }
}
