<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\PasswordResetLinks;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PasswordResetAction;
use Filament\Actions\CreateAction;

class ListUsers extends SuperadminListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [PasswordResetAction::all(PasswordResetLinks::AUDIENCE_BOTH), CreateAction::make()->label('Nuovo admin')];
    }
}
