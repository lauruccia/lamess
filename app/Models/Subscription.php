<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $guarded = [];

    protected $hidden = ['stripe_customer_id', 'stripe_payment_method_id'];

    /** In attesa del primo pagamento sulla pagina di Stripe: la carta non è ancora salvata. */
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const METHOD_CARD = 'card';

    public const METHOD_KMONEY = 'kmoney';

    public const PAUSED = 'paused';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'lines' => 'array', 'for_others' => 'array', 'billing' => 'array', 'free_shipping' => 'boolean', 'early_cancel' => 'boolean',
            'started_at' => 'immutable_datetime', 'next_run_at' => 'immutable_datetime', 'reminded_for' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** Ogni quanti mesi parte un ordine (1 = mensile, 2 = bimestrale). */
    public function intervalMonths(): int
    {
        return max(1, (int) $this->interval_months);
    }

    /** «al mese» / «ogni 2 mesi», per le frasi del tipo «1× al mese». */
    /**
     * Cadenze che il cliente può scegliere ora (in base alle impostazioni): codice del checkout => ogni quanti mesi.
     *
     * @return array<string,int>
     */
    public static function offeredCadences(): array
    {
        return array_filter([
            'monthly' => config('lamess.subscription.monthly_enabled', true) ? 1 : null,
            'bimonthly' => config('lamess.subscription.bimonthly_enabled', true) ? 2 : null,
        ]);
    }

    public static function isOffered(): bool
    {
        return self::offeredCadences() !== [];
    }

    public function cadenceLabel(): string
    {
        return $this->intervalMonths() === 1 ? 'al mese' : 'ogni '.$this->intervalMonths().' mesi';
    }

    /** Numero di ordini che coprono l'impegno (impegno in mesi diviso la cadenza, arrotondato per eccesso); 0 = nessun impegno. */
    public function commitmentCycles(): int
    {
        return (int) ceil((int) $this->commitment_months / $this->intervalMonths());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
