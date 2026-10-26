<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Billing\DocumentNumbers;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\InvoiceSink;
use App\Domain\Billing\SinkResult;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ReturnService;
use App\Domain\Periods\PeriodService;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Period;
use App\Models\PvMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class InvoicesAndReturnsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function paid(array $lines, array $billing = [], ?object $sponsor = null): Order
    {
        Notification::fake();
        $svc = app(OrderService::class);
        $o = $svc->place($this->buyer($sponsor ?? $this->activeSponsor()), $lines, 'IT', 'card', $this->billing($billing));
        $svc->payByCard($o);

        return $o->fresh()->load('items', 'invoice');
    }

    public function test_invoice_is_issued_on_payment_with_consistent_totals_and_sequential_numbers(): void
    {
        $this->plan();
        $this->shipping('10.00');
        $p = $this->product('A', '123.00');
        $o1 = $this->paid([$p->id => 2]);
        $o2 = $this->paid([$p->id => 1], sponsor: $this->activeSponsor('LM00002'));

        $inv = $o1->invoice;
        $this->assertMatchesRegularExpression('#^FT/\d{4}/00001$#', $inv->number);
        $this->assertMatchesRegularExpression('#^FT/\d{4}/00002$#', $o2->invoice->number);
        $this->assertSame($o1->total, $inv->total);
        $this->assertSame('312.32', $inv->total);
        $this->assertSame('256.00', $inv->net);
        $this->assertSame('56.32', $inv->vat);
        $this->assertSame(2, $inv->lines()->count(), 'riga prodotto + spedizione');
        $this->assertSame('sent', $inv->sink_status);
        $this->assertStringStartsWith('FAKE-FT-', $inv->sink_ref);
    }

    public function test_company_gets_invoice_and_private_follows_configuration(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $company = $this->paid([$p->id => 1], ['type' => 'company', 'company_name' => 'ACME srl', 'vat_number' => '01234567890', 'sdi_code' => 'ABCDEFG']);
        $this->assertSame('invoice', $company->invoice->type);
        $this->assertSame('ACME srl', $company->invoice->buyer['name']);

        config(['lamess.private_document' => 'receipt']);
        $private = $this->paid([$p->id => 1], sponsor: $this->activeSponsor('LM00009'));
        $this->assertSame('receipt', $private->invoice->type);
    }

    public function test_sink_failure_does_not_block_the_order_and_can_be_retried(): void
    {
        $this->plan();
        $failing = new class implements InvoiceSink
        {
            public bool $up = false;

            public function name(): string
            {
                return 'down';
            }

            public function send(Invoice|CreditNote $document): SinkResult
            {
                if (! $this->up) {
                    throw new \RuntimeException('gestionale non raggiungibile');
                }

                return new SinkResult(true, 'OK-1');
            }
        };
        $this->app->instance(InvoiceSink::class, $failing);
        $p = $this->product('A', '100.00');
        $o = $this->paid([$p->id => 1]);

        $this->assertSame('paid', $o->status);
        $this->assertSame('error', $o->invoice->sink_status);
        $this->assertStringContainsString('non raggiungibile', $o->invoice->sink_error);

        $failing->up = true;
        $this->assertSame(1, app(InvoiceService::class)->retryPending());
        $this->assertSame('sent', $o->invoice->fresh()->sink_status);
    }

    public function test_pdf_is_generated(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $o = $this->paid([$p->id => 1]);
        $this->assertStringStartsWith('%PDF', app(InvoiceService::class)->pdf($o->invoice));
    }

    public function test_pdf_is_generated_when_buyer_has_no_vat_number_or_tax_code(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $o = $this->paid([$p->id => 1]);
        $invoice = $o->invoice;
        $invoice->buyer = ['name' => 'Mario Rossi', 'address' => 'Via Roma 1', 'zip' => '89100', 'city' => 'Reggio Calabria', 'province' => 'RC'];
        $invoice->save();

        $this->assertStringStartsWith('%PDF', app(InvoiceService::class)->pdf($invoice->fresh()));
    }

    public function test_document_counters_are_independent_per_year_and_prefix(): void
    {
        $this->assertSame('FT/2026/00001', DocumentNumbers::next('FT', 2026));
        $this->assertSame('FT/2026/00002', DocumentNumbers::next('FT', 2026));
        $this->assertSame('FT/2027/00001', DocumentNumbers::next('FT', 2027));
        $this->assertSame('NC/2026/00001', DocumentNumbers::next('NC', 2026));
    }

    public function test_partial_refund_in_open_period_creates_credit_note_and_negative_movement_same_period(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $o = $this->paid([$p->id => 3]);
        $orig = PvMovement::firstOrFail();
        $item = $o->items->first();

        $cn = app(ReturnService::class)->refund($o, [$item->id => 1], 'Prodotto difettoso');

        $this->assertMatchesRegularExpression('#^NC/\d{4}/00001$#', $cn->number);
        $this->assertSame('123.00', $cn->net);
        $this->assertSame('27.06', $cn->vat);
        $this->assertSame('150.06', $cn->total);
        $this->assertSame('sent', $cn->sink_status);
        $neg = PvMovement::where('type', 'refund')->firstOrFail();
        $this->assertSame('-100.00', $neg->pv);
        $this->assertSame('450.18', $orig->amount, 'importo IVA inclusa della riga (3 × 150,06)');
        $this->assertSame('-150.06', $neg->amount, 'il reso storna l’importo in euro');
        $this->assertSame($orig->period_id, $neg->period_id);
        $this->assertSame($orig->id, $neg->reverses_movement_id);
        $this->assertSame($cn->id, $neg->credit_note_id);
        $this->assertSame('partially_refunded', $o->fresh()->status);
        $this->assertSame(1, $item->fresh()->qty_refunded);
    }

    public function test_full_refund_marks_order_refunded_and_cannot_exceed_quantity(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $o = $this->paid([$p->id => 2]);
        $item = $o->items->first();
        $svc = app(ReturnService::class);
        $svc->refund($o, [$item->id => 1], 'reso 1');
        $svc->refund($o->fresh(), [$item->id => 1], 'reso 2');
        $this->assertSame('refunded', $o->fresh()->status);
        $this->assertSame(2, CreditNote::count());

        $this->expectException(OrderException::class);
        $svc->refund($o->fresh(), [$item->id => 1], 'oltre');
    }

    public function test_refund_requires_reason_and_paid_order(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $o = $this->paid([$p->id => 1]);
        try {
            app(ReturnService::class)->refund($o, [$o->items->first()->id => 1], ' ');
            $this->fail();
        } catch (OrderException $e) {
            $this->assertStringContainsString('motivazione', $e->getMessage());
        }
        $pending = app(OrderService::class)->place($this->buyer(), [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->expectException(OrderException::class);
        app(ReturnService::class)->refund($pending, [$pending->items->first()->id => 1], 'x');
    }

    public function test_g19_refund_after_period_close_creates_clawback_in_the_open_period(): void
    {
        $this->plan();
        $sponsor = $this->activeSponsor();
        $p = $this->product('A', '1230.00');            // 1000 PV
        $o = $this->paid([$p->id => 1], sponsor: $sponsor);
        $code = now('Europe/Rome')->format('Y-m');
        $periods = app(PeriodService::class);
        $period = Period::where('code', $code)->firstOrFail();
        $periods->calculate($period, 'official');
        $periods->close($period->fresh());
        $this->assertGreaterThan(0, CommissionLine::where('period_id', $period->id)->where('bonus_type', 'direct')->sum('amount'));

        // il mese successivo si apre dal back-office; qui usiamo il periodo successivo
        $next = $periods->open(PeriodKey::parse($code)->next()->code(), CompPlanVersion::firstOrFail());
        $this->travelTo(Carbon::instance($next->starts_at)->addDays(2));
        app(ReturnService::class)->refund($o->fresh()->load('items'), [$o->items->first()->id => 1], 'reso dopo chiusura');

        $neg = PvMovement::where('type', 'refund')->firstOrFail();
        $this->assertSame($next->id, $neg->period_id, 'lo storno va nel periodo aperto');
        $this->assertSame('-1000.00', $neg->pv);

        $run = $periods->calculate($next->fresh());
        $claw = CommissionLine::where('run_id', $run->id)->where('bonus_type', 'clawback')->sum('amount');
        $this->assertLessThan(0, (float) $claw);
    }
}
