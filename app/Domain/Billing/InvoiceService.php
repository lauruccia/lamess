<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Engine\Support\Num;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Emissione di fatture e note di credito, numerazione, consegna al sink, PDF di cortesia. */
final class InvoiceService
{
    public function __construct(private readonly InvoiceSink $sink) {}

    /**
     * Imponibile, IVA (per aliquota, arrotondata sul totale dell'aliquota) e totale.
     *
     * @param  iterable<array{net: string, rate: string}>  $rows
     * @return array{net: string, vat: string, total: string, by_rate: array<string, array{net: string, vat: string}>}
     */
    public static function totals(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = (string) Num::of($r['rate'])->toScale(2);
            $groups[$k] = ($groups[$k] ?? Num::zero())->plus(Num::of($r['net']));
        }
        $net = Num::zero();
        $vat = Num::zero();
        $by = [];
        foreach ($groups as $rate => $base) {
            $v = Num::money($base->multipliedBy($rate)->dividedBy(100, 6, RoundingMode::HalfUp));
            $by[$rate] = ['net' => (string) Num::money($base), 'vat' => (string) $v];
            $net = $net->plus($base);
            $vat = $vat->plus($v);
        }

        return ['net' => (string) Num::money($net), 'vat' => (string) Num::money($vat), 'total' => (string) Num::money($net->plus($vat)), 'by_rate' => $by];
    }

    private static function lineVat(string $net, string $rate): string
    {
        return (string) Num::money(Num::of($net)->multipliedBy($rate)->dividedBy(100, 6, RoundingMode::HalfUp));
    }

    public function issueForOrder(Order $order): Invoice
    {
        if ($order->invoice()->exists()) {
            return $order->invoice;
        }
        $billing = $order->billing;
        $type = ($billing['type'] ?? 'private') === 'company' ? 'invoice' : (string) config('lamess.private_document');
        $now = now();

        $invoice = DB::transaction(function () use ($order, $billing, $type, $now): Invoice {
            $inv = Invoice::create([
                'order_id' => $order->id, 'number' => DocumentNumbers::next((string) config('lamess.invoice_prefix'), (int) $now->format('Y')),
                'year' => (int) $now->format('Y'), 'type' => $type, 'issued_at' => $now, 'buyer' => $billing,
                'net' => $order->subtotal_net + $order->shipping_net, 'vat' => $order->vat_total, 'total' => $order->total,
            ]);
            $rows = [];
            foreach ($order->items as $it) {
                $net = (string) Num::money(Num::of($it->unit_net)->multipliedBy($it->qty));
                $vat = self::lineVat($net, (string) $it->vat_rate);
                $inv->lines()->create([
                    'order_item_id' => $it->id, 'description' => $it->name.($it->is_gift ? ' (omaggio)' : ($it->parent_item_id ? ' (componente kit)' : '')),
                    'qty' => $it->qty, 'unit_net' => $it->unit_net, 'vat_rate' => $it->vat_rate, 'net' => $net, 'vat' => $vat, 'total' => (string) Num::money(Num::of($net)->plus($vat)),
                ]);
                $rows[] = ['net' => (string) Num::of($it->unit_net)->multipliedBy($it->qty), 'rate' => (string) $it->vat_rate];
            }
            if (Num::of($order->shipping_net)->isPositive()) {
                $rate = (string) $order->shipping_vat_rate;
                $vat = self::lineVat((string) $order->shipping_net, $rate);
                $inv->lines()->create([
                    'description' => 'Spese di spedizione', 'qty' => 1, 'unit_net' => $order->shipping_net, 'vat_rate' => $rate,
                    'net' => $order->shipping_net, 'vat' => $vat, 'total' => (string) Num::money(Num::of($order->shipping_net)->plus($vat)),
                ]);
            }

            return $inv;
        });

        $this->deliver($invoice);

        return $invoice->refresh();
    }

    /**
     * @param  array<int,int>  $qtyByItem  order_item_id => quantità resa
     */
    public function issueCreditNote(Order $order, array $qtyByItem, string $reason, bool $refundShipping = false): CreditNote
    {
        $invoice = $order->invoice ?? throw new \RuntimeException('Ordine senza fattura.');
        $now = now();

        $note = DB::transaction(function () use ($order, $invoice, $qtyByItem, $reason, $now, $refundShipping): CreditNote {
            $rows = [];
            $lines = [];
            foreach ($qtyByItem as $itemId => $qty) {
                /** @var OrderItem $it */
                $it = $order->items->firstWhere('id', $itemId);
                $net = (string) Num::money(Num::of($it->unit_net)->multipliedBy($qty));
                $vat = self::lineVat($net, (string) $it->vat_rate);
                $lines[] = [
                    'order_item_id' => $it->id, 'description' => $it->name, 'qty' => $qty, 'unit_net' => $it->unit_net, 'vat_rate' => $it->vat_rate,
                    'net' => $net, 'vat' => $vat, 'total' => (string) Num::money(Num::of($net)->plus($vat)),
                ];
                $rows[] = ['net' => (string) Num::of($it->unit_net)->multipliedBy($qty), 'rate' => (string) $it->vat_rate];
            }
            $shipNet = '0.00';
            if ($refundShipping) {
                $shipNet = (string) Num::money(Num::of($order->shipping_net)->minus($order->shipping_refunded_net));
                if (Num::of($shipNet)->isPositive()) {
                    $rate = (string) $order->shipping_vat_rate;
                    $vat = self::lineVat($shipNet, $rate);
                    $lines[] = [
                        'order_item_id' => null, 'description' => 'Spese di spedizione', 'qty' => 1, 'unit_net' => $shipNet, 'vat_rate' => $rate,
                        'net' => $shipNet, 'vat' => $vat, 'total' => (string) Num::money(Num::of($shipNet)->plus($vat)),
                    ];
                    $rows[] = ['net' => $shipNet, 'rate' => $rate];
                }
            }
            $t = self::totals($rows);
            $cn = CreditNote::create([
                'shipping_net' => $shipNet,
                'invoice_id' => $invoice->id, 'order_id' => $order->id,
                'number' => DocumentNumbers::next((string) config('lamess.credit_note_prefix'), (int) $now->format('Y')),
                'year' => (int) $now->format('Y'), 'issued_at' => $now, 'reason' => $reason,
                'net' => $t['net'], 'vat' => $t['vat'], 'total' => $t['total'],
            ]);
            foreach ($lines as $l) {
                $cn->lines()->create($l);
            }

            return $cn;
        });

        $this->deliver($note);

        return $note->refresh();
    }

    /** Consegna al sink; un errore non annulla l'emissione: il documento resta da reinviare. */
    public function deliver(Invoice|CreditNote $doc): void
    {
        try {
            $r = $this->sink->send($doc);
            $doc->update(['sink_status' => $r->ok ? 'sent' : 'error', 'sink_ref' => $r->reference, 'sink_error' => $r->ok ? null : $r->error]);
        } catch (Throwable $e) {
            $doc->update(['sink_status' => 'error', 'sink_error' => $e->getMessage()]);
        }
    }

    /** Reinvia i documenti non consegnati. @return int quanti consegnati */
    public function retryPending(): int
    {
        $ok = 0;
        foreach ([Invoice::class, CreditNote::class] as $model) {
            // Tetto di 200 documenti per giro (il resto al giro successivo, ogni ora): se il gestionale è fermo non si caricano migliaia di righe in memoria.
            $model::query()->whereIn('sink_status', ['pending', 'error'])->limit(200)->get()->each(function ($doc) use (&$ok): void {
                $this->deliver($doc);
                $ok += $doc->refresh()->sink_status === 'sent' ? 1 : 0;
            });
        }

        return $ok;
    }

    public function pdf(Invoice|CreditNote $doc): string
    {
        $doc->loadMissing('lines');
        $rows = $doc->lines->map(fn ($l) => ['net' => (string) $l->net, 'rate' => (string) $l->vat_rate]);
        $buyer = $doc instanceof Invoice ? $doc->buyer : $doc->invoice->buyer;

        return Pdf::loadView('pdf.invoice', [
            'doc' => $doc, 'isCredit' => $doc instanceof CreditNote, 'buyer' => $buyer,
            'totals' => $doc->lines->isEmpty() ? null : self::totals($rows), 'seller' => config('lamess.seller'),
        ])->output();
    }
}
