<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    /**
     * Solo i clienti "puri": esclude chi è incaricato (non cessato). Il record cliente dell'incaricato
     * resta nel database (serve agli ordini personali e al piano), ma non va negli elenchi/conteggi clienti.
     * Un incaricato cessato torna cliente (vedi CustomerService::ensureCustomer).
     */
    public function scopeNotConsultants(Builder $query): Builder
    {
        // NOT EXISTS correlato (user_id è univoco su consultants): niente NOT IN con sottoquery da rivalutare
        return $query->whereNotExists(fn ($e) => $e->from('consultants as c_not')->selectRaw('1')
            ->whereColumn('c_not.user_id', 'customers.user_id')->where('c_not.status', '!=', 'terminated'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_user_id', 'user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id', 'user_id');
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'assigned_consultant_id');
    }
}
