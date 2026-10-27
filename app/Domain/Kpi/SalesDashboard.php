<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RankAchievement;
use App\Models\ProductCategory;
use App\Models\Refund;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cruscotto vendite del back-office (stile dashboard Odoo di Lamèss). Sola lettura: non scrive nulla.
 *
 * Importi in euro IVA inclusa (`orders.total`). «Ordini» sono quelli pagati e non del tutto resi (`Order::PAID_STATES`),
 * gli altri stati (in attesa di pagamento, annullati) non contano. Il periodo è una finestra mobile di N giorni
 * (spostabile indietro con `$offset`) confrontata con la finestra precedente di pari durata.
 * Le aggregazioni sono fatte in PHP, così il codice è identico su SQLite e MySQL.
 */
final class SalesDashboard
{
    /** giorni => etichetta; 0 = tutto lo storico */
    public const RANGES = [30 => 'Ultimi 30 giorni', 90 => 'Ultimi 90 giorni', 180 => 'Ultimi 180 giorni', 365 => 'Ultimi 12 mesi', 0 => 'Tutto lo storico'];

    private const TOP = 10;

    private const SELLERS = 15;

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, prev_start: ?CarbonImmutable, prev_end: ?CarbonImmutable}
     */
    public function window(int $days, int $offset, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $offset = max(0, $offset);

        if ($days <= 0) {
            $first = Order::query()->min('created_at');

            return ['start' => $first ? CarbonImmutable::parse($first)->startOfDay() : $now->startOfDay(), 'end' => $now->endOfDay(), 'prev_start' => null, 'prev_end' => null];
        }

        $end = $now->subDays($days * $offset)->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $prevEnd = $start->subSecond();

        return ['start' => $start, 'end' => $end, 'prev_start' => $prevEnd->subDays($days - 1)->startOfDay(), 'prev_end' => $prevEnd];
    }

    /** @return array<string, mixed> */
    public function build(int $days, int $offset = 0, ?CarbonImmutable $now = null): array
    {
        $w = $this->window($days, $offset, $now);
        $orders = $this->orders($w['start'], $w['end']);
        $paid = $orders->filter(fn (Order $o) => $this->isPaid($o))->values();

        $prevOrders = $w['prev_start'] && $w['prev_end'] ? $this->orders($w['prev_start'], $w['prev_end']) : null;
        $prevPaid = $prevOrders?->filter(fn (Order $o) => $this->isPaid($o))->values();

        $cur = $this->metrics($w['start'], $w['end'], $orders);
        $prev = $prevOrders ? $this->metrics($w['prev_start'], $w['prev_end'], $prevOrders) : null;

        $items = $this->items($w['start'], $w['end']);
        $names = $this->buyerNames($orders);
        // incaricato di ogni ordine: quello indicato sull'ordine, altrimenti quello assegnato al cliente
        $assigned = $this->assignedConsultants($orders->concat($prevOrders ?? []));
        $sellerOf = $this->attribution($orders, $assigned);
        $prevSellerOf = $prevOrders ? $this->attribution($prevOrders, $assigned) : [];
        $newBySeller = $this->newCustomersBySeller($w['start'], $w['end']);
        $sellerIds = collect($sellerOf)->concat($prevSellerOf)->concat(array_keys($newBySeller))->filter()->unique()->values();
        $sellers = Consultant::query()->whereIn('id', $sellerIds)->pluck('name', 'id');
        $sellerStats = $this->sellerStats($paid, $prevPaid, $sellerOf, $prevSellerOf, $newBySeller, $sellers);

        $monthly = $this->monthly($paid, $w['start'], $w['end']);

        return [
            'window' => $w,
            'tiles' => $this->tiles($cur, $prev),
            'extra' => [
                'new_customers' => ['value' => $cur['new_customers'], 'delta' => $prev ? $this->delta($cur['new_customers'], $prev['new_customers']) : null],
                'refunds' => ['value' => $cur['refunds'], 'delta' => $prev ? $this->delta($cur['refunds'], $prev['refunds']) : null],
                'kmoney' => ['value' => $cur['kmoney'], 'delta' => $prev ? $this->delta($cur['kmoney'], $prev['kmoney']) : null],
                'subscriptions_active' => Subscription::query()->where('status', Subscription::ACTIVE)->count(),
                'subscriptions_paused' => Subscription::query()->where('status', Subscription::PAUSED)->count(),
            ],
            'monthly' => $monthly,
            'chart' => self::areaChart(array_column($monthly, 'revenue')),
            'top_orders' => $this->topOrders($paid, $names, $sellers, $sellerOf),
            'top_customers' => $this->topCustomers($paid, $names, $sellers, $sellerOf, $assigned),
            'top_products' => $this->topProducts($items),
            'categories' => $this->categories($items),
            'seller_stats' => $sellerStats,
            'seller_highlights' => $this->sellerHighlights($sellerStats),
            'sellers_with_sales' => count(array_filter($sellerStats, fn (array $r) => $r['id'] !== null && $r['orders'] > 0)),
            'sellers_active' => Consultant::query()->where('status', 'active')->count(),
            'channels' => $this->group($paid, fn (Order $o) => self::channelLabel($o->source)),
            'payments' => $this->group($paid, fn (Order $o) => self::paymentLabel($o->payment_method)),
            'countries' => $this->group($paid, fn (Order $o) => $o->country_code ?: '—'),
        ];
    }

    public static function channelLabel(?string $source): string
    {
        return match ($source) {
            null, '', 'shop' => 'Negozio online',
            'odoo' => 'Importati da Odoo',
            'manual', 'admin' => 'Ordine manuale',
            default => ucfirst($source),
        };
    }

    public static function paymentLabel(?string $method): string
    {
        return match ($method) {
            'card' => 'Carta', 'transfer' => 'Bonifico', 'kmoney' => 'KMoney', null, '' => 'Non indicato', default => ucfirst($method),
        };
    }

    /** Variazione percentuale; null se non confrontabile (periodo precedente a zero). */
    public function delta(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 1);
    }

    /**
     * Geometria di un grafico ad area (viewBox w×h) per i valori dati: linea, area, punti e linee guida.
     *
     * @param  array<int, float|int>  $values
     * @return array{w:int,h:int,line:string,area:string,points:array<int,array{x:float,y:float}>,ticks:array<int,array{y:float,value:float}>,left:int,bottom:int}
     */
    public static function areaChart(array $values, int $w = 1000, int $h = 280): array
    {
        $left = 64;
        $right = 24;
        $top = 20;
        $bottom = 36;
        $max = $values ? max(array_map('floatval', $values)) : 0.0;
        $step = self::niceStep($max / 4);
        $axisMax = max($step * 4, 1.0);
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $inset = 40; // i punti non toccano l'asse: le etichette restano leggibili
        $n = count($values);

        $points = [];
        foreach (array_values($values) as $i => $v) {
            $x = $n > 1 ? $left + $inset + ($plotW - 2 * $inset) * $i / ($n - 1) : $left + $plotW / 2;
            $points[] = ['x' => round($x, 1), 'y' => round($top + $plotH * (1 - (float) $v / $axisMax), 1)];
        }

        $line = '';
        foreach ($points as $i => $p) {
            $line .= ($i === 0 ? 'M' : 'L').$p['x'].' '.$p['y'].' ';
        }
        $base = $top + $plotH;
        $area = $points ? trim($line).' L'.end($points)['x'].' '.$base.' L'.$points[0]['x'].' '.$base.' Z' : '';

        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $ticks[] = ['y' => round($top + $plotH * (1 - $i / 4), 1), 'value' => $step * $i];
        }

        return ['w' => $w, 'h' => $h, 'line' => trim($line), 'area' => $area, 'points' => $points, 'ticks' => $ticks, 'left' => $left, 'bottom' => $h - $bottom];
    }

    /** Passo «tondo» (1, 2, 2,5, 5 × 10^n) per le linee guida dell'asse. */
    public static function niceStep(float $raw): float
    {
        if ($raw <= 0) {
            return 1.0;
        }
        $pow = 10 ** floor(log10($raw));
        $f = $raw / $pow;
        $nice = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 2.5 ? 2.5 : ($f <= 5 ? 5 : 10)));

        return (float) ($nice * $pow);
    }

    private function isPaid(Order $o): bool
    {
        return in_array($o->status, Order::PAID_STATES, true);
    }

    /** @return Collection<int, Order> */
    private function orders(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Order::query()->whereBetween('created_at', [$from, $to])
            ->get(['id', 'number', 'buyer_user_id', 'beneficiary_consultant_id', 'status', 'country_code', 'payment_method', 'source', 'total', 'kmoney_amount', 'created_at', 'billing'])
            ->filter(fn (Order $o) => $this->isPaid($o))->values();
    }

    /**
     * @param  ?Collection<int, Order>  $orders  già caricati (per la finestra corrente), altrimenti li legge
     * @return array{orders:int,revenue:float,avg:float,new_customers:int,refunds:float,kmoney:float}
     */
    private function metrics(CarbonImmutable $from, CarbonImmutable $to, ?Collection $orders = null): array
    {
        $orders ??= $this->orders($from, $to);
        $paid = $orders->filter(fn (Order $o) => $this->isPaid($o));
        $revenue = (float) $paid->sum(fn (Order $o) => (float) $o->total);

        return [
            'orders' => $paid->count(),
            'revenue' => $revenue,
            'avg' => $paid->count() ? $revenue / $paid->count() : 0.0,
            // data di registrazione reale (nei clienti importati `created_at` è il giorno dell'import)
            'new_customers' => Customer::query()->notConsultants()->whereRaw('COALESCE(registered_at, created_at) BETWEEN ? AND ?', [$from, $to])->count(),
            'refunds' => (float) Refund::query()->whereBetween('created_at', [$from, $to])->where('status', '!=', Refund::FAILED)->sum('amount'),
            'kmoney' => (float) $paid->sum(fn (Order $o) => (float) $o->kmoney_amount),
        ];
    }

    /**
     * @param  array<string, float|int>  $cur
     * @param  ?array<string, float|int>  $prev
     * @return array<string, array{value: float|int, delta: ?float}>
     */
    private function tiles(array $cur, ?array $prev): array
    {
        $out = [];
        foreach (['orders', 'revenue', 'avg'] as $k) {
            $out[$k] = ['value' => $cur[$k], 'delta' => $prev ? $this->delta($cur[$k], $prev[$k]) : null];
        }

        return $out;
    }

    /**
     * Ricavo e ordini per mese, con i mesi senza vendite a zero.
     *
     * @param  Collection<int, Order>  $paid
     * @return array<int, array{key:string,label:string,revenue:float,orders:int}>
     */
    private function monthly(Collection $paid, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $by = $paid->groupBy(fn (Order $o) => $o->created_at->format('Y-m'));
        $out = [];
        for ($m = $start->startOfMonth(); $m <= $end; $m = $m->addMonth()) {
            $rows = $by->get($m->format('Y-m'), collect());
            $out[] = [
                'key' => $m->format('Y-m'),
                'label' => $m->locale('it')->translatedFormat('M Y'),
                'revenue' => round((float) $rows->sum(fn (Order $o) => (float) $o->total), 2),
                'orders' => $rows->count(),
            ];
        }

        return $out;
    }

    /** @return Collection<int, OrderItem> righe degli ordini pagati nella finestra */
    private function items(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', Order::PAID_STATES)->whereBetween('orders.created_at', [$from, $to])
            ->get(['order_items.order_id', 'order_items.product_id', 'order_items.name', 'order_items.qty', 'order_items.qty_refunded', 'order_items.unit_net', 'order_items.vat_rate']);
    }

    private static function lineGross(OrderItem $i): float
    {
        return max(0, (int) $i->qty - (int) $i->qty_refunded) * (float) $i->unit_net * (1 + (float) $i->vat_rate / 100);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<int, string> id ordine => nome cliente
     */
    private function buyerNames(Collection $orders): array
    {
        $users = \App\Models\User::query()->whereIn('id', $orders->pluck('buyer_user_id')->filter()->unique())->pluck('name', 'id');

        $names = [];
        foreach ($orders as $o) {
            $billing = is_array($o->billing) ? $o->billing : [];
            $names[$o->id] = (string) ($users[$o->buyer_user_id] ?? ($billing['name'] ?? '')) ?: 'Cliente non indicato';
        }

        return $names;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<int, string>  $names
     * @param  Collection<int, string>  $sellers
     * @param  array<int, ?int>  $sellerOf  id ordine => id incaricato
     * @return array<int, array{number:string,customer:string,seller:string,total:float}>
     */
    private function topOrders(Collection $orders, array $names, Collection $sellers, array $sellerOf): array
    {
        return $orders->sortByDesc(fn (Order $o) => (float) $o->total)->take(self::TOP)->map(fn (Order $o) => [
            'number' => (string) $o->number,
            'customer' => $names[$o->id],
            'seller' => $this->sellerLabel($sellerOf[$o->id] ?? null, $sellers),
            'total' => (float) $o->total,
        ])->values()->all();
    }

    /**
     * Clienti migliori con il loro incaricato (quello assegnato al cliente, altrimenti quello degli ordini).
     *
     * @param  Collection<int, Order>  $paid
     * @param  array<int, string>  $names
     * @param  Collection<int, string>  $sellers
     * @param  array<int, ?int>  $sellerOf
     * @param  array<int, ?int>  $assigned  id utente => id incaricato assegnato
     * @return array<int, array{label:string,orders:int,revenue:float,seller:string}>
     */
    private function topCustomers(Collection $paid, array $names, Collection $sellers, array $sellerOf, array $assigned): array
    {
        return $paid->groupBy(fn (Order $o) => $o->buyer_user_id ? 'u'.$o->buyer_user_id : 'n'.$names[$o->id])->map(function (Collection $rows) use ($names, $sellers, $sellerOf, $assigned) {
            $first = $rows->first();
            $sid = ($first->buyer_user_id ? ($assigned[$first->buyer_user_id] ?? null) : null) ?? $rows->map(fn (Order $o) => $sellerOf[$o->id] ?? null)->filter()->first();

            return [
                'label' => $names[$first->id],
                'orders' => $rows->count(),
                'revenue' => round((float) $rows->sum(fn (Order $o) => (float) $o->total), 2),
                'seller' => $this->sellerLabel($sid, $sellers),
            ];
        })->sortByDesc('revenue')->take(self::TOP)->values()->all();
    }

    /**
     * Raggruppa gli ordini per etichetta: numero ordini e ricavo, dal più alto.
     *
     * @param  Collection<int, Order>  $orders
     * @param  callable(Order): string  $key
     * @return array<int, array{label:string,orders:int,revenue:float}>
     */
    private function group(Collection $orders, callable $key): array
    {
        return $orders->groupBy($key)->map(function (Collection $rows, string $k) {
            return [
                'label' => $k,
                'orders' => $rows->count(),
                'revenue' => round((float) $rows->sum(fn (Order $o) => (float) $o->total), 2),
            ];
        })->sortByDesc('revenue')->take(self::TOP)->values()->all();
    }

    private function sellerLabel(?int $id, Collection $sellers): string
    {
        return $id === null ? 'Vendita diretta' : (string) ($sellers[$id] ?? 'Incaricato #'.$id);
    }

    /**
     * Incaricato assegnato a ciascun cliente (utente) degli ordini dati.
     *
     * @param  iterable<int, Order>  $orders
     * @return array<int, ?int> id utente => id incaricato
     */
    private function assignedConsultants(iterable $orders): array
    {
        $users = collect($orders)->pluck('buyer_user_id')->filter()->unique()->values();

        return Customer::query()->whereIn('user_id', $users)->whereNotNull('assigned_consultant_id')->pluck('assigned_consultant_id', 'user_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Incaricato di ogni ordine: quello indicato sull'ordine, altrimenti quello assegnato al cliente, altrimenti nessuno (vendita diretta).
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<int, ?int>  $assigned
     * @return array<int, ?int> id ordine => id incaricato
     */
    private function attribution(Collection $orders, array $assigned): array
    {
        $out = [];
        foreach ($orders as $o) {
            $out[$o->id] = $o->beneficiary_consultant_id ? (int) $o->beneficiary_consultant_id : ($assigned[$o->buyer_user_id] ?? null);
        }

        return $out;
    }

    /** @return array<int, int> id incaricato => clienti a lui assegnati e registrati nel periodo */
    private function newCustomersBySeller(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Customer::query()->whereNotNull('assigned_consultant_id')->whereRaw('COALESCE(registered_at, created_at) BETWEEN ? AND ?', [$from, $to])
            ->pluck('assigned_consultant_id')->countBy()->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, string> id incaricato => nome della qualifica (pietra) in vigore */
    private function currentRanks(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $out = [];
        foreach (RankAchievement::query()->whereIn('consultant_id', $ids)->orderBy('effective_from')->orderBy('id')->get(['consultant_id', 'rank_code', 'effective_from']) as $r) {
            if ($r->effective_from === null || $r->effective_from <= now()) {
                $out[(int) $r->consultant_id] = ucfirst(strtolower((string) $r->rank_code));
            }
        }

        return $out;
    }

    /**
     * Rendimento per incaricato nel periodo (con «vendita diretta» come riga a parte), dal ricavo più alto.
     *
     * @param  Collection<int, Order>  $paid
     * @param  ?Collection<int, Order>  $prevPaid
     * @param  array<int, ?int>  $sellerOf
     * @param  array<int, ?int>  $prevSellerOf
     * @param  array<int, int>  $newBySeller
     * @param  Collection<int, string>  $names
     * @return array<int, array{id:?int,label:string,rank:?string,orders:int,revenue:float,share:float,avg:float,customers:int,new_customers:int,delta:?float}>
     */
    private function sellerStats(Collection $paid, ?Collection $prevPaid, array $sellerOf, array $prevSellerOf, array $newBySeller, Collection $names): array
    {
        $total = (float) $paid->sum(fn (Order $o) => (float) $o->total);
        $by = $paid->groupBy(fn (Order $o) => (string) ($sellerOf[$o->id] ?? ''));
        $prevRevenue = $prevPaid ? $prevPaid->groupBy(fn (Order $o) => (string) ($prevSellerOf[$o->id] ?? ''))->map(fn (Collection $g) => (float) $g->sum(fn (Order $o) => (float) $o->total)) : null;

        $keys = $by->keys()->concat(collect(array_keys($newBySeller))->map(fn ($k) => (string) $k))->unique()->values();
        $ranks = $this->currentRanks($keys->filter(fn ($k) => $k !== '')->map(fn ($k) => (int) $k)->values());

        $rows = $keys->map(function (string $k) use ($by, $total, $prevRevenue, $newBySeller, $names, $ranks) {
            $rows = $by->get($k, collect());
            $id = $k === '' ? null : (int) $k;
            $revenue = round((float) $rows->sum(fn (Order $o) => (float) $o->total), 2);

            return [
                'id' => $id,
                'label' => $this->sellerLabel($id, $names),
                'rank' => $id !== null ? ($ranks[$id] ?? null) : null,
                'orders' => $rows->count(),
                'revenue' => $revenue,
                'share' => $total > 0 ? round($revenue / $total * 100, 1) : 0.0,
                'avg' => $rows->count() ? round($revenue / $rows->count(), 2) : 0.0,
                'customers' => $rows->pluck('buyer_user_id')->filter()->unique()->count(),
                'new_customers' => $id !== null ? ($newBySeller[$id] ?? 0) : 0,
                'delta' => $prevRevenue ? $this->delta($revenue, (float) ($prevRevenue[$k] ?? 0)) : null,
            ];
        });

        return $rows->sortBy([['revenue', 'desc'], ['new_customers', 'desc'], ['label', 'asc']])->take(self::SELLERS)->values()->all();
    }

    /**
     * I migliori incaricati per ricavo, ordini, nuovi clienti e clienti serviti (esclusa la vendita diretta; solo con valore > 0).
     *
     * @param  array<int, array<string, mixed>>  $stats
     * @return array<string, ?array{label:string,value:float|int}>
     */
    private function sellerHighlights(array $stats): array
    {
        $named = array_values(array_filter($stats, fn (array $r) => $r['id'] !== null));
        $best = function (string $key) use ($named): ?array {
            usort($named, fn (array $a, array $b) => [$b[$key], $b['revenue']] <=> [$a[$key], $a['revenue']]);

            return $named && $named[0][$key] > 0 ? ['label' => $named[0]['label'], 'value' => $named[0][$key]] : null;
        };

        return ['revenue' => $best('revenue'), 'orders' => $best('orders'), 'new_customers' => $best('new_customers'), 'customers' => $best('customers')];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, array{label:string,orders:int,qty:int,revenue:float}>
     */
    private function topProducts(Collection $items): array
    {
        return $items->groupBy(fn (OrderItem $i) => trim((string) $i->name) ?: 'Prodotto senza nome')->map(fn (Collection $rows, string $name) => [
            'label' => $name,
            'orders' => $rows->pluck('order_id')->unique()->count(),
            'qty' => (int) $rows->sum(fn (OrderItem $i) => max(0, (int) $i->qty - (int) $i->qty_refunded)),
            'revenue' => round((float) $rows->sum(fn (OrderItem $i) => self::lineGross($i)), 2),
        ])->sortByDesc('revenue')->take(self::TOP)->values()->all();
    }

    /**
     * Ricavo per categoria prodotto, con quota percentuale.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, array{label:string,revenue:float,share:float}>
     */
    private function categories(Collection $items): array
    {
        $catOf = Product::query()->whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('category_id', 'id');
        $catNames = ProductCategory::query()->pluck('name', 'id');

        $rows = $items->groupBy(fn (OrderItem $i) => ($cid = $catOf[$i->product_id] ?? null) ? (string) ($catNames[$cid] ?? 'Senza categoria') : 'Senza categoria')
            ->map(fn (Collection $g, string $name) => ['label' => $name, 'revenue' => round((float) $g->sum(fn (OrderItem $i) => self::lineGross($i)), 2)])
            ->sortByDesc('revenue')->take(self::TOP)->values();

        $sum = (float) $rows->sum('revenue');

        return $rows->map(fn (array $r) => $r + ['share' => $sum > 0 ? round($r['revenue'] / $sum * 100, 1) : 0.0])->all();
    }
}
