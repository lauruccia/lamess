<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lingua del sito. L'italiano è la lingua sorgente e predefinita; le altre nascono in bozza e il sito le mostra
 * ai visitatori solo da attive.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string|null $flag
 * @property string $status
 * @property bool $is_default
 * @property int $sort
 */
class Language extends Model
{
    public const ACTIVE = 'active';

    public const DRAFT = 'draft';

    protected $fillable = ['code', 'name', 'native_name', 'flag', 'status', 'is_default', 'sort'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'sort' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
