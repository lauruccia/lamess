<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Categoria («collezione») del negozio: raggruppa i prodotti nella vetrina e nelle regole di sconto.
 * Può avere sottocategorie, su un solo livello (categoria → sottocategoria): `parent_id` punta alla categoria madre.
 */
class ProductCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** Solo le categorie principali (senza madre). */
    public function scopeRoots(Builder $q): void
    {
        $q->whereNull($q->qualifyColumn('parent_id'));
    }

    /** Ordine ad albero: ogni categoria principale seguita dalle sue sottocategorie, per posizione e nome. */
    public function scopeInTreeOrder(Builder $q): void
    {
        $t = $q->getModel()->getTable();
        $q->orderByRaw("coalesce((select p.sort_order from {$t} as p where p.id = {$t}.parent_id), {$t}.sort_order)")
            ->orderByRaw("coalesce((select p.name from {$t} as p where p.id = {$t}.parent_id), {$t}.name)")
            ->orderByRaw("coalesce({$t}.parent_id, {$t}.id)")
            ->orderByRaw("case when {$t}.parent_id is null then 0 else 1 end")
            ->orderBy("{$t}.sort_order")
            ->orderBy("{$t}.name");
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }

    /** «Viso › Creme» per una sottocategoria, «Viso» per una principale. */
    public function fullName(): string
    {
        return $this->parent_id !== null && $this->parent ? $this->parent->name.' › '.$this->name : (string) $this->name;
    }

    /** Visibile in vetrina: attiva, e se è una sottocategoria anche la madre lo è. */
    public function isShownInShop(): bool
    {
        return $this->active && ($this->parent_id === null || (bool) $this->parent?->active);
    }

    /**
     * Aggiunge alle categorie indicate le loro sottocategorie (uno sconto sulla categoria vale anche per le sottocategorie).
     *
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    public static function withChildIds(iterable $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : iterator_to_array($ids))));
        if ($ids === []) {
            return [];
        }
        $children = self::whereIn('parent_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge($ids, $children)));
    }

    /**
     * Elenco per i menu a tendina, in ordine ad albero: «Viso», «Viso › Creme»…
     *
     * @return array<int,string>
     */
    public static function treeOptions(): array
    {
        return self::query()->with('parent')->inTreeOrder()->get()->mapWithKeys(fn (self $c) => [$c->id => $c->fullName()])->all();
    }

    /**
     * Le sole categorie principali, scelte possibili come madre (esclusa quella che si sta modificando).
     *
     * @return array<int,string>
     */
    public static function rootOptions(?int $exceptId = null): array
    {
        return self::query()->roots()->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    protected static function booted(): void
    {
        static::saving(function (ProductCategory $c): void {
            if ($c->parent_id !== null && (! $c->exists || $c->isDirty('parent_id'))) {
                if ($c->exists && (int) $c->parent_id === (int) $c->id) {
                    throw new DomainException('Una categoria non può essere madre di sé stessa.');
                }
                $parent = self::find($c->parent_id);
                if ($parent === null || $parent->parent_id !== null) {
                    throw new DomainException('Le sottocategorie hanno un solo livello: scegli una categoria principale come madre.');
                }
                if ($c->exists && $c->children()->exists()) {
                    throw new DomainException('Questa categoria ha delle sottocategorie: non può diventare a sua volta una sottocategoria.');
                }
            }
            if (blank($c->slug)) {
                $base = Str::slug((string) $c->name) ?: 'categoria';
                $slug = $base;
                for ($i = 2; self::where('slug', $slug)->where('id', '!=', $c->id ?? 0)->exists(); $i++) {
                    $slug = $base.'-'.$i;
                }
                $c->slug = $slug;
            }
        });
    }
}
