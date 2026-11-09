<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipApplications\Pages;

use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;

class ListMembershipApplications extends SuperadminListRecords
{
    protected static string $resource = MembershipApplicationResource::class;
}
