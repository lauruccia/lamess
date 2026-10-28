<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Filament\Pages\Base\StyledEditPage;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use StyledEditPage;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['perm'] = PermissionCatalog::splitByArea($this->record->getDirectPermissions()->pluck('name')->all());
        $data['menu'] = app(UserAdministration::class)->menuState($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(UserAdministration::class);
        $service->updateProfile($record, (string) $data['name'], (string) $data['email']);
        if ($record->hasRole(Roles::ADMIN)) {
            $service->setPermissions($record, PermissionCatalog::flatten($data['perm'] ?? null));
            $service->setMenu($record, UserAdministration::flattenMenu($data['menu'] ?? null));
        }

        return $record;
    }
}
