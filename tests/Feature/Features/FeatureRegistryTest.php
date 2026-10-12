<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Access\Roles;
use App\Domain\Audit\AuditLog;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureException;
use App\Domain\Features\FeatureRegistry;
use App\Filament\Pages\FeaturesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class FeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $perms = []): User
    {
        $u = User::factory()->role($role)->create();
        $perms !== [] && $u->givePermissionTo($perms);

        return $u;
    }

    private function admin(array $extra = ['features.manage']): User
    {
        return $this->user('admin', ['network.view', 'orders.view', ...$extra]);
    }

    public function test_unknown_keys_and_defaults_are_enabled(): void
    {
        $this->assertTrue(Feature::enabled('non.registrata', $this->admin()));
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $this->admin()));
    }

    public function test_every_registered_feature_has_label_group_and_a_valid_parent(): void
    {
        foreach (FeatureRegistry::all() as $key => $d) {
            $this->assertNotSame('', $d['label'], $key);
            $this->assertNotSame('', $d['group'], $key);
            $this->assertTrue(isset($d['parent']) ? FeatureRegistry::has($d['parent']) : true, "{$key}: genitore inesistente");
        }
    }

    public function test_disabling_for_everyone_hides_it_from_admins_but_never_from_the_super_admin(): void
    {
        $super = $this->user('super_admin');
        $admin = $this->admin();
        Feature::set('consultant360.tab.wallet', false, by: $super);

        $this->assertFalse(Feature::enabled('consultant360.tab.wallet', $admin));
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $super));
        $this->assertTrue(Feature::enabled('consultant360.tab.network', $admin));
    }

    public function test_role_rule_and_user_rule_precedence(): void
    {
        $super = $this->user('super_admin');
        $support = $this->user('support');
        $finance = $this->user('admin_finance');

        Feature::set('consultant360.tab.wallet', false, 'role', 'support', by: $super);
        $this->assertFalse(Feature::enabled('consultant360.tab.wallet', $support));
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $finance));

        Feature::set('consultant360.tab.wallet', true, 'user', (string) $support->id, by: $super);
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $support), 'la regola per utente vince su quella per ruolo');

        Feature::set('consultant360.tab.wallet', false, by: $super);
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $support));
        $this->assertFalse(Feature::enabled('consultant360.tab.wallet', $finance), 'senza eccezioni vale la regola per tutti');
    }

    public function test_turning_off_a_parent_turns_off_its_children_and_back(): void
    {
        $super = $this->user('super_admin');
        $admin = $this->admin();
        Feature::set('consultant360.section.counters', false, by: $super);
        $this->assertFalse(Feature::enabled('consultant360.counter.orders', $admin));
        Feature::set('consultant360.section.counters', true, by: $super);
        $this->assertTrue(Feature::enabled('consultant360.counter.orders', $admin));
    }

    public function test_protected_feature_cannot_be_disabled_and_super_only_ones_are_closed_to_admins(): void
    {
        $super = $this->user('super_admin');
        $admin = $this->admin();

        $this->assertFalse(Feature::enabled('consultant360.tab.audit', $admin));
        $this->assertTrue(Feature::enabled('consultant360.tab.audit', $super));
        $this->assertFalse(Feature::enabled('consultant360.action.move_sponsor', $admin));
        $this->assertTrue(Feature::enabled('consultant360.action.move_sponsor', $super));

        $this->expectException(FeatureException::class);
        Feature::set('consultant360.tab.audit', false, by: $super);
    }

    public function test_admin_cannot_turn_on_a_super_only_feature(): void
    {
        $this->expectException(FeatureException::class);
        Feature::set('consultant360.action.move_sponsor', true, by: $this->admin());
    }

    public function test_who_can_manage(): void
    {
        $this->assertTrue(Feature::canManage($this->user('super_admin')));
        $this->assertTrue(Feature::canManage($this->admin()));
        $this->assertFalse(Feature::canManage($this->admin([])));
        $this->assertFalse(Feature::canManage($this->user('support')));

        $this->expectException(FeatureException::class);
        Feature::set('consultant360.tab.wallet', false, by: $this->admin([]));
    }

    public function test_features_manage_is_grantable_to_admins_only_by_permission(): void
    {
        $this->assertContains('features.manage', Roles::grantable());
        $this->assertArrayHasKey('features.manage', Roles::PERMISSIONS);
        foreach (Roles::MATRIX as $role => $perms) {
            $this->assertNotContains('features.manage', $perms, "{$role}: il permesso si concede utente per utente");
        }
    }

    public function test_super_admin_lock_stops_the_admin(): void
    {
        $super = $this->user('super_admin');
        $admin = $this->admin();
        Feature::set('consultant360.tab.volumes', true, lock: true, by: $super);
        $this->assertTrue(Feature::lockedBySuperadmin('consultant360.tab.volumes'));

        try {
            Feature::set('consultant360.tab.volumes', false, by: $admin);
            $this->fail('la regola bloccata non doveva cambiare');
        } catch (FeatureException) {
        }
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes', $admin));

        Feature::set('consultant360.tab.volumes', false, by: $super); // il super_admin può, e il blocco resta
        $this->assertTrue(Feature::lockedBySuperadmin('consultant360.tab.volumes'));
        Feature::set('consultant360.tab.volumes', false, lock: false, by: $super);
        $this->assertFalse(Feature::lockedBySuperadmin('consultant360.tab.volumes'));
    }

    public function test_admin_cannot_lock(): void
    {
        $this->expectException(FeatureException::class);
        Feature::set('consultant360.tab.volumes', true, lock: true, by: $this->admin());
    }

    public function test_unknown_key_and_bad_scope_are_refused(): void
    {
        $super = $this->user('super_admin');
        foreach ([['x.y', 'all', ''], ['consultant360.tab.wallet', 'role', ''], ['consultant360.tab.wallet', 'all', 'support'], ['consultant360.tab.wallet', 'team', '1']] as [$k, $t, $i]) {
            try {
                Feature::set($k, false, $t, $i, by: $super);
                $this->fail("{$k}/{$t}/{$i} doveva essere rifiutata");
            } catch (FeatureException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_changes_are_audited_and_the_chain_stays_valid(): void
    {
        $super = $this->user('super_admin');
        $this->actingAs($super);
        Feature::set('consultant360.tab.wallet', false, reason: 'prova', by: $super);
        Feature::reset('consultant360.tab.wallet', by: $super);

        $rows = DB::table('audit_logs')->whereIn('action', ['features.set', 'features.reset'])->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('prova', $rows[0]->reason);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_updated_by_never_shows_the_super_admin(): void
    {
        $super = $this->user('super_admin');
        Feature::set('consultant360.tab.wallet', false, by: $super);
        $this->assertSame('Amministrazione', DB::table('ui_feature_settings')->value('updated_by'));
    }

    public function test_reset_returns_to_default_and_cache_is_refreshed(): void
    {
        $super = $this->user('super_admin');
        $admin = $this->admin();
        Feature::set('consultant360.tab.wallet', false, by: $super);
        $this->assertFalse(Feature::enabled('consultant360.tab.wallet', $admin));
        Feature::resetAll('consultant360.tab.wallet', by: $super);
        $this->assertTrue(Feature::enabled('consultant360.tab.wallet', $admin));
    }

    public function test_features_page_access_and_toggle(): void
    {
        $this->actingAs($this->admin([]));
        $this->get('/admin/funzionalita')->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get('/admin/funzionalita')->assertOk()->assertSee('Funzionalità e viste')->assertSee('Tab «Rete»');

        Livewire::test(FeaturesPage::class)->call('toggle', 'consultant360.tab.network')->assertNotified();
        $this->assertFalse(Feature::enabled('consultant360.tab.network', $admin));
        Livewire::test(FeaturesPage::class)->call('toggle', 'consultant360.tab.network');
        $this->assertTrue(Feature::enabled('consultant360.tab.network', $admin));
    }

    public function test_page_hides_super_only_rows_from_admins_and_lock_toggle_is_super_only(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/funzionalita')->assertOk()->assertDontSee('Tab «Audit»')->assertDontSee('Sposta / cambia sponsor');

        Livewire::test(FeaturesPage::class)->call('toggleLock', 'consultant360.tab.network');
        $this->assertFalse(Feature::lockedBySuperadmin('consultant360.tab.network'));

        $this->actingAs($this->user('super_admin'));
        $this->get('/admin/funzionalita')->assertOk()->assertSee('Tab «Audit»');
        Livewire::test(FeaturesPage::class)->call('toggleLock', 'consultant360.tab.network');
        $this->assertTrue(Feature::lockedBySuperadmin('consultant360.tab.network'));
    }

    public function test_exception_action_saves_a_role_rule(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(FeaturesPage::class)
            ->callAction('exception', ['scope' => 'role', 'role' => 'support', 'enabled' => false, 'reason' => 'prova'], ['key' => 'consultant360.tab.wallet'])
            ->assertNotified('Eccezione salvata');
        $this->assertFalse(Feature::enabled('consultant360.tab.wallet', $this->user('support')));
    }

    public function test_switching_off_a_list_blocks_the_menu_and_the_direct_url_but_not_the_super_admin(): void
    {
        $admin = $this->admin();
        $super = $this->user('super_admin');
        $this->actingAs($admin)->get('/admin/orders')->assertOk();

        Feature::set('backoffice.resource.orders', false, by: $super);
        $this->actingAs($admin)->get('/admin/orders')->assertForbidden();
        $this->actingAs($super)->get('/admin/orders')->assertOk();
    }
}
