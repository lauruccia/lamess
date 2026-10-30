<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pagina personale pubblica di un incaricato. Il contenuto vero sta nei campi; `proposal` è la modifica in attesa di approvazione. */
class ConsultantPage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['media' => 'array', 'proposal' => 'array', 'enabled' => 'boolean', 'blocked' => 'boolean', 'approved_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }
}
