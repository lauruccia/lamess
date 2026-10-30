<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsultantPages\Pages;

use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\ConsultantPages\ConsultantPageResource;

class ListConsultantPages extends SuperadminListRecords
{
    protected static string $resource = ConsultantPageResource::class;
}
