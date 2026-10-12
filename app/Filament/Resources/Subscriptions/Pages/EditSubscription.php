<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Subscriptions\SubscriptionResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditSubscription extends SuperadminEditRecord
{
    protected static string $resource = SubscriptionResource::class;
}
