<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sanctions\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Sanctions\SanctionResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditSanction extends SuperadminEditRecord
{
    protected static string $resource = SanctionResource::class;
}
