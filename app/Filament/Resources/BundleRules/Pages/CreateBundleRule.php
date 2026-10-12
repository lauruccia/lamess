<?php

declare(strict_types=1);

namespace App\Filament\Resources\BundleRules\Pages;

use App\Filament\Resources\BundleRules\BundleRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBundleRule extends CreateRecord
{
    protected static string $resource = BundleRuleResource::class;
}
