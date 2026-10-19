<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Engine\Support\Num;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PricedLine;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Dettaglio ordine: cliente, righe, totali, pagamenti e rimborsi, documenti e cronologia; le azioni sono quelle dell'elenco. */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Ordine '.$this->record->number;
    }

    private static function shippingGross(Order $r): string
    {
        return (string) PricedLine::gross(Num::of((string) $r->shipping_net), (string) $r->shipping_vat_rate);
    }

    /** Numero del ciclo di abbonamento a cui appartiene l'ordine (1 = primo ordine). */
    private static function subscriptionCycle(Order $r): int
    {
        return Order::where('subscription_id', $r->subscription_id)->where('id', '<=', $r->id)->count();
    }

    private static function eur(string|int|float|null $v): string
    {
        return '€ '.number_format((float) $v, 2, ',', '.');
    }

    private static function when(mixed $d): string
    {
        return $d === null ? '—' : $d->copy()->setTimezone('Europe/Rome')->format('d/m/Y H:i');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ordine')->columns(4)->schema([
                TextEntry::make('number')->label('Numero'),
                TextEntry::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => OrderResource::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'pending_payment' => 'warning', 'paid', 'processing', 'shipped', 'delivered' => 'success', 'cancelled' => 'gray', default => 'danger',
                    }),
                TextEntry::make('created_at')->label('Creato il')->state(fn (Order $r) => self::when($r->created_at)),
                TextEntry::make('paid_at')->label('Pagato il')->state(fn (Order $r) => self::when($r->paid_at)),
                TextEntry::make('buyer.name')->label('Cliente')->placeholder('—'),
                TextEntry::make('buyer.email')->label('Email')->placeholder('—'),
                TextEntry::make('beneficiary.name')->label('Incaricato (vendita)')->placeholder('— (vendita casa)'),
                TextEntry::make('payment_method')->label('Pagamento')->state(fn (Order $r) => OrderService::methodLabel($r->payment_method).($r->kmoney_rest_method ? ' + '.OrderService::methodLabel($r->kmoney_rest_method) : '')),
                TextEntry::make('order_kind')->label('Tipo ordine')->badge()
                    ->state(fn (Order $r) => $r->subscription_id ? 'Abbonamento '.($r->subscription?->intervalMonths() === 2 ? 'bimestrale' : 'mensile').' · n. '.$r->subscription_id.' · ciclo '.self::subscriptionCycle($r) : 'Ordine singolo')
                    ->color(fn (Order $r) => $r->subscription_id ? 'info' : 'gray')
                    ->icon(fn (Order $r) => $r->subscription_id ? 'heroicon-o-arrow-path' : null)
                    ->url(fn (Order $r) => $r->subscription_id ? SubscriptionResource::getUrl('edit', ['record' => $r->subscription_id]) : null),
            ]),
            Section::make('Consegna')->columns(4)->schema([
                TextEntry::make('carrier')->label('Corriere')->placeholder('—'),
                TextEntry::make('tracking_code')->label('Tracking')->placeholder('—'),
                TextEntry::make('shipped_at')->label('Spedito il')->state(fn (Order $r) => self::when($r->shipped_at)),
                TextEntry::make('delivered_at')->label('Consegnato il')->state(fn (Order $r) => self::when($r->delivered_at)),
                TextEntry::make('billing_text')->label('Fatturazione')->columnSpan(2)->state(fn (Order $r) => $this->addressText((array) ($r->billing ?? []), false))->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('shipping_text')->label('Spedizione a')->columnSpan(2)->state(fn (Order $r) => $this->addressText((array) (($r->billing ?? [])['shipping'] ?? []), true))->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Righe')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('name')->label('Prodotto')->columnSpan(2),
                    TextEntry::make('qty')->label('Q.tà'),
                    TextEntry::make('unit_gross')->label('Prezzo unitario (IVA incl.)')->state(fn ($record) => self::eur((string) PricedLine::gross(Num::of((string) $record->unit_net), (string) $record->vat_rate))),
                    TextEntry::make('qty_refunded')->label('Resi'),
                ]),
            ]),
            Section::make('Totali')->columns(5)->schema([
                TextEntry::make('products_gross')->label('Prodotti (IVA incl.)')->state(fn (Order $r) => self::eur((float) $r->total - (float) self::shippingGross($r))),
                TextEntry::make('shipping_gross')->label('Spedizione (IVA incl.)')->state(fn (Order $r) => self::eur(self::shippingGross($r))),
                TextEntry::make('total')->label('Totale')->state(fn (Order $r) => self::eur($r->total))->weight('bold'),
                TextEntry::make('vat_total')->label('di cui IVA')->state(fn (Order $r) => self::eur($r->vat_total)),
                TextEntry::make('discounts_text')->label('Sconti')->state(fn (Order $r) => $r->discounts ? collect($r->discounts)->map(fn ($d) => $d['code'] ?: $d['name'])->implode(', ') : '—'),
            ]),
            Section::make('Pagamenti e rimborsi')->schema([
                TextEntry::make('payments_text')->hiddenLabel()->state(fn (Order $r) => $this->paymentsText($r))->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Documenti')->schema([
                TextEntry::make('docs_text')->hiddenLabel()->state(fn (Order $r) => collect([$r->invoice ? 'Fattura '.$r->invoice->number : null])
                    ->merge($r->creditNotes->map(fn ($n) => 'Nota di credito '.$n->number.' · '.self::eur($n->total)))->filter()->implode("\n") ?: 'Nessun documento')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Cronologia')->schema([
                TextEntry::make('timeline')->hiddenLabel()->state(fn (Order $r) => $r->events()->with('user')->get()->map(fn ($e) => sprintf(
                    '[%s] %s%s%s', self::when($e->created_at), $e->internal ? 'NOTA INTERNA — ' : '', $e->message, $e->user ? ' ('.$e->user->name.')' : '',
                ))->implode("\n") ?: 'Nessun evento registrato')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    /** @param array<string,mixed> $a */
    private function addressText(array $a, bool $shipping): string
    {
        if (empty($a['address'])) {
            return '—';
        }
        $name = $shipping ? ($a['recipient'] ?? '') : (($a['company_name'] ?? '') ?: trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')));

        return trim(($name !== '' ? $name."\n" : '').$a['address']."\n".trim(($a['zip'] ?? '').' '.($a['city'] ?? '')).(! empty($a['province']) ? ' ('.$a['province'].')' : ''));
    }

    private function paymentsText(Order $r): string
    {
        $lines = [];
        foreach ($r->payments()->get() as $p) {
            $lines[] = sprintf('Pagamento %s · %s · %s%s', OrderService::methodLabel($p->method), self::eur($p->amount), $p->status, $p->provider_ref ? ' · rif. '.$p->provider_ref : '');
        }
        foreach ($r->refunds()->get() as $f) {
            $lines[] = sprintf('Rimborso %s · %s · %s%s', OrderService::methodLabel($f->method), self::eur($f->amount), match ($f->status) {
                'done' => 'eseguito', 'failed' => 'NON RIUSCITO', default => 'da eseguire'
            }, $f->error ? ' · '.$f->error : '');
        }

        return implode("\n", $lines) ?: 'Nessun pagamento registrato';
    }

    protected function getHeaderActions(): array
    {
        return OrderResource::orderActions();
    }
}
