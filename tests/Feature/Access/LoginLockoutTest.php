<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.open' => true]);
    }

    public function test_repeated_wrong_passwords_lock_the_account_even_with_the_right_password(): void
    {
        $user = User::factory()->role('customer')->create(['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'sbagliata'])->assertSessionHasErrors('email');
        }

        $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Troppi tentativi', session('errors')->first('email'));
        $this->assertNotNull($user->fresh());
    }

    public function test_attempts_spread_over_many_ips_still_lock_the_same_email(): void
    {
        User::factory()->role('customer')->create(['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123']);

        for ($i = 1; $i <= 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'sbagliata']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_successful_login_clears_the_failures(): void
    {
        User::factory()->role('customer')->create(['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123']);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'sbagliata']);
        }
        $this->post('/accedi', ['email' => 'cliente@example.test', 'password' => 'Password-Giusta-123'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame(0, RateLimiter::attempts('login:email:'.sha1('cliente@example.test')));
    }
}
