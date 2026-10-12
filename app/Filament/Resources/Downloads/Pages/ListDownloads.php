<?php

declare(strict_types=1);

namespace App\Filament\Resources\Downloads\Pages;

use App\Filament\Resources\Downloads\DownloadResource;
use Filament\Actions\CreateAction;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListDownloads extends SuperadminListRecords
{
    protected static string $resource = DownloadResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
