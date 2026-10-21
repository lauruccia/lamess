<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'net_price' => 'decimal:8', 'founder_net_price' => 'decimal:8', 'vat_rate' => 'decimal:2', 'pv' => 'decimal:2', 'cv' => 'decimal:2',
            'commissionable_pct' => 'decimal:2', 'shipping_commissionable' => 'boolean',
            'promo_rank_double_cv' => 'boolean', 'is_kit' => 'boolean', 'active' => 'boolean', 'countries' => 'array',
            'compare_at_net' => 'decimal:8', 'images' => 'array', 'featured' => 'boolean', 'allow_backorder' => 'boolean', 'stock_qty' => 'integer',
            'options' => 'array', 'variant_options' => 'array',
            'vap_value' => 'decimal:4', 'customer_validity_months' => 'integer', 'customer_count' => 'integer', 'makes_client' => 'boolean', 'months_per_piece' => 'boolean',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(KitComponent::class, 'kit_product_id');
    }

    /** Varianti (formato, taglia, colore…): prodotti «figli» di questo. */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** Prodotti in vetrina: i principali pubblicati con un prezzo; se hanno varianti, almeno una deve essere in vendita. */
    public function scopeOnShelf(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->where('active', true)->where('net_price', '>', 0)
            ->where(fn (Builder $q) => $q->whereDoesntHave('variants')
                ->orWhereHas('variants', fn (Builder $v) => $v->where('active', true)->where('net_price', '>', 0)));
    }

    /** Ciò che si può mettere nel carrello: prodotti singoli e varianti pubblicate (mai un principale con varianti). */
    public function scopeBuyable(Builder $query): Builder
    {
        return $query->where('active', true)->where('net_price', '>', 0)->whereDoesntHave('variants')
            ->where(fn (Builder $q) => $q->whereNull('parent_id')->orWhereHas('parent', fn (Builder $p) => $p->where('active', true)));
    }

    /** Percentuali KMoney selezionabili dall'amministratore (coerenti con quelle accettate da KMoney). */
    public const KMONEY_PERCENTS = [0, 25, 50, 75, 100];

    /** Quota (%) del prezzo pagabile in KMoney; le varianti ereditano quella del prodotto principale. */
    public function kmoneyPercent(): int
    {
        $own = $this->parent_id !== null ? (int) ($this->parent?->kmoney_percent ?? 100) : (int) ($this->kmoney_percent ?? 100);

        return in_array($own, self::KMONEY_PERCENTS, true) ? $own : 0;
    }

    public function hasVariants(): bool
    {
        return $this->parent_id === null && ($this->relationLoaded('variants') ? $this->variants->isNotEmpty() : $this->variants()->exists());
    }

    /** Varianti acquistabili, in ordine. @return Collection<int,Product> */
    public function sellableVariants(): Collection
    {
        return $this->variantRows()->filter(fn (Product $v) => $v->active && Num::of($v->net_price)->isPositive())->values();
    }

    /** @return Collection<int,Product> */
    private function variantRows(): Collection
    {
        return $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
    }

    /** Prezzo più basso e più alto tra le varianti in vendita (IVA inclusa); null senza varianti. @return array{0: string, 1: string}|null */
    public function variantPriceRange(?User $user = null): ?array
    {
        $prices = $this->sellableVariants()->map(fn (Product $v) => $v->grossPriceFor($user))->sortBy(fn ($p) => (float) $p)->values();

        return $prices->isEmpty() ? null : [(string) $prices->first(), (string) $prices->last()];
    }

    /** Il principale riallinea il proprio prezzo al più basso delle varianti: ordinamenti e «da € …» restano coerenti. */
    public function refreshFromVariants(): void
    {
        $rows = $this->variants()->get();
        if ($rows->isEmpty()) {
            return;
        }
        $pool = $rows->filter(fn (Product $v) => $v->active)->whenEmpty(fn () => $rows);
        $min = $pool->map(fn (Product $v) => Num::of($v->net_price))->reduce(fn ($c, $n) => $c === null || $n->isLessThan($c) ? $n : $c);
        if ($min !== null) {
            static::whereKey($this->id)->update(['net_price' => (string) $min->toScale(8), 'compare_at_net' => null, 'stock_qty' => null]);
            $this->refresh();
        }
    }

    /** Indirizzo da aprire nel negozio: le varianti stanno nella pagina del prodotto principale. */
    /** Nome per la vetrina: i nomi importati tutti in maiuscolo («I - BOX COSMETIC COFFEE») diventano «I - Box Cosmetic Coffee». */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return str_contains($name, ' ') && $name === mb_strtoupper($name) && $name !== mb_strtolower($name)
            ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8')
            : $name;
    }

    public function shopUrl(): string
    {
        $base = $this->parent ?? $this;

        return url('/negozio/'.$base->slug).($this->parent_id ? '?variante='.$this->id : '');
    }

    /** Prezzo IVA inclusa, arrotondato al centesimo come lo vede il cliente. */
    public function grossPrice(): string
    {
        return $this->grossPriceFor(null);
    }

    /** Vero se per questo utente vale il prezzo founder: è un incaricato founder e il prodotto ha un prezzo founder. */
    public function hasFounderPriceFor(?User $user): bool
    {
        return $user !== null && $this->founder_net_price !== null && Num::of($this->founder_net_price)->isPositive()
            && (bool) $user->consultant?->is_founder;
    }

    /** Prezzo IVA esclusa che vale per l'utente (null = listino): è quello che finisce nel carrello. */
    public function netPriceFor(?User $user): string
    {
        return (string) ($this->hasFounderPriceFor($user) ? $this->founder_net_price : $this->net_price);
    }

    /** Prezzo IVA inclusa per l'utente: i founder vedono il prezzo founder, tutti gli altri il listino. */
    public function grossPriceFor(?User $user): string
    {
        return (string) Num::money(Num::of($this->netPriceFor($user))->multipliedBy(Num::of('100')->plus(Num::of($this->vat_rate)))->dividedBy(100, 10, RoundingMode::HalfUp));
    }

    /** Prezzo di listino barrato IVA incluso, solo se davvero superiore al prezzo di vendita. */
    public function compareAtGross(): ?string
    {
        if ($this->compare_at_net === null || ! Num::of($this->compare_at_net)->isGreaterThan(Num::of($this->net_price))) {
            return null;
        }

        return (string) Num::money(Num::of($this->compare_at_net)->multipliedBy(Num::of('100')->plus(Num::of($this->vat_rate)))->dividedBy(100, 10, RoundingMode::HalfUp));
    }

    /** Percentuale di risparmio rispetto al prezzo di listino, intera. */
    public function savingPercent(?User $user = null): ?int
    {
        $was = $this->compareAtGross();
        if ($was === null) {
            return null;
        }

        return (int) Num::of($was)->minus(Num::of($this->grossPriceFor($user)))->multipliedBy(100)->dividedBy($was, 0, RoundingMode::HalfUp)->toInt();
    }

    /** @return list<string> percorsi delle immagini, la prima è la copertina */
    public function imagePaths(): array
    {
        return array_values(array_filter((array) $this->images, fn ($p) => is_string($p) && $p !== ''));
    }

    public function coverUrl(): ?string
    {
        $first = $this->imagePaths()[0] ?? null;
        if ($first === null && $this->parent_id) {
            return $this->parent?->coverUrl();
        }

        return $first ? self::mediaUrl($first) : null;
    }

    /** I file si servono da un percorso del sito (/media/…): non serve il collegamento «storage», che su cPanel senza terminale non si può creare. */
    public static function mediaUrl(string $path): string
    {
        return url('/media/'.ltrim($path, '/'));
    }

    /** Un prodotto già venduto, usato in un kit o in un omaggio non si cancella (resta nello storico): si nasconde. */
    public function isDeletable(): bool
    {
        if ($this->parent_id === null && $this->variants()->get()->contains(fn (Product $v) => ! $v->isDeletable())) {
            return false;
        }

        return ! OrderItem::where('product_id', $this->id)->exists()
            && ! KitComponent::where('component_product_id', $this->id)->exists()
            && ! BundleRule::where('gift_product_id', $this->id)->orWhere('trigger_product_id', $this->id)->exists();
    }

    public function tracksStock(): bool
    {
        return $this->stock_qty !== null;
    }

    /** Quante unità si possono ancora vendere (null = senza limite). */
    public function availableQty(): ?int
    {
        if (! $this->tracksStock() || $this->allow_backorder) {
            return null;
        }

        return max(0, (int) $this->stock_qty);
    }

    public function isSoldOut(): bool
    {
        if ($this->hasVariants()) {
            return $this->sellableVariants()->every(fn (Product $v) => $v->isSoldOut());
        }

        return $this->availableQty() === 0;
    }

    public function isLowStock(int $threshold = 5): bool
    {
        $q = $this->availableQty();

        return $q !== null && $q > 0 && $q <= $threshold;
    }

    /** Caratteristiche che una variante eredita dal prodotto principale. */
    public const INHERITED = ['vat_rate', 'category_id', 'countries', 'commission_base', 'commissionable_pct', 'pv_discount_mode', 'shipping_commissionable', 'promo_rank_double_cv'];

    public const BASE_NET = 'net';

    public const BASE_GROSS = 'gross';

    /**
     * PV unitari: valore impostato (fisso) oppure calcolato (RF-040):
     * base (netto o lordo IVA) × quota commissionabile / euro-per-PV. Con i default (netto, 100%) è imponibile / euro-per-PV.
     */
    public function pvUnit(): string
    {
        return (string) ($this->pv !== null ? Num::points($this->pv) : Num::suggestedPv($this->commissionBase(), config('lamess.eur_per_pv')));
    }

    /** Importo su cui si calcolano i PV automatici: prezzo netto o lordo, ridotto alla quota commissionabile. */
    public function commissionBase(): BigDecimal
    {
        $amount = Num::of($this->net_price);
        if (($this->commission_base ?? self::BASE_NET) === self::BASE_GROSS) {
            $amount = $amount->multipliedBy(Num::of('100')->plus(Num::of($this->vat_rate))->dividedBy(100, 10, RoundingMode::HalfUp));
        }
        $pct = Num::of($this->commissionable_pct ?? '100');

        return $amount->multipliedBy($pct)->dividedBy(100, 10, RoundingMode::HalfUp);
    }

    protected static function booted(): void
    {
        static::saving(function (Product $p): void {
            if (blank($p->slug)) {
                $base = Str::slug((string) $p->name) ?: (Str::slug((string) $p->sku) ?: 'prodotto');
                $slug = $base;
                for ($i = 2; self::where('slug', $slug)->where('id', '!=', $p->id ?? 0)->exists(); $i++) {
                    $slug = $base.'-'.$i;
                }
                $p->slug = $slug;
            }
        });
        static::creating(function (Product $p): void {
            if ($p->parent_id !== null && ($parent = $p->parent)) {
                foreach (self::INHERITED as $f) {
                    if ($p->getAttribute($f) === null) {
                        $p->setAttribute($f, $parent->getAttribute($f));
                    }
                }
                if (blank($p->sku)) {
                    $base = Str::upper(Str::limit((string) $parent->sku, 30, '').'-'.Str::slug((string) $p->variant_label));
                    $sku = $base;
                    for ($i = 2; self::where('sku', $sku)->exists(); $i++) {
                        $sku = $base.'-'.$i;
                    }
                    $p->sku = $sku;
                }
            }
        });
        static::saving(function (Product $p): void {
            if ($p->parent_id !== null && ($parent = $p->parent)) {
                $values = array_values(array_filter((array) $p->variant_options, fn ($v) => filled($v)));
                if ($values !== []) {
                    $p->variant_label = implode(' / ', $values);
                }
                $p->name = $parent->name.($p->variant_label ? ' — '.$p->variant_label : '');
                $p->slug = null;
            }
        });
        static::saved(function (Product $p): void {
            if ($p->parent_id !== null) {
                $p->parent?->refreshFromVariants();

                return;
            }
            // Cambiano nome, aliquota, categoria, paesi o parametri commissionali del principale: le varianti seguono.
            if (! $p->wasChanged(array_merge(['name'], self::INHERITED))) {
                return;
            }
            $changed = array_intersect(self::INHERITED, array_keys($p->getChanges()));
            foreach ($p->variants()->get() as $v) {
                foreach ($changed as $f) {
                    $v->setAttribute($f, $p->getAttribute($f));
                }
                $v->save();
            }
        });
        static::deleted(function (Product $p): void {
            foreach ($p->imagePaths() as $path) {
                Storage::disk('local')->delete($path);
            }
            if ($p->parent_id === null) {
                $p->variants()->get()->each->delete();
            } else {
                $p->parent?->refreshFromVariants();
            }
        });
        // Ogni modifica a prezzo, PV/CV o parametri commissionali va nel log con valore precedente e nuovo.
        static::updated(function (Product $p): void {
            $watch = ['net_price', 'vat_rate', 'pv', 'cv', 'promo_rank_double_cv', 'commission_base', 'commissionable_pct', 'pv_discount_mode', 'shipping_commissionable', 'active', 'compare_at_net', 'founder_net_price', 'stock_qty'];
            $changes = [];
            foreach ($watch as $f) {
                if ($p->wasChanged($f)) {
                    $changes[$f] = ['prima' => $p->getOriginal($f), 'dopo' => $p->getAttribute($f)];
                }
            }
            if ($changes !== []) {
                AuditLog::record('product.updated', "Prodotto modificato: {$p->sku}", $p, $changes);
            }
        });
    }

    /** CV unitari: valore impostato oppure PV (doppio con il flag promo rank). */
    public function cvUnit(): string
    {
        if ($this->cv !== null) {
            return (string) Num::points($this->cv);
        }
        $pv = Num::of($this->pvUnit());

        return (string) Num::points($this->promo_rank_double_cv ? $pv->multipliedBy(2) : $pv);
    }

    public const VAP_PERCENT = 'percent';

    public const VAP_FIXED = 'fixed';

    /**
     * V.A.P. del prodotto: [modo, valore] oppure null (= vale la quota V.A.P. del piano).
     * Le varianti usano il proprio se impostato, altrimenti quello del prodotto principale.
     *
     * @return array{0:string,1:string}|null
     */
    public function vapSetting(): ?array
    {
        foreach ([$this, $this->parent] as $p) {
            if ($p !== null && in_array($p->vap_mode, [self::VAP_PERCENT, self::VAP_FIXED], true) && $p->vap_value !== null) {
                return [(string) $p->vap_mode, (string) $p->vap_value];
            }
        }

        return null;
    }

    /** Mesi per cui l'acquisto di questo prodotto rende valido il cliente per l'incaricato (vuoto = 1). */
    public function customerValidityMonths(): int
    {
        foreach ([$this, $this->parent] as $p) {
            if ($p !== null && $p->customer_validity_months !== null && (int) $p->customer_validity_months >= 1) {
                return (int) $p->customer_validity_months;
            }
        }

        return 1;
    }

    /**
     * Mesi di validità di una riga d'ordine da $qty pezzi: con l'interruttore «months_per_piece» (acceso di default,
     * la variante eredita dal padre) i pezzi moltiplicano i mesi, altrimenti vale la durata del prodotto una volta sola.
     */
    public function customerValidityMonthsFor(int $qty): int
    {
        $perPiece = $this->parent !== null ? ($this->months_per_piece ?? $this->parent->months_per_piece ?? true) : ($this->months_per_piece ?? true);

        return $this->customerValidityMonths() * ($perPiece ? max(1, $qty) : 1);
    }

    /** Quanti clienti vale l'acquisto di questo prodotto per l'incaricato (vuoto = 1). */
    public function customerCount(): int
    {
        foreach ([$this, $this->parent] as $p) {
            if ($p !== null && $p->customer_count !== null && (int) $p->customer_count >= 1) {
                return (int) $p->customer_count;
            }
        }

        return 1;
    }

    /** Questo prodotto rende cliente chi lo compra a prescindere dall'importo minimo del piano? (la variante eredita dal padre) */
    public function makesClient(): bool
    {
        return (bool) $this->makes_client || (bool) $this->parent?->makes_client;
    }

    public function sellableIn(string $country): bool
    {
        if ($this->parent_id !== null && ! ($this->parent?->active)) {
            return false;
        }

        return $this->active && ($this->countries === null || $this->countries === [] || in_array($country, $this->countries, true));
    }
}
