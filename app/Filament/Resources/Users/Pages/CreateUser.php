<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\UserAdministration;
use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Nuovo admin';

    private ?string $temporaryPassword = null;

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(UserAdministration::class);
        $result = $service->createAdmin((string) $data['name'], (string) $data['email'], PermissionCatalog::flatten($data['perm'] ?? null));
        $this->temporaryPassword = $result['password'];
        $service->setMenu($result['user'], UserAdministration::flattenMenu($data['menu'] ?? null));

        return $result['user'];
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->persistent()
            ->title('Admin creato — password temporanea (mostrata una sola volta)')
            ->body((string) $this->temporaryPassword);
    }
}
