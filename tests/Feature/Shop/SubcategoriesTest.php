<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\CartPricer;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class SubcategoriesTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function admin(): User
    {
        $u = User::factory()->role('admin_finance')->create();
        $this->actingAs($u);

        return $u;
    }

    public function test_a_subcategory_has_one_level_only(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $this->assertSame('Viso › Creme', $creme->fresh()->fullName());

        try {
            ProductCategory::create(['name' => 'Notte', 'parent_id' => $creme->id]);
            $this->fail('Una sottocategoria non può avere figlie.');
        } catch (DomainException) {
            $this->assertSame(2, ProductCategory::count());
        }

        $this->expectException(DomainException::class);
        $viso->update(['parent_id' => $viso->id]);
    }

    public function test_a_category_with_subcategories_cannot_become_one(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $corpo = ProductCategory::create(['name' => 'Corpo']);

        $this->expectException(DomainException::class);
        $viso->update(['parent_id' => $corpo->id]);
    }

    public function test_shop_nests_subcategories_and_parent_shows_their_products(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $this->product('A', '10.00', ['name' => 'Siero diretto', 'category_id' => $viso->id]);
        $this->product('B', '10.00', ['name' => 'Crema notte', 'category_id' => $creme->id]);
        $this->product('C', '10.00', ['name' => 'Sapone corpo']);

        $this->get('/negozio?categoria='.$viso->slug)->assertOk()
            ->assertSee('Siero diretto')->assertSee('Crema notte')->assertDontSee('Sapone corpo')
            ->assertSee('Sottocategorie di Viso');
        $this->get('/negozio?categoria='.$creme->slug)->assertOk()
            ->assertSee('Crema notte')->assertDontSee('Siero diretto');
    }

    public function test_parent_without_own_products_still_appears_through_its_subcategories(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        ProductCategory::create(['name' => 'Vuota', 'parent_id' => $viso->id]);
        $this->product('B', '10.00', ['name' => 'Crema notte', 'category_id' => $creme->id]);

        $this->get('/negozio')->assertOk()->assertSee('Viso')->assertSee('Creme')->assertDontSee('Vuota');
    }

    public function test_hidden_parent_hides_its_subcategories(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso', 'active' => false]);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $this->product('B', '10.00', ['name' => 'Crema notte', 'category_id' => $creme->id]);

        $this->assertFalse($creme->fresh()->isShownInShop());
        $this->get('/negozio')->assertOk()->assertDontSee('categoria='.$creme->slug, false)->assertDontSee('categoria='.$viso->slug, false);
        $this->get('/negozio?categoria='.$creme->slug)->assertOk()->assertSee('Crema notte')->assertDontSee('cat-chips', false); // filtro ignorato: vetrina completa
    }

    public function test_product_page_shows_the_parent_in_breadcrumbs(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $p = $this->product('B', '10.00', ['name' => 'Crema notte', 'category_id' => $creme->id]);

        $this->get('/negozio/'.$p->slug)->assertOk()->assertSee('/negozio?categoria='.$viso->slug, false)->assertSee('/negozio?categoria='.$creme->slug, false);
    }

    public function test_discount_on_a_category_also_covers_its_subcategories(): void
    {
        $this->plan();
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $corpo = ProductCategory::create(['name' => 'Corpo']);
        $inSub = $this->product('A', '100.00', ['category_id' => $creme->id]);
        $outside = $this->product('B', '100.00', ['category_id' => $corpo->id]);
        Discount::create(['name' => 'Viso', 'code' => 'VISO', 'type' => 'percent', 'value' => '10', 'active' => true, 'applies_to' => 'categories', 'category_ids' => [$viso->id]]);

        $cart = app(CartPricer::class)->price([
            (string) $inSub->id => ['product' => $inSub, 'qty' => 1],
            (string) $outside->id => ['product' => $outside, 'qty' => 1],
        ], $this->buyer($this->activeSponsor()), ['VISO'], null, 'IT', false);

        $this->assertSame('90.00000000', (string) $cart->lines[(string) $inSub->id]->unitNet);
        $this->assertSame('100.00000000', (string) $cart->lines[(string) $outside->id]->unitNet);
    }

    public function test_admin_creates_a_subcategory_and_lists_it_under_its_parent(): void
    {
        $this->plan();
        $this->admin();
        $viso = ProductCategory::create(['name' => 'Viso', 'sort_order' => 5]);
        $corpo = ProductCategory::create(['name' => 'Corpo', 'sort_order' => 1]);

        Livewire::test(CreateProductCategory::class)->fillForm(['name' => 'Creme', 'parent_id' => $viso->id, 'active' => true])->call('create')->assertHasNoFormErrors();
        $creme = ProductCategory::where('name', 'Creme')->firstOrFail();
        $this->assertSame($viso->id, $creme->parent_id);

        Livewire::test(ListProductCategories::class)->assertCanSeeTableRecords([$corpo, $viso, $creme], inOrder: true);
        $this->assertSame(['Corpo', 'Viso', 'Creme'], ProductCategory::inTreeOrder()->pluck('name')->all());
    }

    public function test_add_subcategory_action_opens_the_form_with_the_parent_selected(): void
    {
        $this->plan();
        $this->admin();
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);

        Livewire::test(ListProductCategories::class)
            ->assertTableActionVisible('addSubcategory', $viso)
            ->assertTableActionHidden('addSubcategory', $creme);
        $this->get('/admin/product-categories/create?parent='.$viso->id)->assertOk();
    }

    public function test_category_with_subcategories_cannot_be_deleted_from_admin(): void
    {
        $this->plan();
        $this->admin();
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);

        Livewire::test(EditProductCategory::class, ['record' => $viso->getKey()])->callAction('delete');
        $this->assertNotNull(ProductCategory::find($viso->id));

        Livewire::test(EditProductCategory::class, ['record' => $creme->getKey()])->callAction('delete');
        $this->assertNull(ProductCategory::find($creme->id));
        Livewire::test(EditProductCategory::class, ['record' => $viso->getKey()])->callAction('delete');
        $this->assertNull(ProductCategory::find($viso->id));
    }

    public function test_parent_field_excludes_the_record_itself_and_other_subcategories(): void
    {
        $viso = ProductCategory::create(['name' => 'Viso']);
        $creme = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);
        $corpo = ProductCategory::create(['name' => 'Corpo']);

        $this->assertSame([$viso->id => 'Viso'], ProductCategory::rootOptions($corpo->id));
        $this->assertSame([$corpo->id => 'Corpo', $viso->id => 'Viso'], ProductCategory::rootOptions());
        $this->assertSame('Viso › Creme', ProductCategory::treeOptions()[$creme->id]);
    }

    public function test_category_slug_is_unique_across_levels(): void
    {
        $viso = ProductCategory::create(['name' => 'Creme']);
        $sub = ProductCategory::create(['name' => 'Creme', 'parent_id' => $viso->id]);

        $this->assertNotSame($viso->slug, $sub->slug);
        $this->assertInstanceOf(Product::class, $this->product('A', '1.00', ['category_id' => $sub->id]));
    }
}
