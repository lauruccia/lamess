<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListSubscriptions extends SuperadminListRecords
{
    protected static string $resource = SubscriptionResource::class;
}
