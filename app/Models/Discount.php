<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sconto del negozio: con codice (lo digita il cliente) o automatico (scatta da solo).
 * Importi «fixed» e «min_subtotal» sono IVA inclusa, come li vede il cliente.
 */
class Discount extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const TIERED = 'tiered';

    public const FREE_SHIPPING = 'free_shipping';

    public const TYPES = [
        self::PERCENT => 'Percentuale',
        self::FIXED => 'Importo fisso (€)',
        self::TIERED => 'Scaglioni di quantità',
        self::FREE_SHIPPING => 'Spedizione gratuita',
    ];

    public const AUDIENCES = [
        'all' => 'Tutti',
        'customers' => 'Solo clienti',
        'consultants' => 'Solo incaricati',
        'first_order' => 'Solo primo ordine',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2', 'min_subtotal' => 'decimal:2', 'tiers' => 'array', 'product_ids' => 'array', 'category_ids' => 'array',
            'combinable' => 'boolean', 'active' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function isAutomatic(): bool
    {
        return blank($this->code);
    }

    public function isWithinDates(\DateTimeInterface $at): bool
    {
        $now = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($at));

        return ($this->starts_at === null || $now->gte($this->starts_at)) && ($this->ends_at === null || $now->lte($this->ends_at));
    }

    /** Stato leggibile per l'elenco del back-office. */
    public function statusLabel(?\DateTimeInterface $at = null): string
    {
        $at ??= now();
        if (! $this->active) {
            return 'Disattivato';
        }
        if ($this->starts_at && $this->starts_at->gt($at)) {
            return 'Programmato';
        }
        if ($this->ends_at && $this->ends_at->lt($at)) {
            return 'Scaduto';
        }
        if ($this->usage_limit !== null && $this->redemptions()->count() >= $this->usage_limit) {
            return 'Esaurito';
        }

        return 'Attivo';
    }

    protected static function booted(): void
    {
        static::saving(function (Discount $d): void {
            $d->code = filled($d->code) ? strtoupper(preg_replace('/\s+/', '', (string) $d->code)) : null;
        });
        // Gli sconti incidono sugli importi: ogni creazione, modifica o cancellazione va nel log.
        static::created(fn (Discount $d) => AuditLog::record('discount.created', "Sconto creato: {$d->name}", $d, ['tipo' => $d->type, 'valore' => (string) $d->value, 'codice' => $d->code]));
        static::updated(function (Discount $d): void {
            $changes = [];
            foreach ($d->getChanges() as $f => $v) {
                if ($f !== 'updated_at') {
                    $changes[$f] = ['prima' => $d->getOriginal($f), 'dopo' => $v];
                }
            }
            if ($changes !== []) {
                AuditLog::record('discount.updated', "Sconto modificato: {$d->name}", $d, $changes);
            }
        });
        static::deleted(fn (Discount $d) => AuditLog::record('discount.deleted', "Sconto eliminato: {$d->name}", $d));
    }
}
