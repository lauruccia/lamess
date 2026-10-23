<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Etichette di spedizione: una per ordine, stampabili singole o in blocco su fogli A4 (2 colonne × 4 righe = 8 etichette per foglio).
 */
final class ShippingLabelService
{
    /** Stati per cui ha senso preparare un pacco. */
    public const PRINTABLE = ['paid', 'processing', 'shipped', 'delivered', 'partially_refunded'];

    public function isPrintable(Order $order): bool
    {
        return in_array($order->status, self::PRINTABLE, true);
    }

    /**
     * I dati di una etichetta: destinatario (indirizzo di consegna, altrimenti fatturazione), mittente, colli.
     *
     * @return array{number:string,name:string,lines:list<string>,phone:?string,items:list<string>,pieces:int,sender:string}
     */
    public function label(Order $order): array
    {
        $billing = (array) $order->billing;
        $ship = is_array($billing['shipping'] ?? null) ? $billing['shipping'] : [];
        $address = filled($ship['address'] ?? null) ? $ship : $billing;
        $name = trim((string) ($ship['recipient'] ?? '')) ?: (string) ($billing['name'] ?? $order->buyer?->name ?? '');
        $country = (string) ($billing['country_code'] ?? 'IT');
        $city = trim(($address['zip'] ?? '').' '.($address['city'] ?? '').(filled($address['province'] ?? null) ? ' ('.$address['province'].')' : ''));
        $lines = array_values(array_filter([(string) ($address['address'] ?? ''), $city, $country !== 'IT' ? $country : null]));
        $order->loadMissing('items');
        $company = config('lamess.company');

        return [
            'number' => (string) $order->number,
            'name' => $name,
            'lines' => $lines,
            'phone' => $ship['phone'] ?? null,
            'items' => $order->items->map(fn ($i) => $i->qty.' × '.$i->name)->all(),
            'pieces' => (int) $order->items->sum('qty'),
            'sender' => trim($company['name'].' — '.$company['address']),
        ];
    }

    /** @param  Collection<int,Order>  $orders */
    public function pdf(Collection $orders, bool $sheet = true): string
    {
        $labels = $orders->filter(fn (Order $o) => $this->isPrintable($o))->map(fn (Order $o) => $this->label($o))->values()->all();
        abort_if($labels === [], 422, 'Nessun ordine pronto da spedire tra quelli scelti.');

        return Pdf::loadView('pdf.shipping-labels', ['labels' => $labels, 'perPage' => $sheet ? 8 : 1])
            ->setPaper($sheet ? 'a4' : [0, 0, 297.6, 420.9], 'portrait')->output();
    }
}
