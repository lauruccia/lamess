<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** Stati in cui l'ordine è stato pagato e non è (del tutto) reso: servono per i totali e per i resi. */
    public const PAID_STATES = ['paid', 'processing', 'shipped', 'delivered', 'partially_refunded'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal_net' => 'decimal:2', 'shipping_net' => 'decimal:2', 'shipping_vat_rate' => 'decimal:2', 'vat_total' => 'decimal:2', 'total' => 'decimal:2', 'kmoney_amount' => 'decimal:2',
            'pv_total' => 'decimal:2', 'cv_total' => 'decimal:2', 'paid_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'billing' => 'array', 'discount_net' => 'decimal:2', 'shipping_discount_net' => 'decimal:2', 'discounts' => 'array',
        ];
    }

    /** Importo ancora da pagare con carta/bonifico: il totale meno la quota già addebitata in KMoney. */
    public function restDue(): string
    {
        $rest = \Brick\Math\BigDecimal::of((string) $this->total)->minus((string) ($this->kmoney_amount ?? 0));

        return (string) $rest->toScale(2, \Brick\Math\RoundingMode::HalfUp);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'beneficiary_consultant_id');
    }
}
