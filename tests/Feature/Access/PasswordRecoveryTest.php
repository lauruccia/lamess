<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_form_is_available_and_linked_from_login(): void
    {
        $this->get('/accedi')->assertOk()->assertSee('Password dimenticata');
        $this->get('/password-dimenticata')->assertOk();
    }

    public function test_answer_is_identical_for_known_and_unknown_email(): void
    {
        User::factory()->create(['email' => 'noto@example.test']);
        $a = $this->post('/password-dimenticata', ['email' => 'noto@example.test']);
        $b = $this->post('/password-dimenticata', ['email' => 'ignoto@example.test']);

        $this->assertSame($a->getStatusCode(), $b->getStatusCode());
        $this->assertSame(session('status'), $b->getSession()->get('status'));
    }

    public function test_reset_with_valid_token_changes_password(): void
    {
        $u = User::factory()->create();
        $token = Password::createToken($u);

        $this->post('/password-reset', ['token' => $token, 'email' => $u->email, 'password' => 'NuovaPassword-123', 'password_confirmation' => 'NuovaPassword-123'])
            ->assertRedirect();
        $this->assertTrue(Hash::check('NuovaPassword-123', $u->fresh()->password));
    }

    public function test_reset_with_wrong_token_is_refused(): void
    {
        $u = User::factory()->create();
        $this->post('/password-reset', ['token' => 'sbagliato', 'email' => $u->email, 'password' => 'NuovaPassword-123', 'password_confirmation' => 'NuovaPassword-123'])
            ->assertSessionHasErrors();
        $this->assertTrue(Hash::check('password', $u->fresh()->password));
    }

    public function test_forgot_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/password-dimenticata', ['email' => 'x@example.test']);
        }
        $this->post('/password-dimenticata', ['email' => 'x@example.test'])->assertStatus(429);
    }
}
