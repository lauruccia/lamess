<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Access\Roles;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Fase 2 — parametri commissionali per prodotto. */
final class ProductCommissionParamsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    public function test_defaults_keep_the_standard_calculation(): void
    {
        $p = $this->product('A', '123.00');
        $this->assertSame('net', $p->fresh()->commission_base);
        $this->assertSame('100.00', (string) $p->fresh()->pvUnit());
    }

    public function test_gross_base_uses_price_with_vat(): void
    {
        $p = $this->product('G', '100.00', ['commission_base' => 'gross']);
        // 100 + 22% = 122 → 122 / 1,23 = 99,19
        $this->assertSame('99.19', (string) $p->fresh()->pvUnit());
    }

    public function test_commissionable_share_reduces_automatic_pv(): void
    {
        $p = $this->product('Q', '123.00', ['commissionable_pct' => '50']);
        $this->assertSame('50.00', (string) $p->fresh()->pvUnit());
    }

    public function test_fixed_pv_ignores_base_and_share(): void
    {
        $p = $this->product('F', '123.00', ['pv' => '70', 'commission_base' => 'gross', 'commissionable_pct' => '10']);
        $this->assertSame('70.00', (string) $p->fresh()->pvUnit());
    }

    public function test_parameter_changes_are_logged_with_previous_and_new_values(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $p = $this->product('L', '100.00');
        Livewire::test(EditProduct::class, ['record' => $p->getKey()])
            ->fillForm(['commission_base' => 'gross', 'commissionable_pct' => '80'])->call('save')->assertHasNoFormErrors();

        $row = AuditEntry::where('action', 'product.updated')->latest('id')->firstOrFail();
        $c = is_string($row->changes) ? json_decode($row->changes, true) : $row->changes;
        $this->assertEquals(['prima' => 'net', 'dopo' => 'gross'], $c['commission_base']);
        $this->assertEquals('100.00', $c['commissionable_pct']['prima']);
        $this->assertEquals('80.00', $c['commissionable_pct']['dopo']);
    }
}
