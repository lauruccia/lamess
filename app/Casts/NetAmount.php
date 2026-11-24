<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Engine\Support\Num;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Importo netto con la precisione che serve: fino a 8 decimali, ma almeno 2 (5.00 resta «5.00», 10.6557377 resta esatto).
 * Serve alla spedizione: il prezzo si decide IVA inclusa (es. 13,00 €) e il netto non deve perdere i decimali.
 */
final class NetAmount implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) Num::netExact((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : (string) Num::netExact((string) $value);
    }
}
