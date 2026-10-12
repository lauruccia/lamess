<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Roles;
use App\Domain\Audit\AuditLog;
use App\Http\Controllers\InstallController;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-di-prova-molto-lungo-123456';

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(InstallController::lockPath());
    }

    protected function tearDown(): void
    {
        @unlink(InstallController::lockPath());
        parent::tearDown();
    }

    public function test_page_does_not_exist_without_token(): void
    {
        config(['lamess.install_token' => null]);
        $this->get('/installazione')->assertNotFound();
        config(['lamess.install_token' => 'corto']);
        $this->get('/installazione')->assertNotFound();
    }

    public function test_page_is_404_when_superadmin_exists(): void
    {
        config(['lamess.install_token' => self::TOKEN]);
        User::factory()->role(Roles::SUPER_ADMIN)->create();
        $this->get('/installazione')->assertNotFound();
        $this->post('/installazione', [])->assertNotFound();
    }

    public function test_wrong_token_does_not_install(): void
    {
        config(['lamess.install_token' => self::TOKEN]);
        $this->get('/installazione')->assertOk();
        $this->post('/installazione', ['token' => 'sbagliato', 'name' => 'L', 'email' => 'l@example.test', 'password' => 'Password-Lunga-123', 'password_confirmation' => 'Password-Lunga-123'])
            ->assertSessionHasErrors('token');
        $this->assertSame(0, User::count());
    }

    public function test_install_creates_superadmin_once_and_locks(): void
    {
        config(['lamess.install_token' => self::TOKEN]);
        $this->post('/installazione', ['token' => self::TOKEN, 'name' => 'Laura', 'email' => 'l@example.test', 'password' => 'Password-Lunga-123', 'password_confirmation' => 'Password-Lunga-123'])
            ->assertOk()->assertSee('Installazione completata');

        $this->assertTrue(User::withoutGlobalScopes()->where('email', 'l@example.test')->first()->isSuperAdmin());
        $this->assertFileExists(InstallController::lockPath());
        $this->assertTrue(AuditLog::verify()->ok);
        $this->assertSame('7.38', ShippingRate::where('country_code', 'IT')->firstOrFail()->amount_net, 'spedizione predefinita 9,00 € IVA inclusa');
        $this->get('/installazione')->assertNotFound();
    }
}
