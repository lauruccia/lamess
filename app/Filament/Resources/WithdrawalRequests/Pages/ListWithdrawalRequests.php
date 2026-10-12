<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalRequests\Pages;

use App\Filament\Resources\WithdrawalRequests\WithdrawalRequestResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListWithdrawalRequests extends SuperadminListRecords
{
    protected static string $resource = WithdrawalRequestResource::class;
}
