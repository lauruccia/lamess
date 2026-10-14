<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Orders\OrderService;
use App\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Filament\Resources\Discounts\Pages\ListDiscounts;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class ShopCatalogAdminTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function admin(string $role = 'admin_finance'): User
    {
        $u = User::factory()->role($role)->create();
        $this->actingAs($u);

        return $u;
    }

    public function test_pages_render_for_a_catalog_admin(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('A', '100.00');
        $d = Discount::create(['name' => 'X', 'type' => 'percent', 'value' => 10, 'code' => 'X']);
        foreach (['/products', '/products/create', "/products/{$p->id}/edit", '/product-categories', '/product-categories/create', '/discounts', '/discounts/create', "/discounts/{$d->id}/edit"] as $path) {
            $this->get('/admin'.$path)->assertOk();
        }
    }

    public function test_product_form_shows_the_commission_and_duration_data_in_the_main_section(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('BOX', '122.95081967', ['vap_mode' => Product::VAP_PERCENT, 'vap_value' => '60', 'customer_count' => 5, 'customer_validity_months' => 12]);

        Livewire::test(EditProduct::class, ['record' => $p->getKey()])
            ->assertSee('Commissioni e durata (Piano Incaricati)')
            ->assertSee('Quanti clienti vale')->assertSee('Durata (mesi)')
            ->assertSee('V.A.P. 60 % di 150,00 € = 90,00 €. Vale 5 clienti per 12 mesi.')
            ->assertFormSet(['vap_mode' => Product::VAP_PERCENT, 'vap_value' => '60.0000', 'customer_count' => 5, 'customer_validity_months' => 12])
            ->fillForm(['vap_mode' => Product::VAP_FIXED, 'vap_value' => '40', 'customer_count' => 1, 'customer_validity_months' => 3])
            ->call('save')->assertHasNoFormErrors();

        $p->refresh();
        $this->assertSame(Product::VAP_FIXED, $p->vap_mode);
        $this->assertSame([Product::VAP_FIXED, '40.0000'], $p->vapSetting());
        $this->assertSame(3, $p->customerValidityMonths());
    }

    public function test_product_summary_without_vap_follows_the_plan(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('BOX2', '122.95081967');

        Livewire::test(EditProduct::class, ['record' => $p->getKey()])->assertSee('V.A.P. come da piano')->assertSee('Vale 1 cliente per 1 mese.');
    }

    public function test_roles_without_catalog_permission_cannot_open_shop_admin(): void
    {
        $this->plan();
        $this->admin('board');
        foreach (['/products', '/product-categories', '/discounts'] as $path) {
            $this->get('/admin'.$path)->assertForbidden();
        }
    }

    public function test_product_gets_price_from_gross_slug_images_and_stock(): void
    {
        Storage::fake('local');
        $this->plan();
        $this->admin();
        $cat = ProductCategory::create(['name' => 'Viso']);

        Livewire::test(CreateProduct::class)->fillForm([
            'sku' => 'CR1', 'name' => 'Crema Viso Notte', 'net_price' => '131.06557377', 'vat_rate' => '22', 'active' => true,
            'category_id' => $cat->id, 'track_stock' => true, 'stock_qty' => 12, 'images' => [UploadedFile::fake()->image('a.jpg', 600, 600)],
        ])->call('create')->assertHasNoFormErrors();

        $p = Product::where('sku', 'CR1')->firstOrFail();
        $this->assertSame('crema-viso-notte', $p->slug);
        $this->assertSame('159.90', $p->grossPrice());
        $this->assertSame(12, $p->stock_qty);
        $this->assertCount(1, $p->imagePaths());
        Storage::disk('local')->assertExists($p->imagePaths()[0]);
    }

    public function test_net_price_field_fills_the_gross_price_shown_to_the_customer(): void
    {
        $this->plan();
        $this->admin();

        Livewire::test(CreateProduct::class)->fillForm(['sku' => 'G1', 'name' => 'Da netto', 'vat_rate' => '22'])
            ->set('data.net_price', '131.07')->assertSet('data.gross_price', '159.91')
            ->set('data.vat_rate', '10')->assertSet('data.gross_price', '144.18');
    }

    public function test_untracked_stock_is_saved_as_null_and_compare_price_must_be_higher(): void
    {
        $this->plan();
        $this->admin();

        Livewire::test(CreateProduct::class)->fillForm(['sku' => 'S1', 'name' => 'Senza stock', 'net_price' => '100', 'vat_rate' => '22', 'track_stock' => false, 'stock_qty' => 5, 'compare_at_net' => '80'])
            ->call('create')->assertHasFormErrors(['compare_at_net']);
        Livewire::test(CreateProduct::class)->fillForm(['sku' => 'S1', 'name' => 'Senza stock', 'net_price' => '100', 'vat_rate' => '22', 'track_stock' => false, 'stock_qty' => 5, 'compare_at_net' => '150'])
            ->call('create')->assertHasNoFormErrors();
        $p = Product::where('sku', 'S1')->firstOrFail();
        $this->assertNull($p->stock_qty);
        $this->assertSame('183.00', $p->compareAtGross());
        $this->assertSame(33, $p->savingPercent()); // 122 contro 183
    }

    public function test_products_can_be_hidden_and_shown_from_the_list_and_in_bulk(): void
    {
        $this->plan();
        $this->admin();
        $a = $this->product('A', '100.00');
        $b = $this->product('B', '100.00');

        Livewire::test(ListProducts::class)->call('updateTableColumnState', 'active', (string) $a->getKey(), false);
        $this->assertFalse($a->fresh()->active);
        Livewire::test(ListProducts::class)->call('updateTableColumnState', 'active', (string) $a->getKey(), true);
        $this->assertTrue($a->fresh()->active);

        Livewire::test(ListProducts::class)->callTableBulkAction('hide', [$a, $b]);
        $this->assertFalse($a->fresh()->active);
        $this->assertFalse($b->fresh()->active);
        Livewire::test(ListProducts::class)->callTableBulkAction('publish', [$a]);
        $this->assertTrue($a->fresh()->active);
        $this->get('/negozio')->assertSee($a->name)->assertDontSee($b->name);
    }

    public function test_duplicate_creates_a_hidden_copy_and_sold_products_cannot_be_deleted(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('A', '100.00', ['stock_qty' => 4]);

        Livewire::test(ListProducts::class)->callTableAction('replicate', $p);
        $copy = Product::where('id', '!=', $p->id)->firstOrFail();
        $this->assertFalse($copy->active);
        $this->assertNotSame($p->sku, $copy->sku);
        $this->assertNotSame($p->slug, $copy->slug);
        $this->assertTrue($copy->isDeletable());

        $this->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertFalse($p->fresh()->isDeletable());
        Livewire::test(EditProduct::class, ['record' => $p->getKey()])->assertActionHidden('delete');
    }

    public function test_category_can_be_created(): void
    {
        $this->plan();
        $this->admin();

        Livewire::test(CreateProductCategory::class)->fillForm(['name' => 'Corpo e Bagno', 'active' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame('corpo-e-bagno', ProductCategory::firstOrFail()->slug);
    }

    public function test_discount_with_code_and_automatic_tiered_discount_can_be_created(): void
    {
        $this->plan();
        $this->admin();

        Livewire::test(CreateDiscount::class)->fillForm(['name' => 'Benvenuto', 'type' => 'percent', 'method' => 'code', 'code' => 'ciao 10', 'value' => 10, 'audience' => 'all', 'applies_to' => 'all', 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('CIAO10', Discount::where('name', 'Benvenuto')->firstOrFail()->code);

        Livewire::test(CreateDiscount::class)->fillForm([
            'name' => 'Più compri', 'type' => 'tiered', 'method' => 'automatic', 'audience' => 'all', 'applies_to' => 'all', 'active' => true,
            'tiers' => [['min_qty' => 3, 'percent' => 10], ['min_qty' => 6, 'percent' => 15]],
        ])->call('create')->assertHasNoFormErrors();
        $t = Discount::where('name', 'Più compri')->firstOrFail();
        $this->assertNull($t->code);
        $this->assertCount(2, $t->tiers);
    }

    public function test_switching_a_discount_to_automatic_clears_its_code(): void
    {
        $this->plan();
        $this->admin();
        $d = Discount::create(['name' => 'X', 'type' => 'percent', 'value' => 10, 'code' => 'VECCHIO']);

        Livewire::test(EditDiscount::class, ['record' => $d->getKey()])->fillForm(['method' => 'automatic'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($d->fresh()->code);
    }

    public function test_discount_form_rejects_percent_over_100_and_duplicate_codes(): void
    {
        $this->plan();
        $this->admin();
        Discount::create(['name' => 'A', 'type' => 'percent', 'value' => 10, 'code' => 'DOPPIO']);

        Livewire::test(CreateDiscount::class)->fillForm(['name' => 'B', 'type' => 'percent', 'method' => 'code', 'code' => 'DOPPIO', 'value' => 150, 'audience' => 'all', 'applies_to' => 'all'])
            ->call('create')->assertHasFormErrors(['code', 'value']);
    }

    public function test_discount_list_shows_status_and_can_toggle(): void
    {
        $this->plan();
        $this->admin();
        $d = Discount::create(['name' => 'Scaduto', 'type' => 'percent', 'value' => 10, 'code' => 'OLD', 'ends_at' => now()->subDay()])->fresh();

        $this->assertSame('Scaduto', $d->statusLabel());
        Livewire::test(ListDiscounts::class)->assertCanSeeTableRecords([$d])->assertSee('Scaduto');
    }

    public function test_variant_combinations_are_generated_without_touching_existing_ones(): void
    {
        $options = [['name' => 'Formato', 'values' => ['50 bustine', '100 bustine']], ['name' => 'Gusto', 'values' => ['Limone', 'Arancia']]];

        [$rows, $added, $tooMany] = ProductResource::generateVariantRows($options, [], 'INT', '10.00', 22);
        $this->assertSame(4, $added);
        $this->assertFalse($tooMany);
        $labels = collect($rows)->pluck('variant_label')->all();
        $this->assertContains('100 bustine / Arancia', $labels);
        $this->assertSame('INT-50-BUSTINE-LIMONE', collect($rows)->firstWhere('variant_label', '50 bustine / Limone')['sku']);
        $this->assertSame('10.00', collect($rows)->first()['net_price']);
        $this->assertSame('12.20', collect($rows)->first()['price_gross']);

        $options[1]['values'][] = 'Menta';
        [$more, $addedNow] = ProductResource::generateVariantRows($options, $rows, 'INT', '10.00', 22);
        $this->assertSame(2, $addedNow);
        $this->assertCount(6, $more);
        $this->assertSame(array_keys($rows), array_slice(array_keys($more), 0, 4));

        [, , $tooMany] = ProductResource::generateVariantRows([['name' => 'A', 'values' => range(1, 10)], ['name' => 'B', 'values' => range(1, 10)]], [], 'X', '');
        $this->assertTrue($tooMany);
    }

    public function test_variants_are_saved_from_the_product_form_with_net_prices(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('INT', '10.00', ['name' => 'Integratore']);

        Livewire::test(EditProduct::class, ['record' => $p->getKey()])
            ->fillForm([
                'options' => [['name' => 'Formato', 'values' => ['50 bustine', '100 bustine']]],
                'variants' => [
                    'new-1' => ['variant_options' => ['Formato' => '50 bustine'], 'variant_label' => '50 bustine', 'sku' => 'INT-50', 'net_price' => '10.00', 'stock_qty' => '7', 'active' => true],
                    'new-2' => ['variant_options' => ['Formato' => '100 bustine'], 'variant_label' => '100 bustine', 'sku' => '', 'net_price' => '18.00', 'compare_at_net' => '20.00', 'stock_qty' => '', 'active' => true],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $variants = $p->fresh()->variants;
        $this->assertCount(2, $variants);
        $this->assertSame('10.00000000', (string) $variants[0]->net_price);
        $this->assertSame(7, $variants[0]->stock_qty);
        $this->assertSame('18.00000000', (string) $variants[1]->net_price);
        $this->assertSame('20.00000000', (string) $variants[1]->compare_at_net);
        $this->assertNull($variants[1]->stock_qty);
        $this->assertSame('Integratore — 100 bustine', $variants[1]->name);
        $this->assertSame('INT-100-BUSTINE', $variants[1]->sku);
        $this->assertSame([['name' => 'Formato', 'values' => ['50 bustine', '100 bustine']]], $p->fresh()->options);
    }

    public function test_product_list_shows_only_main_products_and_duplicate_copies_the_variants_hidden(): void
    {
        $this->plan();
        $this->admin();
        $p = $this->product('INT', '10.00', ['name' => 'Integratore']);
        $v = Product::create(['parent_id' => $p->id, 'variant_options' => ['Formato' => '1 kg'], 'net_price' => '30.00', 'stock_qty' => 5]);

        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$p])->assertCanNotSeeTableRecords([$v])
            ->callTableAction('replicate', $p);

        $copy = Product::whereNull('parent_id')->where('id', '!=', $p->id)->firstOrFail();
        $this->assertFalse($copy->active);
        $this->assertCount(1, $copy->variants);
        $this->assertFalse($copy->variants[0]->active);
        $this->assertSame(0, $copy->variants[0]->stock_qty);
        $this->assertNotSame($v->sku, $copy->variants[0]->sku);
    }
}
