<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Filament\Pages\PendingTransfersPage;
use App\Models\Order;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** La pagina «Bonifici in sospeso»: si apre, elenca gli ordini e le tre scelte funzionano dal pannello. */
final class PendingTransfersPageTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    private function pending(): Order
    {
        $this->plan();
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->shipping('0.00');
        $p = $this->product('P', '100.00');

        return app(\App\Domain\Orders\OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());
    }

    public function test_page_opens_for_super_admin_and_lists_the_pending_transfer(): void
    {
        $order = $this->pending();
        $this->actingAs(User::factory()->role('super_admin')->create());

        $this->get('/admin/bonifici-in-sospeso')->assertOk()->assertSee($order->number);
    }

    public function test_portal_users_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role('consultant')->create());

        $this->get('/admin/bonifici-in-sospeso')->assertForbidden();
    }

    public function test_defer_and_cancel_from_the_page(): void
    {
        $order = $this->pending();
        $this->actingAs(User::factory()->role('super_admin')->create());
        $code = Period::query()->latest('id')->value('code');

        Livewire::test(PendingTransfersPage::class, ['periodCode' => $code])
            ->callAction('defer', ['reason' => 'Bonifico in arrivo'], ['order' => $order->id])
            ->assertHasNoActionErrors();
        $this->assertNotNull($order->refresh()->deferred_to_period);

        $other = $this->pending2();
        Livewire::test(PendingTransfersPage::class, ['periodCode' => $code])
            ->callAction('cancel', ['reason' => 'Mai arrivato'], ['order' => $other->id])
            ->assertHasNoActionErrors();
        $this->assertSame('cancelled', $other->refresh()->status);
        $this->assertSame(0, PvMovement::where('order_id', $other->id)->where('status', '!=', 'cancelled')->count());
    }

    private function pending2(): Order
    {
        $p = \App\Models\Product::where('sku', 'P')->firstOrFail();

        return app(\App\Domain\Orders\OrderService::class)->place($this->buyer($this->activeSponsor('LM00002')), [$p->id => 1], 'IT', 'transfer', $this->billing());
    }
}
