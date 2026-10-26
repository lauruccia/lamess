<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalDocument extends Model
{
    protected $guarded = [];

    public function edits(): HasMany
    {
        return $this->hasMany(LegalDocumentEdit::class)->orderByDesc('id');
    }
}
