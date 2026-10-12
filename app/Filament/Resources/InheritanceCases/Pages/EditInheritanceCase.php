<?php

declare(strict_types=1);

namespace App\Filament\Resources\InheritanceCases\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\InheritanceCases\InheritanceCaseResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditInheritanceCase extends SuperadminEditRecord
{
    protected static string $resource = InheritanceCaseResource::class;
}
