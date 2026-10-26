<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una versione del testo della Lettera di Incarico (l'ultima pubblicata è «in vigore»). */
class IvdLetterVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    public function edits(): HasMany
    {
        return $this->hasMany(IvdLetterEdit::class)->orderByDesc('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(IvdLetterSignature::class);
    }
}
