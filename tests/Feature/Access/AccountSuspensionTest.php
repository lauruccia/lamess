<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\AccountSuspension;
use App\Domain\Access\Roles;
use App\Domain\Customers\ReferralResolver;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditEntry;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.open' => true]);
    }

    private function consultant(string $code, ?Consultant $sponsor = null): Consultant
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.test', 'password' => 'Password-Giusta-123']);

        return Consultant::create([
            'code' => $code, 'name' => "Incaricato {$code}", 'user_id' => $user->id, 'status' => 'active', 'referral_code' => 'REF'.$code,
            'sponsor_id' => $sponsor?->id, 'since' => now(), 'onboarding_complete' => true,
        ]);
    }

    public function test_suspend_blocks_login_closes_sessions_and_is_audited(): void
    {
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $user = User::factory()->role('customer')->create(['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        app(AccountSuspension::class)->suspend($user, 'banned', 'Frode', $admin);

        $this->assertTrue($user->fresh()->isSuspended());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, AuditEntry::where('action', 'user.suspended')->count());

        $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123'])
            ->assertSessionHasErrors(['email' => 'Questo account è stato bloccato. Per chiarimenti scrivi all\'assistenza.']);
        $this->assertGuest();
        $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'sbagliata'])
            ->assertSessionHasErrors(['email' => 'Credenziali non corrette.']);

        app(AccountSuspension::class)->reinstate($user->fresh(), $admin);
        $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_an_open_session_is_closed_on_the_next_request(): void
    {
        $user = User::factory()->role('customer')->create();
        $this->actingAs($user);
        $user->forceFill(['suspended_at' => now(), 'suspension_type' => 'suspended'])->save();

        $this->get('/area')->assertRedirect('/accedi');
        $this->assertGuest();
    }

    public function test_who_can_suspend_whom(): void
    {
        $super = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $otherSuper = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $network = User::factory()->role('admin_network')->create();
        $finance = User::factory()->role('admin_finance')->create();
        $customer = User::factory()->role('customer')->create();
        $backoffice = User::factory()->role('admin_finance')->create();

        $this->assertTrue(AccountSuspension::canManage($super, $customer));
        $this->assertTrue(AccountSuspension::canManage($super, $backoffice));
        $this->assertFalse(AccountSuspension::canManage($super, $otherSuper));
        $this->assertFalse(AccountSuspension::canManage($super, $super));
        $this->assertTrue(AccountSuspension::canManage($network, $customer));
        $this->assertFalse(AccountSuspension::canManage($network, $backoffice));
        $this->assertFalse(AccountSuspension::canManage($finance, $customer));
        $this->assertFalse(AccountSuspension::canManage(null, $customer));

        $this->expectException(ValidationException::class);
        app(AccountSuspension::class)->suspend($customer, 'suspended', 'x', $finance);
    }

    public function test_a_reason_is_required(): void
    {
        $super = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->expectException(ValidationException::class);
        app(AccountSuspension::class)->suspend(User::factory()->create(), 'suspended', '  ', $super);
    }

    public function test_suspended_consultant_referral_link_stops_working(): void
    {
        $c = $this->consultant('LM00001');
        $this->assertSame($c->id, app(ReferralResolver::class)->find('REFLM00001')?->id);

        app(AccountSuspension::class)->suspend($c->user, 'suspended', 'Verifiche', User::factory()->role(Roles::SUPER_ADMIN)->create());

        $this->assertNull(app(ReferralResolver::class)->find('REFLM00001'));
    }

    public function test_hidden_parent_is_skipped_in_trees(): void
    {
        $parentOf = [1 => null, 2 => 1, 3 => 2];
        $this->assertSame(1, AccountSuspension::visibleParent($parentOf, [2 => true], 2));
        $this->assertNull(AccountSuspension::visibleParent($parentOf, [1 => true, 2 => true], 2));
        $this->assertSame(3, AccountSuspension::visibleParent($parentOf, [], 3));
    }

    public function test_lists_hide_blocked_accounts_until_the_filter_asks_for_them(): void
    {
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin);
        $ok = $this->consultant('LM00010');
        $bad = $this->consultant('LM00011');
        $cu = User::factory()->create();
        $cb = User::factory()->create();
        $okCustomer = Customer::create(['user_id' => $cu->id, 'source' => 'referral', 'registered_at' => now()]);
        $badCustomer = Customer::create(['user_id' => $cb->id, 'source' => 'referral', 'registered_at' => now()]);
        $svc = app(AccountSuspension::class);
        $svc->suspend($bad->user, 'suspended', 'Test', $admin);
        $svc->suspend($cb, 'banned', 'Test', $admin);

        Livewire::test(ListConsultants::class)->assertCanSeeTableRecords([$ok])->assertCanNotSeeTableRecords([$bad])
            ->filterTable('account_status', 'suspended')->assertCanSeeTableRecords([$bad])->assertCanNotSeeTableRecords([$ok]);
        Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$okCustomer])->assertCanNotSeeTableRecords([$badCustomer])
            ->filterTable('account_status', 'banned')->assertCanSeeTableRecords([$badCustomer]);
        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$cb->fresh()])
            ->filterTable('account_status', 'blocked')->assertCanSeeTableRecords([$cb->fresh(), $bad->user->fresh()]);
    }

    public function test_row_actions_suspend_and_reinstate_with_a_reason(): void
    {
        $admin = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($admin);
        $c = $this->consultant('LM00020');

        Livewire::test(ListConsultants::class)->assertTableActionVisible('suspendAccount', $c)->assertTableActionHidden('reinstateAccount', $c)
            ->callTableAction('suspendAccount', $c, ['type' => 'banned', 'reason' => 'Violazione regolamento']);
        $this->assertSame('banned', $c->user->fresh()->suspension_type);

        Livewire::test(ListConsultants::class)->filterTable('account_status', 'banned')
            ->assertTableActionVisible('reinstateAccount', $c)->callTableAction('reinstateAccount', $c);
        $this->assertFalse($c->user->fresh()->isSuspended());
    }

    public function test_suspended_users_are_not_charged_for_subscriptions(): void
    {
        $sql = AccountSuspension::suspendedUserIds()->toSql();
        $this->assertStringContainsString('suspended_at', $sql);
    }
}
