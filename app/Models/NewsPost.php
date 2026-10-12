<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Novità per la rete e per i clienti. */
class NewsPost extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    public const AUDIENCES = ['all' => 'Tutti', 'consultants' => 'Solo incaricati', 'fans' => 'Solo clienti e FAN'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'pinned' => 'boolean'];
    }

    /** Pubblicate e già in uscita. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', 'published')->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
