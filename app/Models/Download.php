<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Materiale scaricabile (file privato, filigrana col nome di chi lo scarica). */
class Download extends Model
{
    protected $guarded = [];

    protected $attributes = ['active' => true, 'watermark' => true, 'audience' => 'consultants'];

    /** @var array<string, string> */
    public const AUDIENCES = ['consultants' => 'Solo incaricati', 'all' => 'Tutti gli utenti registrati'];

    protected function casts(): array
    {
        return ['watermark' => 'boolean', 'active' => 'boolean', 'size' => 'integer'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DownloadLog::class);
    }
}
