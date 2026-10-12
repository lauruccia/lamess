<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCases\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\ComplianceCases\ComplianceCaseResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditComplianceCase extends SuperadminEditRecord
{
    protected static string $resource = ComplianceCaseResource::class;
}
