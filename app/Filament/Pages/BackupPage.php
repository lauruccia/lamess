<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\System\BackupService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Backup del database — SOLO super_admin. */
class BackupPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Backup';

    protected static ?string $title = 'Backup del database';

    protected static ?string $slug = 'backup';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.backup';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Crea backup ora')->icon('heroicon-o-arrow-down-on-square')->requiresConfirmation()
                ->modalDescription('Crea una copia completa del database. Le ultime 14 copie restano sul server.')
                ->action(function (BackupService $backup): void {
                    try {
                        $r = $backup->create('manuale');
                        Notification::make()->title('Backup creato')->body($r['name'])->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup non riuscito')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['backups' => app(BackupService::class)->list()];
    }
}
