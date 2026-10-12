<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class CatalogImportTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function csv(string $body): string
    {
        $f = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($f, "sku;nome;prezzo_ivato;pv;attivo;note\n".$body);

        return $f;
    }

    public function test_import_converts_gross_prices_keeps_explicit_pv_and_is_idempotent(): void
    {
        $f = $this->csv("A1;Box;159,90;100;1;\nA2;Kit;610.00;400;1;nota\nA3;Reseller;63.00;0;0;");
        $imp = app(CatalogImporter::class);
        $this->assertSame(['created' => 3, 'updated' => 0, 'rows' => 3], $imp->import($f));
        $this->assertSame(['created' => 0, 'updated' => 3, 'rows' => 3], $imp->import($f));

        $this->assertSame(3, Product::count());
        $box = Product::where('sku', 'A1')->firstOrFail();
        $this->assertSame('131.06557377', $box->net_price);
        $this->assertSame('100.00', $box->pvUnit());
        $this->assertSame('500.00000000', Product::where('sku', 'A2')->firstOrFail()->net_price);
        $this->assertSame('nota', Product::where('sku', 'A2')->firstOrFail()->description);
        $this->assertFalse(Product::where('sku', 'A3')->firstOrFail()->active);
        $this->assertSame('0.00', Product::where('sku', 'A3')->firstOrFail()->pvUnit(), 'PV zero esplicito, non calcolato');
    }

    public function test_net_flag_and_dry_run(): void
    {
        $f = $this->csv('N1;Netto;100.00;81;1;');
        app(CatalogImporter::class)->import($f, grossPrices: false, dryRun: true);
        $this->assertSame(0, Product::count());
        app(CatalogImporter::class)->import($f, grossPrices: false);
        $this->assertSame('100.00000000', Product::firstOrFail()->net_price);
    }

    public function test_import_rejects_bad_files(): void
    {
        $imp = app(CatalogImporter::class);
        foreach ([['X;Nome;abc;1;1;', 'non validi'], ['X;;10;1;1;', 'obbligatori'], ["X;A;10;1;1;\nX;B;10;1;1;", 'duplicato']] as [$body, $msg]) {
            try {
                $imp->import($this->csv($body));
                $this->fail("atteso: {$msg}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $imp->import(tempnam(sys_get_temp_dir(), 'x'));
    }

    public function test_the_real_catalog_file_imports_cleanly(): void
    {
        $r = app(CatalogImporter::class)->import(database_path('data/catalogo-odoo.csv'));
        $this->assertSame(35, $r['rows']);
        $this->assertSame(35, Product::count());
        $kit = Product::where('sku', 'LM-KIT-GOLD')->firstOrFail();
        $this->assertSame('2000.00000000', $kit->net_price);
        $this->assertSame('1600.00', $kit->pvUnit());
    }

    public function test_gross_listing_price_is_what_the_customer_pays_to_the_cent(): void
    {
        $this->plan();
        app(CatalogImporter::class)->import($this->csv("B1;Box;159.90;100;1;\nB2;Kit;610.00;400;1;"));
        $box = Product::where('sku', 'B1')->firstOrFail();
        $kit = Product::where('sku', 'B2')->firstOrFail();
        $svc = app(OrderService::class);
        $u = $this->buyer($this->activeSponsor());

        $o = $svc->place($u, [$box->id => 3, $kit->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertSame('1089.70', $o->total, '3 × 159,90 + 610,00 IVA inclusa');
        $this->assertSame('893.20', $o->subtotal_net);
        $this->assertSame('700.00', $o->pv_total);
        $one = $svc->place($u, [$box->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertSame('159.90', $one->total);
    }

    public function test_zero_price_products_are_not_directly_purchasable_nor_listed(): void
    {
        $this->plan();
        $free = $this->product('FREE', '0.00', ['name' => 'Omaggio Tazza']);
        $this->get('/negozio')->assertOk()->assertDontSee('Omaggio Tazza');
        $this->expectException(OrderException::class);
        app(OrderService::class)->place($this->buyer(), [$free->id => 1], 'IT', 'transfer', $this->billing());
    }
}
