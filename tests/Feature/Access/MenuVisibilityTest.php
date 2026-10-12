<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureException;
use App\Domain\Features\FeatureRegistry;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Pages\NetworkTreePage;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private UserAdministration $svc;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new UserAdministration;
        $this->super = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($this->super);
    }

    /** @return array{user: User, password: string} */
    private function fullAdmin(string $email = 'cliente@example.test'): array
    {
        return $this->svc->createAdmin('Cliente', $email, Roles::grantable());
    }

    public function test_every_resource_using_permissions_is_in_the_menu_registry(): void
    {
        $missing = [];
        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            $class = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            if (in_array(GatedByPermission::class, class_uses($class), true)) {
                $key = 'backoffice.resource.'.str_replace('-', '_', $class::getSlug());
                FeatureRegistry::has($key) || $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'Voci di menu non registrate: non si potrebbero nascondere.');
    }

    public function test_only_the_chosen_entries_stay_visible(): void
    {
        $admin = $this->fullAdmin()['user'];
        $this->svc->setMenu($admin, ['backoffice.resource.consultants', 'backoffice.widget.kpi']);

        $this->assertTrue(Feature::enabled('backoffice.resource.consultants', $admin));
        $this->assertTrue(Feature::enabled('backoffice.widget.kpi', $admin));
        $this->assertFalse(Feature::enabled('backoffice.resource.orders', $admin));
        $this->assertFalse(Feature::enabled('backoffice.page.albero_rete', $admin));
        $this->assertEqualsCanonicalizing(['backoffice.resource.consultants', 'backoffice.widget.kpi'], $this->svc->visibleKeys($admin));
    }

    public function test_hiding_is_per_user_and_never_touches_the_super_admin(): void
    {
        $a = $this->fullAdmin('a@example.test')['user'];
        $b = $this->fullAdmin('b@example.test')['user'];
        $this->svc->setMenu($a, []);

        $this->assertFalse(Feature::enabled('backoffice.resource.orders', $a));
        $this->assertTrue(Feature::enabled('backoffice.resource.orders', $b));
        $this->assertTrue(Feature::enabled('backoffice.resource.orders', $this->super));
    }

    public function test_hidden_entries_are_blocked_also_by_direct_address(): void
    {
        $admin = $this->fullAdmin()['user'];
        $this->svc->setMenu($admin, ['backoffice.resource.orders']);

        $this->actingAs($admin)->get('/admin/orders')->assertOk();
        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/albero-rete')->assertForbidden();
        $this->get('/admin/cruscotto-vendite')->assertForbidden();
        $this->assertTrue(OrderResource::canViewAny());
        $this->assertFalse(NetworkTreePage::canAccess());
    }

    public function test_entries_can_be_shown_again(): void
    {
        $admin = $this->fullAdmin()['user'];
        $this->svc->setMenu($admin, []);
        $this->svc->setMenu($admin, ['backoffice.resource.orders', 'backoffice.page.albero_rete']);

        $this->assertTrue(Feature::enabled('backoffice.resource.orders', $admin));
        $this->assertTrue(Feature::enabled('backoffice.page.albero_rete', $admin));
        $this->assertFalse(Feature::enabled('backoffice.resource.customers', $admin));
    }

    public function test_menu_does_not_grant_permissions(): void
    {
        $admin = $this->svc->createAdmin('Poco', 'poco@example.test', ['orders.view'])['user'];
        $this->svc->setMenu($admin, ['backoffice.resource.orders', 'backoffice.resource.payouts']);

        $this->actingAs($admin)->get('/admin/orders')->assertOk();
        $this->get('/admin/payouts')->assertForbidden();
    }

    public function test_unknown_keys_and_non_admins_are_refused(): void
    {
        $admin = $this->fullAdmin()['user'];
        try {
            $this->svc->setMenu($admin, ['backoffice.resource.inesistente']);
            $this->fail('Una voce sconosciuta deve essere rifiutata.');
        } catch (ValidationException) {
            $this->assertTrue(Feature::enabled('backoffice.resource.orders', $admin), 'Nulla è stato modificato.');
        }

        $this->expectException(ValidationException::class);
        $this->svc->setMenu($this->super, []);
    }

    public function test_only_differences_are_written_to_the_audit_log(): void
    {
        $admin = $this->fullAdmin()['user'];
        $this->svc->setMenu($admin, []);
        $before = DB::table('audit_logs')->count();
        $this->svc->setMenu($admin, []);

        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_menu_state_matches_what_the_form_needs(): void
    {
        $admin = $this->fullAdmin()['user'];
        $this->svc->setMenu($admin, ['backoffice.resource.consultants', 'backoffice.page.cruscotto_vendite']);
        $state = $this->svc->menuState($admin);

        $this->assertSame(['backoffice.resource.consultants'], $state['rete']);
        $this->assertSame(['backoffice.page.cruscotto_vendite'], $state['vendite']);
        $this->assertSame([], $state['cruscotto']);
        $this->assertEqualsCanonicalizing(['backoffice.resource.consultants', 'backoffice.page.cruscotto_vendite'], UserAdministration::flattenMenu($state));
    }

    public function test_a_restricted_admin_neither_sees_nor_changes_the_menu_rules(): void
    {
        $admin = $this->fullAdmin()['user'];   // ha anche features.manage
        $this->assertTrue($admin->can('features.manage'));
        $this->svc->setMenu($admin, ['backoffice.resource.orders']);

        $this->actingAs($admin);
        foreach ([
            fn () => Feature::set('backoffice.resource.customers', true, 'user', (string) $admin->id, by: $admin),
            fn () => Feature::reset('backoffice.resource.customers', 'user', (string) $admin->id, by: $admin),
            fn () => Feature::set('backoffice.resource.customers', true, by: $admin),
            fn () => Feature::resetAll('backoffice.resource.customers', by: $admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('L\'admin non deve poter cambiare le voci di menu.');
            } catch (FeatureException) {
                $this->assertFalse(Feature::enabled('backoffice.resource.customers', $admin));
            }
        }

        $this->get('/admin/funzionalita')->assertOk()
            ->assertDontSee('Elenco «Clienti»')->assertDontSee('Elenco «Ordini»')->assertDontSee('eccezione per te')->assertDontSee('spenta per utente');
    }

    public function test_the_super_admin_still_manages_menu_rules_in_the_features_page(): void
    {
        $this->get('/admin/funzionalita')->assertOk()->assertSee('Elenco «Clienti»');
    }
}
