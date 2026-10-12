<?php

declare(strict_types=1);

namespace App\Filament\Resources\DownloadLogs\Pages;

use App\Filament\Resources\DownloadLogs\DownloadLogResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListDownloadLogs extends SuperadminListRecords
{
    protected static string $resource = DownloadLogResource::class;
}
