<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants\Pages;

use App\Domain\Network\ConsultantAccess;
use App\Domain\Network\ConsultantCodeGenerator;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Support\ConsultantAccessAction;
use App\Models\Consultant;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateConsultant extends CreateRecord
{
    protected static string $resource = ConsultantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $consultant = app(NetworkService::class)->enroll(
                ['code' => auth()->user()?->isSuperAdmin() === true && filled($data['code'] ?? null) ? $data['code'] : app(ConsultantCodeGenerator::class)->next(), 'name' => $data['name'], 'person_key' => $data['person_key'] ?? null],
                ! empty($data['sponsor_id']) ? Consultant::findOrFail($data['sponsor_id']) : null,
                new DateTimeImmutable((string) $data['since'], new DateTimeZone('UTC')),
            );
        } catch (NetworkException $e) {
            Notification::make()->title('Iscrizione non possibile')->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        if (filled($data['email'] ?? null)) {
            try {
                $access = app(ConsultantAccess::class);
                $access->grant($consultant, (string) $data['email']);
                ConsultantAccessAction::notifyOutcome((string) $data['email'], $access->inviteSent);
            } catch (NetworkException $e) {
                Notification::make()->title('Incaricato creato, ma accesso non creato')->body($e->getMessage().' Puoi crearlo dalla scheda con «Crea accesso».')->warning()->persistent()->send();
            }
        }

        return $consultant;
    }
}
