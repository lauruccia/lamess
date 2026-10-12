<?php

declare(strict_types=1);

namespace App\Filament\Resources\BundleRules\Pages;

use App\Filament\Resources\BundleRules\BundleRuleResource;
use Filament\Actions\CreateAction;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListBundleRules extends SuperadminListRecords
{
    protected static string $resource = BundleRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuova regola')];
    }
}
