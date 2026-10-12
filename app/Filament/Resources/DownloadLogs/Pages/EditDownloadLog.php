<?php

declare(strict_types=1);

namespace App\Filament\Resources\DownloadLogs\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\DownloadLogs\DownloadLogResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditDownloadLog extends SuperadminEditRecord
{
    protected static string $resource = DownloadLogResource::class;
}
