<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Consultants\ConsultantResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditConsultant extends SuperadminEditRecord
{
    protected static string $resource = ConsultantResource::class;

    protected static array $excludeColumns = ['sponsor_id'];
}
