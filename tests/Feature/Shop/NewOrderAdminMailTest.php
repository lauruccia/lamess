<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Access\Roles;
use App\Domain\Orders\OrderService;
use App\Models\User;
use App\Notifications\NewOrderAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Avviso «Nuovo ordine» all'amministrazione: admin e super admin, oppure l'elenco in LAMESS_ORDINI_MAIL_TO. */
final class NewOrderAdminMailTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function admin(string $role, string $email): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['email' => $email]);
        $u->assignRole($role);

        return $u;
    }

    public function test_new_order_mail_goes_to_admins_and_super_admins(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true, 'lamess.orders.admin_mail_to' => '']);
        $this->plan();
        $this->shipping('0.00');
        $this->admin(Roles::ADMIN, 'admin@example.test');
        $this->admin(Roles::SUPER_ADMIN, 'super@example.test');
        $p = $this->product('A', '123.00');

        $o = app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());

        Notification::assertSentOnDemand(NewOrderAdmin::class, function ($n, $channels, $notifiable) use ($o) {
            $to = $notifiable->routes['mail'];
            sort($to);

            return $n->order->is($o) && $to === ['admin@example.test', 'super@example.test'];
        });
    }

    public function test_configured_list_replaces_admin_users_and_switch_off_silences_it(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true, 'lamess.orders.admin_mail_to' => 'ordini@example.test, magazzino@example.test']);
        $this->plan();
        $this->shipping('0.00');
        $this->admin(Roles::ADMIN, 'admin@example.test');
        $p = $this->product('A', '123.00');
        $svc = app(OrderService::class);

        $svc->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
        Notification::assertSentOnDemand(NewOrderAdmin::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === ['ordini@example.test', 'magazzino@example.test']);

        Notification::fake();
        config(['lamess.mail_enabled' => false]);
        $svc->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
        Notification::assertNothingSent();
    }
}
