<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Il modulo di modifica automatico mostra testi italiani, menu e righe, non campi grezzi e JSON. */
final class GenericFormTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function subscription(): Subscription
    {
        $buyer = User::factory()->create();
        $p = $this->product('A', '10.00');

        return Subscription::create([
            'user_id' => $buyer->id, 'status' => 'active', 'lines' => [$p->id => 2], 'country_code' => 'IT', 'billing' => $this->billing(),
            'payment_method' => 'card', 'started_at' => now(), 'next_run_at' => now(),
        ]);
    }

    public function test_edit_page_uses_italian_labels_instead_of_column_names(): void
    {
        $sub = $this->subscription();
        $this->actingAs(User::factory()->role('super_admin')->create());

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $sub]))
            ->assertOk()
            ->assertSee('Utente')
            ->assertSee('Prodotti')
            ->assertSee('Ordine per altri destinatari')
            ->assertDontSee('User Id')
            ->assertDontSee('For Others');
    }

    public function test_saving_keeps_the_products_in_the_original_format_and_applies_the_new_status(): void
    {
        $sub = $this->subscription();
        $original = $sub->lines;
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(EditSubscription::class, ['record' => $sub->getKey()])
            ->fillForm(['status' => 'paused'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $sub->fresh();
        $this->assertSame('paused', $fresh->status);
        $this->assertEquals($original, $fresh->lines);
        $this->assertNull($fresh->for_others);
    }
}
