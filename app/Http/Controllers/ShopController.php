<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\InvoiceService;
use App\Domain\Customers\ShippingAddressBook;
use App\Domain\Orders\CartPricer;
use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PendingCart;
use App\Domain\Orders\PricedCart;
use App\Domain\Orders\ReturnRequestService;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Orders\StripeWebhook;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\BundleRule;
use App\Models\CreditNote;
use App\Models\Discount;
use App\Models\KMoneyLink;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Negozio: catalogo, scheda prodotto, carrello (in sessione), sconti, checkout, ordini e documenti dell'utente. */
class ShopController extends Controller
{
    private const COUNTRY = 'IT';

    /** Nazione di spedizione scelta nel checkout (in sessione): decide tariffa e prodotti vendibili. Senza scelta o se non aperta: Italia. */
    private function country(Request $r): string
    {
        $c = strtoupper((string) $r->session()->get('checkout_country', self::COUNTRY));

        return in_array($c, (array) config('lamess.shop_countries'), true) ? $c : self::COUNTRY;
    }

    /** Righe per pagina nella vista a griglia: i prodotti per pagina sono sempre colonne × righe, così non resta una riga a metà. */
    private const GRID_ROWS = 4;

    /** Righe tra cui il cliente può scegliere («Per pagina»). */
    private const GRID_ROW_OPTIONS = [3, 4, 6, 8];

    private const LIST_PER_PAGE = 10;

    private const COLS_MIN = 3;

    private const COLS_MAX = 6;

    private const SORTS = [
        'consigliati' => 'Consigliati', 'novita' => 'Novità', 'prezzo-asc' => 'Prezzo: dal più basso', 'prezzo-desc' => 'Prezzo: dal più alto', 'nome' => 'Nome A-Z', 'kmoney' => 'KMoney: % più alta',
    ];

    /** Filtro «KMoney» del negozio: soglia minima della % pagabile in KMoney => etichetta. */
    private const KMONEY_FILTERS = [1 => 'Pagabili in KMoney', 25 => 'Almeno 25%', 50 => 'Almeno 50%', 75 => 'Almeno 75%', 100 => 'Solo 100% in KMoney'];

    /** @return array<int,int> */
    private function cart(Request $r): array
    {
        return array_filter((array) $r->session()->get('cart', []), fn ($q) => $q > 0);
    }

    /** @return array<int,array<int,int>> ordine multiplo: user_id destinatario => [product_id => qty] */
    private function others(Request $r): array
    {
        $out = [];
        foreach ((array) $r->session()->get('cart_others', []) as $uid => $lines) {
            $lines = array_filter((array) $lines, fn ($q) => $q > 0);
            if ($lines !== []) {
                $out[(int) $uid] = $lines;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function codes(Request $r): array
    {
        return array_values(array_filter((array) $r->session()->get('cart_codes', []), 'is_string'));
    }

    /** Prodotti ancora acquistabili. Quelli nascosti o spariti dopo l'inserimento nel carrello vengono tolti. */
    private function sellableProducts(Request $r): Collection
    {
        $ids = array_unique(array_merge(array_keys($this->cart($r)), ...array_map('array_keys', array_values($this->others($r)))));

        return Product::buyable()->with('parent')->whereIn('id', $ids)->get()->filter(fn (Product $p) => $p->sellableIn($this->country($r)))->keyBy('id');
    }

    /** Toglie dal carrello i prodotti non più in vendita e porta le quantità entro la giacenza. @return list<string> avvisi */
    private function tidyCart(Request $r): array
    {
        $products = $this->sellableProducts($r);
        $notes = [];
        $cart = [];
        foreach ($this->cart($r) as $pid => $q) {
            if (! isset($products[$pid])) {
                $notes[] = 'Un prodotto non è più disponibile ed è stato tolto dal carrello.';

                continue;
            }
            $cart[$pid] = $this->clampQty($products[$pid], $q, $notes);
        }
        $others = [];
        foreach ($this->others($r) as $uid => $lines) {
            foreach ($lines as $pid => $q) {
                if (isset($products[$pid])) {
                    $others[$uid][$pid] = $this->clampQty($products[$pid], $q, $notes);
                }
            }
        }
        $r->session()->put('cart', $cart);
        $r->session()->put('cart_others', $others);

        return array_values(array_unique($notes));
    }

    /** @param  list<string>|null  $notes */
    private function clampQty(Product $p, int $qty, ?array &$notes = null): int
    {
        $max = min(99, $p->availableQty() ?? 99);
        if ($qty > $max && $notes !== null) {
            $notes[] = $max > 0 ? "«{$p->name}»: disponibili solo {$max} pezzi." : "«{$p->name}» è esaurito ed è stato tolto dal carrello.";
        }

        return max(0, min($qty, $max));
    }

    private function priced(Request $r, ?Collection $products = null, bool $shippingWaived = false): PricedCart
    {
        $products ??= $this->sellableProducts($r);
        $entries = [];
        foreach ($this->cart($r) as $pid => $q) {
            if (isset($products[$pid])) {
                $entries["me:{$pid}"] = ['product' => $products[$pid], 'qty' => $q];
            }
        }
        foreach ($this->others($r) as $uid => $lines) {
            foreach ($lines as $pid => $q) {
                if (isset($products[$pid])) {
                    $entries["{$uid}:{$pid}"] = ['product' => $products[$pid], 'qty' => $q];
                }
            }
        }

        return app(CartPricer::class)->price($entries, $r->user(), $this->codes($r), null, $this->country($r), $shippingWaived);
    }

    private function cartCount(Request $r): int
    {
        return array_sum($this->cart($r)) + array_sum(array_map('array_sum', $this->others($r)));
    }

    /** @return array<string,mixed> */
    private function cartViewData(Request $r): array
    {
        $cart = $this->cart($r);
        $products = $this->sellableProducts($r);
        $gifts = [];
        foreach (BundleRule::with('gift')->where('active', true)->get() as $rule) {
            $n = $rule->giftsFor((int) ($cart[$rule->trigger_product_id] ?? 0), now());
            if ($n > 0) {
                $gifts[] = ['name' => $rule->gift->name, 'qty' => $n];
            }
        }
        $others = $this->others($r);
        $user = $r->user();
        $recipients = $user ? app(OrderService::class)->recipientsFor($user) : [];

        return [
            'priced' => $this->priced($r, $products), 'gifts' => $gifts, 'others' => $others, 'recipients' => $recipients, 'codes' => $this->codes($r),
            'names' => User::whereIn('id', array_keys($others))->pluck('name', 'id'), 'count' => $this->cartCount($r),
            'catalog' => $recipients ? Product::buyable()->orderBy('name')->get() : collect(),
        ];
    }

    private function cartJson(Request $r, string $message = ''): JsonResponse
    {
        PendingCart::pending($r);
        $this->tidyCart($r);

        return response()->json(['count' => $this->cartCount($r), 'message' => $message, 'html' => view('site.shop.drawer', $this->cartViewData($r))->render()]);
    }

    /**
     * Le categorie che il cliente vede: principali con le loro sottocategorie, solo se attive (una sottocategoria sparisce
     * con la madre) e con almeno un prodotto in vetrina. `shop_count` è il numero di prodotti; per una principale include le figlie.
     *
     * @param  Collection<int,int>  $visibleIds
     * @return Collection<int,ProductCategory>
     */
    private function shopCategories(Collection $visibleIds): Collection
    {
        $counts = Product::whereIn('id', $visibleIds)->whereNotNull('category_id')->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $active = ProductCategory::with('parent')->where('active', true)->inTreeOrder()->get()
            ->filter(fn (ProductCategory $c) => $c->parent_id === null || $c->parent?->active)
            ->each(fn (ProductCategory $c) => $c->setAttribute('shop_count', (int) ($counts[$c->id] ?? 0)));
        $subs = $active->filter(fn (ProductCategory $c) => $c->isSubcategory())->groupBy('parent_id');

        return $active->filter(fn (ProductCategory $c) => ! $c->isSubcategory())->each(function (ProductCategory $root) use ($subs): void {
            $kids = ($subs->get($root->id) ?? collect())->filter(fn (ProductCategory $k) => $k->shop_count > 0)->values();
            $root->setRelation('children', $kids);
            $root->setAttribute('shop_count', $root->shop_count + $kids->sum('shop_count'));
        })->filter(fn (ProductCategory $c) => $c->shop_count > 0)->values();
    }

    public function index(Request $r)
    {
        $visibleIds = Product::onShelf()->get(['id', 'countries', 'active', 'parent_id'])->filter(fn (Product $p) => $p->sellableIn(self::COUNTRY))->pluck('id');
        $query = Product::onShelf()->with(['category', 'variants'])->whereIn('id', $visibleIds);

        $categories = $this->shopCategories($visibleIds);
        $category = $categories->flatMap(fn (ProductCategory $c) => $c->children->prepend($c))->firstWhere('slug', (string) $r->query('categoria'));
        if ($category) {
            // Una categoria principale mostra anche i prodotti delle sue sottocategorie.
            $query->whereIn('category_id', $category->isSubcategory() ? [$category->id] : $category->children->pluck('id')->prepend($category->id)->all());
        }
        $navRoot = $category ? ($category->isSubcategory() ? $categories->firstWhere('id', $category->parent_id) : $category) : null;
        $term = trim((string) $r->query('q'));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhere('variant_label', 'like', $like)));
        }
        // Filtro prezzo (IVA inclusa): net_price × (100 + IVA) equivale al prezzo lordo × 100.
        $min = $this->priceFilter($r->query('prezzo_min'));
        $max = $this->priceFilter($r->query('prezzo_max'));
        $grossExpr = 'net_price * (100 + vat_rate)';
        foreach ([[$min, '>='], [$max, '<=']] as [$bound, $op]) {
            if ($bound !== null) {
                $query->where(fn ($q) => $q->whereRaw("$grossExpr $op ?", [$bound * 100])
                    ->orWhereHas('variants', fn ($v) => $v->whereRaw("$grossExpr $op ?", [$bound * 100])));
            }
        }
        $kmoneyMin = array_key_exists((int) $r->query('kmoney'), self::KMONEY_FILTERS) ? (int) $r->query('kmoney') : null;
        if ($kmoneyMin !== null) {
            $query->where('kmoney_percent', '>=', $kmoneyMin);
        }
        $kmoneyAvailable = $kmoneyMin !== null || Product::whereIn('id', $visibleIds)->where('kmoney_percent', '>', 0)->exists();
        $bounds = Product::whereIn('id', $visibleIds)->selectRaw("min($grossExpr) as lo, max($grossExpr) as hi")->first();
        $priceBounds = $bounds && $bounds->hi !== null && $bounds->lo != $bounds->hi ? [(int) floor($bounds->lo / 100), (int) ceil($bounds->hi / 100)] : null;
        $sort = array_key_exists((string) $r->query('ordina'), self::SORTS) ? (string) $r->query('ordina') : 'consigliati';
        match ($sort) {
            'novita' => $query->orderByDesc('id'),
            'prezzo-asc' => $query->orderByRaw('net_price * (100 + vat_rate) asc'),
            'prezzo-desc' => $query->orderByRaw('net_price * (100 + vat_rate) desc'),
            'nome' => $query->orderBy('name'),
            'kmoney' => $query->orderByDesc('kmoney_percent')->orderByDesc('featured')->orderBy('sort_order')->orderBy('name'),
            default => $query->orderByDesc('featured')->orderBy('sort_order')->orderBy('name'),
        };

        // Vista scelta dal cliente (griglia 3–6 colonne o elenco): resta ricordata in un cookie.
        $cols = max(self::COLS_MIN, min(self::COLS_MAX, (int) ($r->query('colonne') ?? $r->cookie('shop_cols') ?? self::COLS_MIN)));
        $requestedView = (string) ($r->query('vista') ?? $r->cookie('shop_view') ?? 'griglia');
        $view = $requestedView === 'elenco' ? 'elenco' : 'griglia';
        if ($r->query->has('colonne')) {
            cookie()->queue('shop_cols', (string) $cols, 60 * 24 * 365);
        }
        if ($r->query->has('vista')) {
            cookie()->queue('shop_view', $view, 60 * 24 * 365);
        }
        // Prodotti per pagina: in griglia sempre un multiplo delle colonne (3, 4, 6 o 8 righe piene); in elenco 10, 20 o 40.
        $perPageOptions = $view === 'elenco' ? [10, 20, 40] : array_map(fn (int $rows) => $cols * $rows, self::GRID_ROW_OPTIONS);
        $perPage = (int) $r->query('perpagina');
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = $view === 'elenco' ? self::LIST_PER_PAGE : $cols * self::GRID_ROWS;
        }

        return view('site.shop.index', [
            'products' => $query->paginate($perPage)->withQueryString(), 'cols' => $cols, 'view' => $view, 'perPage' => $perPage, 'perPageOptions' => $perPageOptions, 'categories' => $categories, 'category' => $category, 'navRoot' => $navRoot,
            'term' => $term, 'sort' => $sort, 'sorts' => self::SORTS, 'totalVisible' => $visibleIds->count(),
            'priceMin' => $min, 'priceMax' => $max, 'priceBounds' => $priceBounds,
            'kmoneyMin' => $kmoneyMin, 'kmoneyFilters' => self::KMONEY_FILTERS, 'kmoneyAvailable' => $kmoneyAvailable,
            'shippingPromo' => $this->shippingPromo(),
        ]);
    }

    /** Valore del filtro prezzo: numero positivo (anche con la virgola) oppure null. */
    private function priceFilter(mixed $value): ?float
    {
        $v = str_replace(',', '.', trim((string) $value));

        return is_numeric($v) && (float) $v >= 0 && (float) $v < 100000 ? round((float) $v, 2) : null;
    }

    /** Soglia di spedizione gratuita più bassa tra gli sconti automatici attivi, per la fascia in cima al negozio. */
    private function shippingPromo(): ?string
    {
        $d = Discount::whereNull('code')->where('active', true)->where('type', Discount::FREE_SHIPPING)->whereNotNull('min_subtotal')->where('applies_to', 'all')->whereNull('min_qty')->get()
            ->filter(fn (Discount $x) => $x->isWithinDates(now()) && $x->audience === 'all')->sortBy('min_subtotal')->first();

        return $d ? Money::eur($d->min_subtotal) : null;
    }

    public function show(Request $r, string $slug)
    {
        $found = Product::with('parent')->where('slug', $slug)->first();
        // Il link di una variante porta alla pagina del prodotto principale, con quella variante già scelta.
        if ($found?->parent_id !== null && $found->parent) {
            return redirect($found->shopUrl());
        }
        $product = Product::onShelf()->with(['category.parent', 'variants'])->where('slug', $slug)->first();
        abort_unless($product && $product->sellableIn(self::COUNTRY), 404);

        $variants = $product->sellableVariants();
        $chosen = $variants->firstWhere('id', (int) $r->query('variante')) ?? $variants->first(fn (Product $v) => ! $v->isSoldOut()) ?? $variants->first();

        $related = Product::onShelf()->with('variants')->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->orderByDesc('featured')->orderBy('sort_order')->limit(8)->get()->filter(fn (Product $p) => $p->sellableIn(self::COUNTRY))->take(4);

        return view('site.shop.show', ['product' => $product, 'variants' => $variants, 'chosen' => $chosen, 'related' => $related, 'offers' => $this->offersFor($product), 'shippingPromo' => $this->shippingPromo()]);
    }

    /**
     * Offerte automatiche che toccano questo prodotto, in parole semplici (es. «Da 3 pezzi: −10%»).
     *
     * @return list<string>
     */
    private function offersFor(Product $p): array
    {
        $out = [];
        foreach (Discount::whereNull('code')->where('active', true)->where('audience', 'all')->get() as $d) {
            if (! $d->isWithinDates(now()) || $d->type === Discount::FREE_SHIPPING) {
                continue;
            }
            $applies = match ($d->applies_to) {
                'products' => in_array($p->id, array_map('intval', (array) $d->product_ids), true),
                'categories' => $p->category_id !== null && in_array((int) $p->category_id, ProductCategory::withChildIds((array) $d->category_ids), true),
                default => true,
            };
            if (! $applies) {
                continue;
            }
            $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
            if ($d->type === Discount::TIERED) {
                foreach (collect((array) $d->tiers)->sortBy('min_qty') as $t) {
                    $out[] = 'Da '.(int) ($t['min_qty'] ?? 0).' pezzi: −'.$pct($t['percent'] ?? 0).'%';
                }
            } else {
                $cond = collect([$d->min_qty ? 'da '.$d->min_qty.' pezzi' : null, $d->min_subtotal ? 'spendendo almeno € '.Money::eur($d->min_subtotal) : null])->filter()->implode(', ');
                $out[] = ($d->type === Discount::PERCENT ? '−'.$pct($d->value).'%' : '−€ '.Money::eur($d->value)).($cond !== '' ? ' '.$cond : '');
            }
        }

        return array_values(array_unique($out));
    }

    /** Immagini del negozio: si servono da /media/… perché su cPanel senza terminale non si può creare il collegamento «storage». */
    public function media(Request $request, string $path)
    {
        abort_unless(str_starts_with($path, 'shop/') && ! str_contains($path, '..') && preg_match('/\.(jpe?g|png|webp)$/i', $path) === 1, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $response = $disk->response($path, null, ['Cache-Control' => 'public, max-age=2592000']);
        $response->setEtag(md5($path.'|'.$disk->lastModified($path).'|'.$disk->size($path)));
        $response->setLastModified(\DateTimeImmutable::createFromFormat('U', (string) $disk->lastModified($path)) ?: null);
        $response->isNotModified($request);   // se il browser ha già l'immagine risponde 304, senza rinviare i byte

        return $response;
    }

    public function add(Request $r)
    {
        $d = $r->validate(['product_id' => 'required|exists:products,id', 'qty' => 'required|integer|min:1|max:99']);
        PendingCart::pending($r); // se l'ordine precedente è ormai pagato, il carrello riparte vuoto
        $product = Product::buyable()->find($d['product_id']);
        if ($product === null || ! $product->sellableIn(self::COUNTRY)) {
            $why = Product::find($d['product_id'])?->hasVariants() ? 'Scegli prima una variante (formato, taglia, colore…).' : 'Prodotto non disponibile.';

            return $r->expectsJson() ? response()->json(['message' => $why], 422) : redirect('/negozio')->withErrors(['order' => $why]);
        }
        $cart = $this->cart($r);
        $notes = [];
        $want = ($cart[$product->id] ?? 0) + (int) $d['qty'];
        $cart[$product->id] = $this->clampQty($product, $want, $notes);
        if ($cart[$product->id] <= 0) {
            unset($cart[$product->id]);
        }
        $r->session()->put('cart', $cart);
        $message = $notes[0] ?? "«{$product->name}» aggiunto al carrello.";

        return $r->expectsJson() ? $this->cartJson($r, $message) : redirect('/carrello')->with('status', $message);
    }

    public function addForOther(Request $r, OrderService $orders)
    {
        $d = $r->validate(['recipient_id' => 'required|integer', 'product_id' => 'required|exists:products,id', 'qty' => 'required|integer|min:1|max:99']);
        abort_unless(array_key_exists((int) $d['recipient_id'], $orders->recipientsFor($r->user())), 403);
        $product = Product::buyable()->findOrFail($d['product_id']);
        $others = $this->others($r);
        $others[(int) $d['recipient_id']][$product->id] = $this->clampQty($product, ($others[(int) $d['recipient_id']][$product->id] ?? 0) + (int) $d['qty']);
        $r->session()->put('cart_others', $others);

        return redirect('/carrello')->with('status', 'Aggiunto all\'ordine per conto di altri.');
    }

    public function update(Request $r)
    {
        $products = $this->sellableProducts($r);
        $notes = [];
        $others = [];
        foreach ((array) $r->input('qty_others', []) as $uid => $lines) {
            foreach ((array) $lines as $pid => $q) {
                if ((int) $q > 0 && isset($products[(int) $pid])) {
                    $others[(int) $uid][(int) $pid] = $this->clampQty($products[(int) $pid], (int) $q, $notes);
                }
            }
        }
        $r->session()->put('cart_others', $others);
        $cart = [];
        foreach ((array) $r->input('qty', []) as $pid => $q) {
            if ((int) $q > 0 && isset($products[(int) $pid])) {
                $cart[(int) $pid] = $this->clampQty($products[(int) $pid], (int) $q, $notes);
            }
        }
        $r->session()->put('cart', $cart);

        return redirect('/carrello')->with('status', $notes[0] ?? null);
    }

    /** Cambia la quantità di una riga dal carrello a cassetto (0 = rimuovi). */
    public function line(Request $r): JsonResponse
    {
        $d = $r->validate(['product_id' => 'required|integer', 'qty' => 'required|integer|min:0|max:99', 'for' => 'nullable|integer']);
        $product = Product::buyable()->find($d['product_id']);
        $notes = [];
        if (! empty($d['for'])) {
            $others = $this->others($r);
            $q = $product && (int) $d['qty'] > 0 ? $this->clampQty($product, (int) $d['qty'], $notes) : 0;
            if ($q > 0) {
                $others[(int) $d['for']][$d['product_id']] = $q;
            } else {
                unset($others[(int) $d['for']][$d['product_id']]);
            }
            $r->session()->put('cart_others', $others);
        } else {
            $cart = $this->cart($r);
            $q = $product && (int) $d['qty'] > 0 ? $this->clampQty($product, (int) $d['qty'], $notes) : 0;
            if ($q > 0) {
                $cart[(int) $d['product_id']] = $q;
            } else {
                unset($cart[(int) $d['product_id']]);
            }
            $r->session()->put('cart', $cart);
        }

        return $this->cartJson($r, $notes[0] ?? '');
    }

    public function mini(Request $r): JsonResponse
    {
        return $this->cartJson($r);
    }

    public function applyCode(Request $r)
    {
        $d = $r->validate(['code' => 'required|string|max:40'], ['required' => 'Scrivi il codice sconto.']);
        $code = strtoupper(preg_replace('/\s+/', '', $d['code']));
        $codes = $this->codes($r);
        $this->tidyCart($r);
        $trial = array_values(array_unique(array_merge($codes, [$code])));
        $entries = [];
        foreach ($this->sellableProducts($r) as $p) {
            $entries["x:{$p->id}"] = ['product' => $p, 'qty' => max(1, $this->cart($r)[$p->id] ?? 1)];
        }
        $priced = app(CartPricer::class)->price($entries, $r->user(), $trial, null, $this->country($r));
        $why = $priced->messages[$code] ?? null;
        if ($why !== null && ! str_starts_with($why, 'Non applicato:')) {
            return back()->withInput()->withErrors(['code' => $why]);
        }
        $r->session()->put('cart_codes', $trial);

        return back()->with('status', $why ?? "Codice {$code} applicato.");
    }

    public function removeCode(Request $r)
    {
        $code = strtoupper((string) $r->input('code'));
        $r->session()->put('cart_codes', array_values(array_diff($this->codes($r), [$code])));

        return back()->with('status', 'Codice rimosso.');
    }

    public function cartView(Request $r)
    {
        $pendingOrder = PendingCart::pending($r);
        $notes = $this->tidyCart($r);
        $data = $this->cartViewData($r);
        // Un codice che nel frattempo non vale più (scaduto, esaurito…) si toglie e si avvisa.
        $kept = [];
        foreach ($this->codes($r) as $c) {
            if (isset($data['priced']->messages[$c]) && ! str_starts_with($data['priced']->messages[$c], 'Non applicato:')) {
                $notes[] = "Codice {$c}: ".$data['priced']->messages[$c];
            } else {
                $kept[] = $c;
            }
        }
        if ($kept !== $this->codes($r)) {
            $r->session()->put('cart_codes', $kept);
            $data = $this->cartViewData($r);
        }

        return view('site.shop.cart', $data + ['notes' => $notes, 'pendingOrder' => $pendingOrder]);
    }

    public function checkoutForm(Request $r)
    {
        PendingCart::pending($r);
        $this->tidyCart($r);
        if ($this->cart($r) === [] && $this->others($r) === []) {
            return redirect('/negozio');
        }
        $u = $r->user()->load('profile');
        // Dati del cliente già registrato: se nel profilo manca il nome, si parte da quello dell'account (senza salvare nulla).
        $profile = $u->profile ?? new Profile(['type' => 'private']);
        if (blank($profile->first_name) && blank($profile->last_name) && blank($profile->company_name) && filled($u->name)) {
            $parts = preg_split('/\s+/', trim($u->name));
            $profile->last_name = count($parts) > 1 ? array_pop($parts) : null;
            $profile->first_name = implode(' ', $parts);
        }

        $book = app(ShippingAddressBook::class);

        return view('site.shop.checkout', ['addresses' => $book->list($u), 'maxAddresses' => $book->max(), 'profile' => $profile, 'user' => $u, 'priced' => $this->priced($r), 'pricedSub' => $this->priced($r, null, true), 'codes' => $this->codes($r), 'count' => $this->cartCount($r), 'kmoneyEnabled' => app(OrderService::class)->kmoneyEnabled(), 'kmoneyConnectable' => app(KMoneyProvider::class) instanceof KMoneyApiProvider, 'kmoneyRegisterUrl' => $this->kmoneyRegisterUrl(), 'methods' => app(OrderService::class)->enabledMethods(), 'pendingOrder' => PendingCart::pending($r), 'country' => $this->country($r), 'countries' => $this->openCountries()]);
    }

    /** @return array<string,string> codice => nome delle nazioni aperte nello shop */
    private function openCountries(): array
    {
        $names = (array) config('lamess.country_names');

        return collect((array) config('lamess.shop_countries'))->mapWithKeys(fn ($c) => [$c => $names[$c] ?? $c])->all();
    }

    /**
     * Cambio nazione nel checkout: ricalcola spedizione e prodotti vendibili senza perdere quanto già scritto nel modulo.
     * La nazione è quella dell'indirizzo salvato scelto, oppure quella indicata per fatturazione/nuovo indirizzo.
     */
    public function checkoutCountry(Request $r)
    {
        $to = (string) $r->input('ship_to', 'billing');
        if (ctype_digit($to)) {
            $c = ShippingAddress::where('user_id', $r->user()->id)->find((int) $to)?->country_code;
        } else {
            $c = $to === 'new' ? $r->input('ship_country') : $r->input('country');
        }
        $r->session()->put('checkout_country', app(ShippingAddressBook::class)->country($c));

        return redirect('/area/checkout')->withInput($r->except(['_token', 'privacy']));
    }

    /**
     * Salva dati e indirizzo del checkout nel profilo, così alla prossima volta sono già lì (anche se manca il profilo).
     * Si può comunque cambiarli in un ordine successivo: ogni ordine conserva il proprio indirizzo.
     *
     * @param  array<string,mixed>  $d
     */
    private function rememberBilling(Request $r, array $d): void
    {
        if (! $r->boolean('remember', true)) {
            return;
        }
        $fields = array_filter(array_intersect_key($d, array_flip([
            'type', 'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec', 'address', 'city', 'zip', 'province',
        ])), fn ($v) => filled($v));
        Profile::updateOrCreate(['user_id' => $r->user()->id], $fields);
    }

    /** Con il driver reale: il cliente non ha ancora collegato il conto KMoney (o non ha concesso il mandato di pagamento). */
    private function kmoneyNotLinked(Request $r): bool
    {
        return app(KMoneyProvider::class) instanceof KMoneyApiProvider
            && ! KMoneyLink::where('user_id', $r->user()->id)->whereNotNull('mandate_uuid')->exists();
    }

    private function kmoneyRegisterUrl(): string
    {
        return (string) (config('lamess.kmoney.register_url') ?: 'https://kmoney.it/register');
    }

    public function checkout(Request $r, OrderService $orders, SubscriptionService $subs)
    {
        $d = $r->validate([
            'type' => 'required|in:private,company', 'first_name' => 'nullable', 'last_name' => 'nullable', 'company_name' => 'nullable',
            'tax_code' => 'nullable', 'vat_number' => 'nullable', 'sdi_code' => 'nullable', 'pec' => 'nullable|email',
            'address' => 'required', 'city' => 'required', 'zip' => 'required', 'province' => 'nullable', 'country' => 'nullable|string|size:2',
            'payment_method' => 'required|in:card,transfer,kmoney', 'kmoney_rest' => 'nullable|in:card,transfer', 'privacy' => 'accepted', 'subscribe' => 'nullable|in:none,monthly,bimonthly',
        ], SiteController::MESSAGES);
        $subscribe = $d['subscribe'] ?? 'none';
        if (! ($orders->enabledMethods()[$d['payment_method']] ?? false)) {
            return back()->withInput()->withErrors(['order' => 'Questo metodo di pagamento non è disponibile al momento: scegline un altro.']);
        }
        if ($d['payment_method'] === 'kmoney' && ! $orders->kmoneyEnabled()) {
            return back()->withInput()->withErrors(['order' => 'Il pagamento con KMoney non è disponibile al momento: scegli un altro metodo.']);
        }
        // KMoney senza conto collegato (con mandato): l'ordine non si crea e non resta in sospeso; il cliente riceve l'avviso con i link per collegare o aprire il conto.
        if ($d['payment_method'] === 'kmoney' && $this->kmoneyNotLinked($r)) {
            return back()->withInput()
                ->withErrors(['order' => 'Per pagare con KMoney collega il tuo conto.'])
                ->with('kmoney_unlinked', ['connect' => url('/area/kmoney/collega'), 'register' => $this->kmoneyRegisterUrl()]);
        }
        if ($subscribe !== 'none' && ! in_array($d['payment_method'], ['card', 'kmoney'], true)) {
            return back()->withInput()->withErrors(['order' => 'L\'abbonamento si paga con carta o con KMoney: scegli uno dei due.']);
        }
        if ($subscribe !== 'none' && $d['payment_method'] === 'kmoney' && $r->input('kmoney_rest') === 'transfer') {
            return back()->withInput()->withErrors(['order' => 'Con l\'abbonamento il resto, se c\'è, si paga con carta: il bonifico non può essere automatico.']);
        }
        if ($subscribe !== 'none' && $this->codes($r) !== []) {
            return back()->withInput()->withErrors(['order' => 'I codici sconto valgono sugli ordini singoli: rimuovi il codice dal carrello o scegli «ordine singolo». Gli sconti automatici si applicano comunque.']);
        }

        try {
            $d['shipping'] = app(ShippingAddressBook::class)->resolve($r->user(), $r->all(), $d);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }
        // L'ordine è venduto e spedito nella nazione dell'indirizzo di spedizione scelto (Italia se non indicata).
        $country = (string) $d['shipping']['country_code'];
        $r->session()->put('checkout_country', $country);

        // Un ordine rimasto in attesa (pagina di pagamento abbandonata, poi «torna al carrello») si chiude prima di crearne uno nuovo: la merce non resta bloccata.
        if (($stale = PendingCart::pending($r)) !== null) {
            try {
                $staleSub = $stale->subscription_id !== null ? Subscription::find($stale->subscription_id) : null;
                if ($staleSub !== null && $subs->awaitingFirstPayment($staleSub)) {
                    $subs->cancel($staleSub, 'Pagamento iniziale non completato');
                } else {
                    $orders->cancel($stale);
                }
                $r->session()->forget(PendingCart::KEY);
            } catch (OrderException) {
                return redirect("/area/ordini/{$stale->id}")->withErrors(['order' => "Hai già un pagamento in corso per l'ordine {$stale->number}: completalo da qui, oppure contatta l'assistenza prima di riordinare."]);
            }
        }

        if ($subscribe !== 'none') {
            $this->rememberBilling($r, $d);
            try {
                $sub = $subs->subscribe($r->user(), $this->cart($r), $country, $d, 12, $this->others($r), null, $d['payment_method'], $subscribe === 'bimonthly' ? 2 : 1);
            } catch (OrderException $e) {
                return back()->withInput()->withErrors(['order' => $e->getMessage()]);
            }
            // Carta su pagina esterna: il primo pagamento si fa lì (e salva la carta per i mesi successivi). Il carrello resta finché non è pagato.
            if ($orders->usesRedirectCheckout() && $subs->awaitingFirstPayment($sub)) {
                $first = $sub->orders()->where('status', 'pending_payment')->latest('id')->first();
                if ($first !== null && ($first->payment_method === 'card' || $first->kmoney_rest_method === 'card')) {
                    PendingCart::hold($r, $first);

                    return $this->toCardCheckout($first, $orders);
                }
            }
            PendingCart::clear($r);

            return redirect('/area/abbonamenti')->with('status', $sub->status === 'active' ? 'Abbonamento attivato: primo ordine pagato.' : 'Abbonamento creato ma il primo addebito non è riuscito: puoi riprovare da qui.');
        }
        try {
            $order = $orders->place($r->user(), $this->cart($r), $country, $d['payment_method'], $d, forOthers: $this->others($r), discountCodes: $this->codes($r), kmoneyRest: $d['kmoney_rest'] ?? null);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }
        $this->rememberBilling($r, $d);
        // Bonifico: l'ordine è registrato e il carrello si svuota. Carta o KMoney: il carrello resta finché l'ordine non risulta pagato.
        if ($d['payment_method'] === 'transfer') {
            PendingCart::clear($r);
        } else {
            PendingCart::hold($r, $order);
        }

        if ($d['payment_method'] === 'card') {
            if ($orders->usesRedirectCheckout()) {
                return $this->toCardCheckout($order, $orders);
            }
            try {
                $paid = $orders->payByCard($order);
            } catch (OrderException $e) {
                return redirect("/area/ordini/{$order->id}")->withErrors(['order' => $e->getMessage()]);
            }

            return redirect("/area/ordini/{$order->id}")->with('status', $paid ? 'Pagamento ricevuto. Grazie!' : 'Pagamento non riuscito: puoi riprovare dall\'ordine.');
        }

        if ($d['payment_method'] === 'kmoney') {
            // Nessun addebito automatico: il pagamento KMoney si conferma esplicitamente dalla pagina dell'ordine.
            return redirect("/area/ordini/{$order->id}")->with('status', 'Ordine registrato. Conferma il pagamento con KMoney per completarlo.');
        }

        return redirect("/area/ordini/{$order->id}")->with('status', 'Ordine registrato: segui le istruzioni per il bonifico.');
    }

    public function subscriptions(Request $r)
    {
        $book = app(ShippingAddressBook::class);

        return view('site.shop.subscriptions', ['addresses' => $book->list($r->user()), 'maxAddresses' => $book->max(), 'subs' => Subscription::where('user_id', $r->user()->id)->latest('id')->get(), 'products' => Product::pluck('name', 'id'), 'canUpdateCard' => app(PaymentProvider::class) instanceof StripeCheckoutProvider]);
    }

    public function subscriptionAction(Request $r, Subscription $subscription, string $action, SubscriptionService $subs)
    {
        abort_unless($subscription->user_id === $r->user()->id, 404);
        try {
            match ($action) {
                'pausa' => $subs->pause($subscription),
                'riprendi' => $subs->resume($subscription),
                'annulla' => $subs->cancel($subscription),
                'salta' => $subs->skipNext($subscription),
                default => abort(404),
            };
        } catch (OrderException $e) {
            return redirect('/area/abbonamenti')->withErrors(['order' => $e->getMessage()]);
        }

        return redirect('/area/abbonamenti')->with('status', ['pausa' => 'Abbonamento in pausa.', 'riprendi' => 'Abbonamento ripreso.', 'annulla' => 'Abbonamento annullato.', 'salta' => 'Fatto: il prossimo addebito è stato spostato al mese successivo.'][$action]);
    }

    /** Cambio carta: il cliente inserisce la nuova carta sulla pagina sicura di Stripe (nessun addebito). */
    public function subscriptionCard(Request $r, Subscription $subscription)
    {
        abort_unless($subscription->user_id === $r->user()->id, 404);
        $stripe = app(PaymentProvider::class);
        if (! $stripe instanceof StripeCheckoutProvider || ! in_array($subscription->status, [Subscription::ACTIVE, Subscription::PAUSED], true)) {
            return redirect('/area/abbonamenti')->withErrors(['order' => 'Non è possibile cambiare la carta di questo abbonamento.']);
        }
        try {
            $session = $stripe->createCardUpdate($subscription, url('/area/abbonamenti?carta=ok'), url('/area/abbonamenti'));
        } catch (OrderException $e) {
            return redirect('/area/abbonamenti')->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->away($session->url);
    }

    public function subscriptionAddress(Request $r, Subscription $subscription, SubscriptionService $subs)
    {
        abort_unless($subscription->user_id === $r->user()->id, 404);
        try {
            $shipping = app(ShippingAddressBook::class)->resolve($r->user(), $r->all(), (array) $subscription->billing);
            $subs->updateShipping($subscription, $shipping);
        } catch (OrderException $e) {
            return redirect('/area/abbonamenti')->withErrors(['order' => $e->getMessage()]);
        }

        return redirect('/area/abbonamenti')->with('status', 'Indirizzo di consegna aggiornato: vale dal prossimo ordine.');
    }

    public function orders(Request $r)
    {
        return view('site.shop.orders', ['orders' => Order::where('buyer_user_id', $r->user()->id)->latest('id')->get()]);
    }

    private function own(Request $r, Order $order): Order
    {
        abort_unless($order->buyer_user_id === $r->user()->id, 404);

        return $order->load('items', 'invoice', 'creditNotes');
    }

    private function toCardCheckout(Order $order, OrderService $orders)
    {
        try {
            $session = $orders->startCardCheckout($order, url("/area/ordini/{$order->id}?da=carta"), url("/area/ordini/{$order->id}"));
        } catch (OrderException $e) {
            return redirect("/area/ordini/{$order->id}")->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->away($session->url);
    }

    public function order(Request $r, Order $order)
    {
        $order = $this->own($r, $order);
        // Ritorno dalla pagina di pagamento a pagamento riuscito: gli articoli sono stati acquistati, il carrello si svuota.
        if ($r->query('da') === 'carta' && (int) $r->session()->get(PendingCart::KEY) === $order->id) {
            PendingCart::clear($r);
        }
        // Ritorno da Stripe: si chiede a Stripe l'esito invece di aspettare il webhook (che resta comunque la via principale).
        if ($r->query('da') === 'carta' && $order->status === 'pending_payment' && ($stripe = app(PaymentProvider::class)) instanceof StripeCheckoutProvider) {
            app(StripeWebhook::class)->reconcile($order, $stripe);
            $order->refresh();
        }
        if ($r->query('da') === 'carta' && $order->status === 'pending_payment') {
            session()->now('status', 'Stiamo confermando il pagamento: l\'ordine si aggiorna entro pochi istanti.');
        }

        return view('site.shop.order', ['order' => $order]);
    }

    /** Il cliente annulla un proprio ordine con bonifico non ancora pagato (carta e KMoney si chiudono da soli o con l'assistenza). */
    public function cancelOwn(Request $r, Order $order, OrderService $orders)
    {
        $order = $this->own($r, $order);
        abort_unless($order->status === 'pending_payment' && $order->payment_method === 'transfer', 404);
        try {
            $orders->cancel($order);
        } catch (OrderException $e) {
            return redirect("/area/ordini/{$order->id}")->withErrors(['order' => $e->getMessage()]);
        }

        return redirect("/area/ordini/{$order->id}")->with('status', 'Ordine annullato.');
    }

    /** Il cliente chiede il reso di una o più righe dell'ordine: la decisione (e il rimborso) spetta all'admin. */
    public function requestReturn(Request $r, Order $order, ReturnRequestService $returns)
    {
        $order = $this->own($r, $order);
        $data = $r->validate(['qty' => ['array'], 'qty.*' => ['nullable', 'integer', 'min:0', 'max:999'], 'reason' => ['required', 'string', 'max:2000']]);
        try {
            $returns->request($order, $r->user(), $data['qty'] ?? [], $data['reason']);
        } catch (OrderException $e) {
            return redirect("/area/ordini/{$order->id}")->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect("/area/ordini/{$order->id}")->with('status', 'Richiesta di reso inviata. Ti rispondiamo il prima possibile.');
    }

    public function retryCard(Request $r, Order $order, OrderService $orders)
    {
        $order = $this->own($r, $order);
        abort_unless($order->status === 'pending_payment' && in_array($order->payment_method, ['card', 'kmoney'], true), 404);
        if ($order->payment_method === 'kmoney') {
            return $this->payKMoneyOrder($r, $order, $orders);
        }
        if ($orders->usesRedirectCheckout()) {
            return $this->toCardCheckout($order, $orders);
        }
        $paid = $orders->payByCard($order);

        return redirect("/area/ordini/{$order->id}")->with('status', $paid ? 'Pagamento ricevuto. Grazie!' : 'Pagamento non riuscito.');
    }

    /** Dopo la quota KMoney il cliente sceglie come pagare il resto (carta o bonifico, se attivi). */
    public function chooseRest(Request $r, Order $order, OrderService $orders)
    {
        $order = $this->own($r, $order);
        abort_unless($order->status === 'pending_payment' && $order->payment_method === 'kmoney', 404);
        $d = $r->validate(['kmoney_rest' => 'required|in:card,transfer'], SiteController::MESSAGES);
        try {
            $order = $orders->chooseKMoneyRest($order, $d['kmoney_rest']);
        } catch (OrderException $e) {
            return redirect("/area/ordini/{$order->id}")->withErrors(['order' => $e->getMessage()]);
        }

        return $this->payKMoneyOrder($r, $order, $orders);
    }

    /** Ordine pagato (anche in parte) con KMoney: addebita la quota, poi passa al resto con carta se serve. */
    private function payKMoneyOrder(Request $r, Order $order, OrderService $orders)
    {
        $url = "/area/ordini/{$order->id}";
        try {
            $res = $orders->payByKMoney($order);
        } catch (OrderException $e) {
            return redirect($url)->withErrors(['order' => $e->getMessage()]);
        }
        if (! $res->paid) {
            if ($res->error === 'not_linked' || $res->redirectUrl !== null) {
                $r->session()->put('kmoney_order', $order->id);

                return $res->redirectUrl !== null ? redirect()->away($res->redirectUrl) : redirect('/area/kmoney/collega');
            }

            return redirect($url)->withErrors(['order' => $res->error ?: 'Pagamento con KMoney non riuscito: riprova dall\'ordine.']);
        }
        $order->refresh();
        if ($order->status === 'pending_payment' && $order->kmoney_rest_method === null && (float) $order->restDue() > 0) {
            return redirect($url)->with('status', 'Quota KMoney addebitata. Ora scegli come pagare il resto.');
        }
        if ($order->status === 'pending_payment' && $order->kmoney_rest_method === 'card') {
            if ($orders->usesRedirectCheckout()) {
                return $this->toCardCheckout($order, $orders);
            }
            $paid = $orders->payByCard($order);

            return redirect($url)->with('status', $paid ? 'Pagamento ricevuto. Grazie!' : 'Quota KMoney addebitata; il pagamento del resto con carta non è riuscito: riprova dall\'ordine.');
        }
        if ($order->status === 'pending_payment') {
            return redirect($url)->with('status', 'Quota KMoney addebitata. Per completare l\'ordine, paga il resto con bonifico seguendo le istruzioni.');
        }

        return redirect($url)->with('status', 'Pagamento con KMoney ricevuto. Grazie!');
    }

    public function invoicePdf(Request $r, Order $order, InvoiceService $svc)
    {
        $order = $this->own($r, $order);
        abort_unless($order->invoice, 404);

        return response($svc->pdf($order->invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $order->invoice->number).'.pdf"']);
    }

    public function creditNotePdf(Request $r, CreditNote $note, InvoiceService $svc)
    {
        $this->own($r, $note->order);

        return response($svc->pdf($note), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $note->number).'.pdf"']);
    }
}
