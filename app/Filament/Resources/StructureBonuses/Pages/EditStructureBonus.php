<?php

declare(strict_types=1);

namespace App\Filament\Resources\StructureBonuses\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\StructureBonuses\StructureBonusResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditStructureBonus extends SuperadminEditRecord
{
    protected static string $resource = StructureBonusResource::class;
}
