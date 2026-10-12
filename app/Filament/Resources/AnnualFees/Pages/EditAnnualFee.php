<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnnualFees\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\AnnualFees\AnnualFeeResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditAnnualFee extends SuperadminEditRecord
{
    protected static string $resource = AnnualFeeResource::class;
}
