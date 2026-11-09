<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsultantPages\Pages;

use App\Domain\Access\Roles;
use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPageService;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\ConsultantPages\ConsultantPageResource;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

class ListConsultantPages extends SuperadminListRecords
{
    protected static string $resource = ConsultantPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeForConsultant')->label('Attiva gratis per un incaricato')->icon('heroicon-o-gift')
                ->visible(fn () => auth()->user()?->hasRole(Roles::SUPER_ADMIN) === true)
                ->modalDescription('Per un incaricato che non ha ancora aperto «La mia pagina»: la sua pagina viene creata e attivata, senza pagamento.')
                ->schema([
                    Select::make('consultant_id')->label('Incaricato')->required()->searchable()
                        ->options(fn () => Consultant::query()->where('status', 'active')->orderBy('name')->get()->mapWithKeys(fn (Consultant $c) => [$c->id => $c->name.' ('.$c->code.')'])->all()),
                    Toggle::make('no_expiry')->label('Senza scadenza')->helperText('Spento: valida per i mesi previsti, poi va rinnovata.'),
                    Textarea::make('note')->label('Motivo (facoltativo)')->rows(2),
                ])
                ->action(function (array $data): void {
                    abort_unless(auth()->user()?->hasRole(Roles::SUPER_ADMIN) === true, 403);
                    $svc = app(PersonalPageService::class);
                    try {
                        $c = Consultant::query()->findOrFail($data['consultant_id']);
                        if (! $svc->eligible($c)) {
                            throw new PageException('L\'incaricato non è attivo: la pagina non si può attivare.');
                        }
                        $svc->activate($svc->forConsultant($c), 'free', $data['note'] ?? null, (bool) ($data['no_expiry'] ?? false));
                        Notification::make()->title('Pagina attivata gratuitamente')->success()->send();
                    } catch (PageException $e) {
                        Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
