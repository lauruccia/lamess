<?php

declare(strict_types=1);

namespace App\Filament\Resources\IvdContracts\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\IvdContracts\IvdContractResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditIvdContract extends SuperadminEditRecord
{
    protected static string $resource = IvdContractResource::class;
}
