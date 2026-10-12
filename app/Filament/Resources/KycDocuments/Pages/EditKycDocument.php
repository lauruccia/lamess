<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocuments\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\KycDocuments\KycDocumentResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditKycDocument extends SuperadminEditRecord
{
    protected static string $resource = KycDocumentResource::class;
}
