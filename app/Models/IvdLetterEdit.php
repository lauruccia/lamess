<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvdLetterEdit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function version(): BelongsTo
    {
        return $this->belongsTo(IvdLetterVersion::class, 'ivd_letter_version_id');
    }
}
