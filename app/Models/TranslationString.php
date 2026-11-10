<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\I18n\Locales;
use Illuminate\Database\Eloquent\Model;

/**
 * Un testo in una lingua. `source` è il testo italiano così com'è scritto nelle viste (è anche la chiave di ricerca).
 *
 * @property int $id
 * @property string $locale
 * @property string $key_hash
 * @property string $source
 * @property string|null $text
 * @property string $status missing | auto | approved
 * @property bool $orphan
 */
class TranslationString extends Model
{
    public const MISSING = 'missing';

    public const AUTO = 'auto';

    public const APPROVED = 'approved';

    protected $fillable = ['locale', 'key_hash', 'source', 'text', 'status', 'orphan'];

    protected function casts(): array
    {
        return ['orphan' => 'boolean'];
    }

    public static function hashOf(string $source): string
    {
        return md5($source);
    }

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            if ($row->key_hash === null || $row->isDirty('source')) {
                $row->key_hash = self::hashOf((string) $row->source);
            }
        });
        // ogni modifica svuota la cache della lingua, così il sito la mostra subito
        static::saved(fn (self $row) => Locales::forgetStrings($row->locale));
        static::deleted(fn (self $row) => Locales::forgetStrings($row->locale));
    }
}
