<?php

declare(strict_types=1);

namespace App\Filament\Resources\IvdContracts\Pages;

use App\Filament\Resources\IvdContracts\IvdContractResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListIvdContracts extends SuperadminListRecords
{
    protected static string $resource = IvdContractResource::class;
}
