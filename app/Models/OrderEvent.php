<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Una riga della cronologia di un ordine. Le note interne (internal) non le vede mai il cliente. */
class OrderEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['internal' => 'boolean', 'meta' => 'array'];
    }

    /** @param array<string,mixed>|null $meta */
    public static function log(Order $order, string $type, string $message, bool $internal = false, ?array $meta = null): self
    {
        return self::create(['order_id' => $order->id, 'type' => $type, 'message' => $message, 'internal' => $internal, 'user_id' => Auth::id(), 'meta' => $meta]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
