<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocuments\Pages;

use App\Filament\Resources\KycDocuments\KycDocumentResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListKycDocuments extends SuperadminListRecords
{
    protected static string $resource = KycDocumentResource::class;
}
