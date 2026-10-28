<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\AccessProfiles;
use App\Domain\Access\AccessReport;
use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Filament\Pages\RolesPermissionsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class RolesPermissionsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->actingAs($this->super);
    }

    public function test_the_super_admin_opens_the_page(): void
    {
        $this->get('/admin/ruoli-permessi')->assertOk()->assertSee('Ruoli e permessi')->assertSee('Matrice dei permessi');
    }

    #[DataProvider('everyoneElse')]
    public function test_nobody_else_opens_the_page(string $who): void
    {
        $user = match ($who) {
            'admin con tutti i permessi' => (new UserAdministration)->createAdmin('A', 'a@example.test', Roles::grantable())['user'],
            'admin_finance' => User::factory()->role('admin_finance')->create(),
            'incaricato' => User::factory()->role('consultant')->create(),
        };

        $this->actingAs($user)->get('/admin/ruoli-permessi')->assertForbidden();
        $this->assertFalse(RolesPermissionsPage::canAccess());
    }

    /** @return array<string, array{string}> */
    public static function everyoneElse(): array
    {
        return ['admin con tutti i permessi' => ['admin con tutti i permessi'], 'admin_finance' => ['admin_finance'], 'incaricato' => ['incaricato']];
    }

    public function test_the_matrix_lists_every_permission_and_profile(): void
    {
        $page = Livewire::test(RolesPermissionsPage::class);
        foreach (array_values(Roles::PRESET_LABELS) as $label) {
            $page->assertSee($label);
        }
        $page->assertSee('Vedere prodotti, categorie, sconti, bundle e tariffe di spedizione (sola lettura)')->assertSee('riservato');
    }

    public function test_a_profile_can_be_created_edited_copied_and_deleted_from_the_page(): void
    {
        $page = Livewire::test(RolesPermissionsPage::class)
            ->mountAction('profile')
            ->fillForm(['label' => 'Ordini e spedizioni'])
            ->set('mountedActions.0.data.perm.vendite', ['orders.manage']);
        $this->assertSame(['orders.view', 'orders.manage'], $page->instance()->mountedActions[0]['data']['perm']['vendite'], 'spuntare «gestire» accende anche «vedere»');
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(['orders.view', 'orders.manage'], AccessProfiles::permissions('profilo:Ordini e spedizioni'));

        $page->callAction('profile', ['label' => 'Ordini e resi', 'perm' => ['vendite' => ['orders.view', 'orders.manage', 'catalog.view']]], ['key' => 'profilo:Ordini e spedizioni'])
            ->assertHasNoActionErrors();
        $this->assertTrue(AccessProfiles::exists('profilo:Ordini e resi'));
        $this->assertFalse(AccessProfiles::exists('profilo:Ordini e spedizioni'));
        $this->assertContains('catalog.view', AccessProfiles::permissions('profilo:Ordini e resi'));

        $page->mountAction('profile', ['from' => 'board'])->assertSchemaStateSet(['label' => 'Copia di Sola lettura (direzione)']);
        $page->unmountAction()->callAction('deleteProfile', [], ['key' => 'profilo:Ordini e resi']);
        $this->assertFalse(AccessProfiles::exists('profilo:Ordini e resi'));
    }

    public function test_a_bad_profile_name_is_reported_and_nothing_is_saved(): void
    {
        Livewire::test(RolesPermissionsPage::class)
            ->callAction('profile', ['label' => 'Rete e anagrafiche', 'perm' => ['cruscotto' => ['kpi.view']]])
            ->assertNotified('Profilo non salvato');
        $this->assertSame([], array_filter(array_keys(AccessProfiles::all()), AccessProfiles::isCustom(...)));
    }

    public function test_user_access_shows_permissions_menu_and_warnings(): void
    {
        $admin = (new UserAdministration)->createAdmin('Anna Admin', 'anna@example.test', ['orders.manage'])['user'];
        (new UserAdministration)->setMenu($admin, ['backoffice.resource.orders']);

        $r = AccessReport::forUser($admin);
        $this->assertSame(2, $r['permission_count']);
        $this->assertSame(['orders.manage'], $r['sensitive']);
        $this->assertSame(1, $r['menu_visible_count']);
        $this->assertContains('Ordini', $r['menu_visible']['Vendite']);
        $this->assertNotEmpty(array_filter($r['warnings'], fn (string $w) => str_contains($w, '2FA')));

        $page = Livewire::test(RolesPermissionsPage::class)->call('setTab', 'utenti')->assertSee('Anna Admin');
        $page->mountAction('userAccess', ['id' => $admin->id])->assertActionMounted('userAccess');

        $html = view('filament.pages.partials.user-access', ['r' => $r, 'editUrl' => '/admin/users/'.$admin->id.'/edit'])->render();
        $this->assertStringContainsString('Conferma gli incassi', $html);
        $this->assertStringContainsString('sensibile', $html);
        $this->assertStringContainsString('Voci nascoste', $html);
        $this->assertStringContainsString('2FA', $html);
    }

    public function test_user_access_refuses_super_admins_and_portal_users(): void
    {
        $member = User::factory()->role('consultant')->create();
        foreach ([$this->super->id, $member->id] as $id) {
            try {
                Livewire::test(RolesPermissionsPage::class)->mountAction('userAccess', ['id' => $id]);
                $this->fail('Doveva rifiutare.');
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_csv_exports_are_safe_and_logged(): void
    {
        (new UserAdministration)->createAdmin('=HYPERLINK("x")', 'evil@example.test', ['kpi.view']);

        $matrix = AccessReport::matrixCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $matrix);
        $this->assertStringContainsString('catalog.view', $matrix);

        $users = AccessReport::usersCsv();
        $this->assertStringContainsString("'=HYPERLINK", $users);
        $this->assertStringNotContainsString(';=HYPERLINK', $users);

        Livewire::test(RolesPermissionsPage::class)->callAction('exportMatrix')->assertFileDownloaded('matrice-permessi.csv');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'access.matrix_exported')->count());
    }

    public function test_sync_recreates_missing_permissions_without_touching_users(): void
    {
        $admin = (new UserAdministration)->createAdmin('A', 'a@example.test', ['kpi.view'])['user'];
        Permission::query()->where('name', 'fees.view')->delete();

        Livewire::test(RolesPermissionsPage::class)->callAction('sync')->assertNotified();

        $this->assertTrue(Permission::query()->where('name', 'fees.view')->exists());
        $this->assertSame(['kpi.view'], $admin->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'access.roles_synced')->count());
    }
}
