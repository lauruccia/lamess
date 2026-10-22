<?php

declare(strict_types=1);

namespace App\Filament\Resources\BundleRules\Pages;

use App\Filament\Pages\Base\StyledEditPage;
use App\Filament\Resources\BundleRules\BundleRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBundleRule extends EditRecord
{
    use StyledEditPage;

    protected static string $resource = BundleRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
