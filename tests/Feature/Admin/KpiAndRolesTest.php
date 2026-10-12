<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\Roles;
use App\Domain\Kpi\KpiService;
use App\Domain\Periods\PeriodService;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class KpiAndRolesTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    public function test_kpi_snapshot_matches_the_calculation(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $this->sale($p, $b, '8000');
        app(PeriodService::class)->calculate($p);

        $k = app(KpiService::class)->snapshot('2026-10');

        $this->assertSame('2026-10', $k['period']);
        $this->assertSame(1, $k['movements']);
        $this->assertSame('8000.00', $k['pv']);
        $this->assertSame('live', $k['run_type']);
        $this->assertGreaterThan(0, (float) $k['commissions_total']);
        $this->assertEqualsWithDelta(array_sum(array_map('floatval', $k['commissions'])), (float) $k['commissions_total'], 0.001);
        $this->assertSame(['CRYSTAL' => 1, 'DIAMOND' => 1], $this->sorted($k['ranks']));
        $this->assertSame(round((float) $k['commissions_total'] / (float) $k['cv'] * 100, 2), $k['payout_ratio']);
    }

    /** @param array<string,int> $a */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    public function test_kpi_without_periods_is_empty_not_an_error(): void
    {
        $k = app(KpiService::class)->snapshot(null);
        $this->assertNull($k['period']);
        $this->assertNull($k['payout_ratio']);
    }

    public function test_dashboard_renders_for_board_but_not_for_a_plain_user(): void
    {
        $p = $this->openPeriod('2026-10');
        $this->sale($p, $this->consultant('A', null, 'DIAMOND'), '100');
        $this->actingAs(User::factory()->role('board')->create())->get('/admin')->assertOk()->assertSee('Cruscotto');
        auth()->logout();
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_roles_limit_what_each_role_can_do(): void
    {
        $this->plan();
        $matrix = [
            'board' => ['/consultants' => 200, '/comp-plan-versions' => 200, '/annual-fees' => 403, '/kyc-documents' => 403, '/pv-movements/create' => 403],
            'support' => ['/consultants' => 200, '/kyc-documents' => 200, '/periods' => 403, '/comp-plan-versions' => 403],
            'admin_finance' => ['/annual-fees' => 200, '/pv-movements/create' => 200, '/comp-plan-versions' => 403, '/consultants/create' => 403],
            'admin_network' => ['/consultants/create' => 200, '/kyc-documents' => 200, '/comp-plan-versions' => 403, '/annual-fees' => 403],
            'admin_compplan' => ['/comp-plan-versions/create' => 200, '/consultants/create' => 403, '/kyc-documents' => 403],
        ];
        foreach ($matrix as $role => $paths) {
            $this->actingAs(User::factory()->role($role)->create());
            foreach ($paths as $path => $code) {
                $this->get('/admin'.$path)->assertStatus($code, "{$role} {$path}");
            }
            auth()->logout();
        }
    }

    public function test_every_role_in_the_matrix_uses_declared_permissions(): void
    {
        foreach (Roles::MATRIX as $role => $perms) {
            foreach ($perms as $p) {
                $this->assertArrayHasKey($p, Roles::PERMISSIONS, "{$role}: permesso sconosciuto {$p}");
            }
        }
    }

    public function test_portal_roles_cannot_enter_backoffice(): void
    {
        $u = User::factory()->role('consultant')->create();
        $this->assertFalse($u->isBackoffice());
        $this->actingAs($u)->get('/admin')->assertForbidden();
    }

    public function test_two_factor_is_required_when_enabled_and_can_be_verified(): void
    {
        config(['lamess.admin_2fa_required' => true]);
        $u = User::factory()->role('support')->create();
        $this->assertSame('', (string) $u->app_authentication_secret);

        $app = AppAuthentication::make();
        $secret = $app->generateSecret();
        $app->saveSecret($u, $secret);
        $u->refresh();

        $this->assertNotEmpty($u->getAppAuthenticationSecret());
        $this->assertTrue($app->verifyCode($app->getCurrentCode($u), $u->getAppAuthenticationSecret()));
        $this->assertFalse($app->verifyCode('000000', $u->getAppAuthenticationSecret()));
        $this->assertNotSame($secret, $u->getRawOriginal('app_authentication_secret'), 'il segreto deve essere cifrato a riposo');
    }

    public function test_two_factor_requirement_follows_the_config_flag(): void
    {
        $panel = Filament::getPanel('admin');
        config(['lamess.admin_2fa_required' => true]);
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
        config(['lamess.admin_2fa_required' => false]);
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());
    }

    public function test_admin_user_command_assigns_role(): void
    {
        $this->artisan('lamess:admin-user', ['email' => 'x@example.test', '--password' => 'password-123', '--role' => 'admin_finance'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'x@example.test')->firstOrFail()->hasRole('admin_finance'));
        $this->artisan('lamess:ruoli')->assertSuccessful();
    }
}
