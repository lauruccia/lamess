<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportReconciliations\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\ImportReconciliations\ImportReconciliationResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditImportReconciliation extends SuperadminEditRecord
{
    protected static string $resource = ImportReconciliationResource::class;
}
