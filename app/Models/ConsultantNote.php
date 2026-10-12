<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\Superadmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Nota interna di un admin sull'incaricato. Si aggiunge e si fissa in alto; non si modifica né si cancella da interfaccia. */
class ConsultantNote extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(static function (): void {
            if (! Superadmin::is()) {
                throw new LogicException('Le note interne non si cancellano.');
            }
        });
        static::updating(static function (self $n): void {
            // si può solo fissare/sbloccare in alto
            if (($n->isDirty('body') || $n->isDirty('consultant_id') || $n->isDirty('author_label')) && ! Superadmin::is()) {
                throw new LogicException('Le note interne non si modificano.');
            }
        });
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }
}
