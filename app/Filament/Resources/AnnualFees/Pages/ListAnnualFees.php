<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnnualFees\Pages;

use App\Filament\Resources\AnnualFees\AnnualFeeResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListAnnualFees extends SuperadminListRecords
{
    protected static string $resource = AnnualFeeResource::class;
}
