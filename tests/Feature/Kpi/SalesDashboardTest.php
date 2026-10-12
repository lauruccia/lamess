<?php

declare(strict_types=1);

namespace Tests\Feature\Kpi;

use App\Domain\Access\Roles;
use App\Domain\Kpi\SalesDashboard;
use App\Filament\Pages\SalesDashboardPage;
use App\Models\Consultant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Cruscotto vendite: finestre e confronti, aggregazioni, accesso e pagina. Sola lettura. */
final class SalesDashboardTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private int $seq = 0;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-12 12:00:00');
    }

    private function product(string $name, ?int $categoryId = null): int
    {
        return (int) DB::table('products')->insertGetId([
            'sku' => 'SKU-'.(++$this->seq), 'name' => $name, 'net_price' => 100, 'vat_rate' => 22, 'pv' => 0, 'cv' => 0,
            'category_id' => $categoryId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $o */
    private function order(string $status, string $date, float $total, array $o = [], array $lines = []): int
    {
        $buyer = $o['buyer'] ?? User::factory()->create(['name' => $o['buyer_name'] ?? 'Cliente '.(++$this->seq)])->id;
        $id = (int) DB::table('orders')->insertGetId([
            'number' => 'ORD-'.(++$this->seq), 'buyer_user_id' => $buyer, 'buyer_type' => 'customer',
            'beneficiary_consultant_id' => $o['consultant'] ?? null, 'status' => $status, 'country_code' => 'IT',
            'payment_method' => $o['method'] ?? 'transfer', 'source' => $o['source'] ?? null,
            'subtotal_net' => round($total / 1.22, 2), 'vat_total' => round($total - $total / 1.22, 2), 'total' => $total,
            'billing' => '{}', 'created_at' => $date, 'updated_at' => $date,
        ]);
        foreach ($lines as [$productId, $name, $qty, $unitNet]) {
            DB::table('order_items')->insert([
                'order_id' => $id, 'product_id' => $productId, 'sku' => 'S', 'name' => $name, 'qty' => $qty, 'unit_net' => $unitNet, 'vat_rate' => 22,
            ]);
        }

        return $id;
    }

    private function seedSales(): void
    {
        $cat = (int) DB::table('product_categories')->insertGetId(['name' => 'Box Lamess', 'slug' => 'box', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        $box = $this->product('BOX COSMETIC COFFEE', $cat);
        $mug = $this->product('TAZZA');
        $agent = $this->consultant('A1');

        // nella finestra di 90 giorni (dal 15/07 al 12/10)
        $this->order('paid', '2026-09-10 10:00:00', 122.00, ['consultant' => $agent->id, 'source' => 'odoo', 'buyer_name' => 'Mario Rossi'], [[$box, 'BOX COSMETIC COFFEE', 2, 50]]);
        $this->order('shipped', '2026-09-20 10:00:00', 244.00, ['method' => 'card'], [[$mug, 'TAZZA', 2, 100]]);
        $this->order('pending_payment', '2026-10-01 10:00:00', 61.00);
        $this->order('cancelled', '2026-09-15 10:00:00', 999.00);
        $this->order('refunded', '2026-09-16 10:00:00', 888.00);
        // finestra precedente (dal 16/04 al 14/07)
        $this->order('paid', '2026-07-01 10:00:00', 100.00);
    }

    public function test_window_is_a_rolling_range_with_an_equal_previous_one(): void
    {
        $w = app(SalesDashboard::class)->window(90, 0, $this->now());
        $this->assertSame('2026-07-15 00:00:00', $w['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 23:59:59', $w['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-14 23:59:59', $w['prev_end']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-16 00:00:00', $w['prev_start']->format('Y-m-d H:i:s'));

        $back = app(SalesDashboard::class)->window(90, 1, $this->now());
        $this->assertSame('2026-07-14 23:59:59', $back['end']->format('Y-m-d H:i:s'));
    }

    public function test_all_history_window_starts_at_the_first_order_and_has_no_comparison(): void
    {
        $this->seedSales();
        $w = app(SalesDashboard::class)->window(0, 0, $this->now());
        $this->assertSame('2026-07-01', $w['start']->format('Y-m-d'));
        $this->assertNull($w['prev_start']);
    }

    public function test_delta_is_null_without_a_previous_value_and_otherwise_a_signed_percentage(): void
    {
        $s = app(SalesDashboard::class);
        $this->assertNull($s->delta(10, 0));
        $this->assertSame(100.0, $s->delta(200, 100));
        $this->assertSame(-50.0, $s->delta(50, 100));
        $this->assertSame(0.0, $s->delta(100, 100));
    }

    public function test_tiles_count_paid_and_pending_orders_and_ignore_cancelled_and_refunded(): void
    {
        $this->seedSales();
        $d = app(SalesDashboard::class)->build(90, 0, $this->now());

        $this->assertSame(1, $d['tiles']['quotes']['value']);
        $this->assertSame(2, $d['tiles']['orders']['value']);
        $this->assertEqualsWithDelta(366.0, $d['tiles']['revenue']['value'], 0.001);
        $this->assertEqualsWithDelta(183.0, $d['tiles']['avg']['value'], 0.001);
        // finestra precedente: 1 ordine da 100 €
        $this->assertSame(100.0, $d['tiles']['orders']['delta']);
        $this->assertSame(266.0, $d['tiles']['revenue']['delta']);
        $this->assertNull($d['tiles']['quotes']['delta']);
    }

    public function test_monthly_series_covers_every_month_even_without_sales(): void
    {
        $this->seedSales();
        $d = app(SalesDashboard::class)->build(90, 0, $this->now());

        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10'], array_column($d['monthly'], 'key'));
        $this->assertSame([0.0, 0.0, 366.0, 0.0], array_column($d['monthly'], 'revenue'));
        $this->assertCount(4, $d['chart']['points']);
    }

    public function test_rankings_group_by_product_category_seller_channel_and_payment(): void
    {
        $this->seedSales();
        $d = app(SalesDashboard::class)->build(90, 0, $this->now());

        $this->assertSame(244.0, $d['top_orders'][0]['total']);
        $this->assertSame('Vendita diretta', $d['top_orders'][0]['seller']);
        $this->assertSame('Mario Rossi', $d['top_orders'][1]['customer']);
        $this->assertCount(1, $d['top_quotes']);

        $this->assertSame(['TAZZA', 'BOX COSMETIC COFFEE'], array_column($d['top_products'], 'label'));
        $this->assertSame([244.0, 122.0], array_column($d['top_products'], 'revenue'));

        $this->assertSame(['Senza categoria', 'Box Lamess'], array_column($d['categories'], 'label'));
        $this->assertSame(66.7, $d['categories'][0]['share']);

        $this->assertEqualsCanonicalizing(['Vendita diretta', 'Incaricato A1'], array_column($d['seller_stats'], 'label'));
        $this->assertEqualsCanonicalizing(['Negozio online', 'Importati da Odoo'], array_column($d['channels'], 'label'));
        $this->assertEqualsCanonicalizing(['Bonifico', 'Carta'], array_column($d['payments'], 'label'));
        $this->assertSame(['IT'], array_column($d['countries'], 'label'));
    }

    public function test_refunded_quantities_reduce_product_revenue(): void
    {
        $p = $this->product('BOX');
        $id = $this->order('partially_refunded', '2026-09-10 10:00:00', 244.00, [], [[$p, 'BOX', 2, 100]]);
        DB::table('order_items')->where('order_id', $id)->update(['qty_refunded' => 1]);

        $d = app(SalesDashboard::class)->build(90, 0, $this->now());
        $this->assertSame(122.0, $d['top_products'][0]['revenue']);
        $this->assertSame(1, $d['top_products'][0]['qty']);
    }

    public function test_empty_database_builds_without_errors(): void
    {
        $d = app(SalesDashboard::class)->build(90, 0, $this->now());
        $this->assertSame(0, $d['tiles']['orders']['value']);
        $this->assertSame([], $d['top_orders']);
        $this->assertSame([], $d['categories']);
    }

    public function test_new_customers_use_the_registration_date_not_the_import_date(): void
    {
        $u = fn () => User::factory()->create()->id;
        // registrato nella finestra, ma importato dopo
        DB::table('customers')->insert(['user_id' => $u(), 'source' => 'referral', 'registered_at' => '2026-09-01 10:00:00', 'created_at' => '2026-10-05 10:00:00', 'updated_at' => '2026-10-05 10:00:00']);
        // registrato prima della finestra, importato dentro
        DB::table('customers')->insert(['user_id' => $u(), 'source' => 'referral', 'registered_at' => '2026-03-01 10:00:00', 'created_at' => '2026-10-05 10:00:00', 'updated_at' => '2026-10-05 10:00:00']);

        $d = app(SalesDashboard::class)->build(90, 0, $this->now());
        $this->assertSame(1, $d['extra']['new_customers']['value']);
    }

    public function test_sellers_are_attributed_from_the_order_or_else_from_the_customer(): void
    {
        $a = $this->consultant('A1');
        $b = $this->consultant('B1');
        $cu = User::factory()->create(['name' => 'Cliente di B']);
        DB::table('customers')->insert(['user_id' => $cu->id, 'assigned_consultant_id' => $b->id, 'source' => 'referral', 'registered_at' => '2026-09-05 10:00:00', 'created_at' => '2026-10-05', 'updated_at' => '2026-10-05']);
        $other = User::factory()->create();
        DB::table('customers')->insert(['user_id' => $other->id, 'assigned_consultant_id' => $b->id, 'source' => 'referral', 'registered_at' => '2026-03-05 10:00:00', 'created_at' => '2026-10-05', 'updated_at' => '2026-10-05']);

        // ordine senza incaricato: va a B perché il cliente è assegnato a B
        $this->order('paid', '2026-09-10 10:00:00', 100.0, ['buyer' => $cu->id]);
        // ordine con incaricato esplicito A (il cliente è di B): vince l'ordine
        $this->order('paid', '2026-09-11 10:00:00', 300.0, ['buyer' => $cu->id, 'consultant' => $a->id]);
        // ordine senza nessuno dei due: vendita diretta
        $this->order('paid', '2026-09-12 10:00:00', 50.0);
        // periodo precedente: A aveva 150
        $this->order('paid', '2026-06-01 10:00:00', 150.0, ['consultant' => $a->id]);

        $d = app(SalesDashboard::class)->build(90, 0, $this->now());
        $rows = collect($d['seller_stats'])->keyBy('label');

        $this->assertSame(300.0, $rows['Incaricato A1']['revenue']);
        $this->assertSame(100.0, $rows['Incaricato B1']['revenue']);
        $this->assertSame(50.0, $rows['Vendita diretta']['revenue']);
        $this->assertSame(100.0, $rows['Incaricato A1']['delta']); // 150 -> 300
        $this->assertSame(66.7, $rows['Incaricato A1']['share']);
        $this->assertSame(1, $rows['Incaricato B1']['new_customers']); // solo quello registrato nel periodo
        $this->assertSame(1, $rows['Incaricato B1']['customers']);
        $this->assertSame('Incaricato A1', $d['seller_highlights']['revenue']['label']);
        $this->assertSame('Incaricato B1', $d['seller_highlights']['new_customers']['label']);
        $this->assertSame(2, $d['sellers_with_sales']);

        $cust = collect($d['top_customers'])->firstWhere('label', 'Cliente di B');
        $this->assertSame('Incaricato B1', $cust['seller']); // l'incaricato del cliente, non quello del singolo ordine
        $this->assertSame(400.0, $cust['revenue']);
        $this->assertSame('Incaricato A1', $d['top_orders'][0]['seller']);
    }

    public function test_area_chart_geometry_and_nice_steps(): void
    {
        $this->assertSame(100.0, SalesDashboard::niceStep(90));
        $this->assertSame(5.0, SalesDashboard::niceStep(3.2));
        $this->assertSame(1.0, SalesDashboard::niceStep(0));

        $c = SalesDashboard::areaChart([0, 5000, 10000]);
        $this->assertCount(3, $c['points']);
        $this->assertSame(5, count($c['ticks']));
        $this->assertSame(10000.0, $c['ticks'][4]['value']);
        $this->assertStringStartsWith('M', $c['line']);
        $this->assertStringEndsWith('Z', $c['area']);
        $this->assertSame([], SalesDashboard::areaChart([])['points']);
    }

    public function test_super_admin_can_open_the_page_and_roles_without_the_kpi_permission_cannot(): void
    {
        Roles::sync();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $this->assertTrue(SalesDashboardPage::canAccess());
        $this->actingAs(User::factory()->role('support')->create());
        $this->assertFalse(SalesDashboardPage::canAccess());
    }

    public function test_page_renders_with_data_and_the_period_controls_work(): void
    {
        $this->seedSales();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        Livewire::test(SalesDashboardPage::class)
            ->assertSuccessful()->assertSee('Cruscotto vendite')->assertSee('Vendite mensili')->assertSee('Prodotti principali')
            ->assertSee('Preventivi migliori')->assertSee('Performance incaricati')
            ->call('previous')->assertSet('offset', 1)
            ->call('next')->call('next')->assertSet('offset', 0)
            ->set('days', 0)->assertSuccessful()->assertSet('offset', 0)
            ->call('previous')->assertSet('offset', 0)
            ->set('days', 7)->assertSet('days', 90)
            ->set('days', 30)->assertSuccessful();
    }

    public function test_page_renders_on_an_empty_database(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        Livewire::test(SalesDashboardPage::class)->assertSuccessful()->assertSee('Nessun ordine pagato nel periodo');
    }

    public function test_the_dashboard_does_not_write_anything(): void
    {
        $this->seedSales();
        $before = [DB::table('orders')->count(), DB::table('order_items')->count(), Consultant::count()];
        app(SalesDashboard::class)->build(365, 0, $this->now());
        $this->assertSame($before, [DB::table('orders')->count(), DB::table('order_items')->count(), Consultant::count()]);
    }
}
