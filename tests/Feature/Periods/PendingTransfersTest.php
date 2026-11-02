<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Orders\OrderService;
use App\Domain\Periods\PendingTransfers;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Period;
use App\Models\PvMovement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Controllo dei bonifici in sospeso a fine mese: ordine il 31/10, bonifico il 2/11. */
final class PendingTransfersTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function goTo(string $local): void
    {
        $at = CarbonImmutable::parse($local, 'Europe/Rome');
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /** Bonifico ordinato il 31/10 alle 23:50, mese di ottobre ancora aperto; poi è il 2/11 alle 11:00. */
    private function orderOnTheLastDay(): Order
    {
        $this->goTo('2026-10-31 23:50');
        $this->plan();
        $this->openPeriod('2026-10');
        $this->shipping('0.00');
        $p = $this->product('P', '100.00');
        $order = app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->goTo('2026-11-02 11:00');

        return $order;
    }

    private function october(): Period
    {
        return Period::where('code', '2026-10')->firstOrFail();
    }

    private function service(): PendingTransfers
    {
        return app(PendingTransfers::class);
    }

    public function test_plain_confirmation_after_month_end_counts_in_the_month_of_the_click(): void
    {
        $order = $this->orderOnTheLastDay();

        app(OrderService::class)->markPaid($order);

        $periods = PvMovement::where('order_id', $order->id)->where('status', 'confirmed')->with('period')->get()->pluck('period.code')->unique()->all();
        $this->assertSame(['2026-11'], $periods);
    }

    public function test_confirming_in_the_order_month_keeps_real_payment_and_invoice_dates(): void
    {
        $order = $this->orderOnTheLastDay();

        $paid = $this->service()->confirmInPeriod($order, $this->october(), 'Bonifico del 31/10 accreditato il 2/11', 'CRO123');

        $this->assertSame('paid', $paid->status);
        $moves = PvMovement::where('order_id', $order->id)->get();
        $this->assertGreaterThan(0, $moves->count());
        foreach ($moves as $m) {
            $this->assertSame('confirmed', $m->status);
            $this->assertSame($this->october()->id, $m->period_id);
            $this->assertSame('2026-10-31', $m->event_at->setTimezone('Europe/Rome')->format('Y-m-d'));
        }
        // l'incasso e la fattura restano alla data reale
        $this->assertSame('2026-11-02', $paid->paid_at->setTimezone('Europe/Rome')->format('Y-m-d'));
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('2026-11-02', CarbonImmutable::parse($invoice->issued_at)->setTimezone('Europe/Rome')->format('Y-m-d'));
        $this->assertTrue(OrderEvent::where('order_id', $order->id)->where('type', 'competence_set')->exists());
    }

    public function test_deferring_moves_the_order_to_the_next_month_and_survives_the_closing(): void
    {
        $order = $this->orderOnTheLastDay();
        $oct = $this->october();
        $this->assertSame(1, $this->service()->count($oct));

        $this->service()->deferToNext($order, $oct, 'Il cliente ha avvisato: bonifico in arrivo');

        $order->refresh();
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame('2026-11', $order->deferred_to_period);
        $nov = Period::where('code', '2026-11')->firstOrFail();
        $this->assertSame(0, PvMovement::where('period_id', $oct->id)->where('status', 'pending_payment')->count());
        $this->assertGreaterThan(0, PvMovement::where('period_id', $nov->id)->where('order_id', $order->id)->where('status', 'pending_payment')->count());
        $this->assertSame(0, $this->service()->count($oct));
        $this->assertSame(1, $this->service()->count($nov));

        // la chiusura di ottobre non lo annulla
        $this->assertSame(0, app(PeriodService::class)->cancelPendingOrders($oct));
        $this->assertSame('pending_payment', $order->refresh()->status);

        // confermato più tardi, conta nel mese della conferma
        app(OrderService::class)->markPaid($order);
        $this->assertSame(0, PvMovement::where('order_id', $order->id)->where('period_id', $oct->id)->count());
        $this->assertGreaterThan(0, PvMovement::where('order_id', $order->id)->where('period_id', $nov->id)->where('status', 'confirmed')->count());
    }

    public function test_cancelling_releases_the_points_and_logs_the_reason(): void
    {
        $order = $this->orderOnTheLastDay();

        $this->service()->cancel($order, $this->october(), 'Bonifico mai arrivato');

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(0, PvMovement::where('order_id', $order->id)->where('status', '!=', 'cancelled')->count());
        $this->assertTrue(OrderEvent::where('order_id', $order->id)->where('message', 'like', '%Bonifico mai arrivato%')->exists());
        $this->assertSame(0, $this->service()->count($this->october()));
    }

    public function test_official_runtime_of_a_finished_month_waits_for_the_undecided_transfers(): void
    {
        $order = $this->orderOnTheLastDay();
        $oct = $this->october();

        try {
            app(PeriodService::class)->calculate($oct, 'official');
            $this->fail('Il runtime ufficiale non doveva partire con un bonifico da decidere.');
        } catch (PeriodStateException $e) {
            $this->assertStringContainsString('Bonifici in sospeso', $e->getMessage());
        }
        // la stima (live) non è bloccata
        $this->assertSame('completed', app(PeriodService::class)->calculate($oct, 'live')->status);

        $this->service()->cancel($order, $oct, 'Bonifico mai arrivato');

        $this->assertSame('completed', app(PeriodService::class)->calculate($oct, 'official')->status);
    }

    public function test_runtime_is_not_blocked_while_the_month_is_still_running(): void
    {
        $this->orderOnTheLastDay();
        $this->goTo('2026-10-31 23:55');   // ottobre non è ancora finito

        $run = app(PeriodService::class)->calculate($this->october(), 'official');

        $this->assertSame('completed', $run->status);
    }

    public function test_a_closed_month_cannot_be_decided(): void
    {
        $order = $this->orderOnTheLastDay();
        $oct = $this->october();
        $oct->update(['status' => Period::CLOSED]);

        $this->expectException(PeriodStateException::class);
        $this->service()->confirmInPeriod($order, $oct->refresh(), 'Bonifico del 31/10');
    }
}
