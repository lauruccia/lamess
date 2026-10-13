<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Plan\LegacyPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Interruttore a un click: nasconde (o rimostra) tutti i riferimenti al vecchio piano di marketing.
 * Non cancella nulla: vedi {@see LegacyPlan}. Accesso: super_admin e chi gestisce il piano compensi.
 */
class LegacyPlanPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEyeSlash;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $navigationLabel = 'Visibilità piano precedente';

    protected static ?string $title = 'Visibilità del piano precedente';

    protected static ?string $slug = 'piano-precedente';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.legacy-plan';

    public static function canAccess(): bool
    {
        return LegacyPlan::canToggle();
    }

    protected function getHeaderActions(): array
    {
        $hidden = LegacyPlan::hidden();

        return [
            Action::make('toggle')
                ->label($hidden ? 'Mostra di nuovo il piano precedente' : 'Nascondi il piano precedente')
                ->icon($hidden ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                ->color($hidden ? 'gray' : 'danger')
                ->requiresConfirmation()
                ->modalHeading($hidden ? 'Mostrare di nuovo il piano precedente?' : 'Nascondere il piano precedente?')
                ->modalDescription($hidden
                    ? 'Menu, schede, colonne e documenti del piano precedente tornano visibili a tutti.'
                    : 'Da subito nessuno vedrà più riferimenti al piano precedente, nemmeno gli amministratori. Non si cancella nulla: i dati restano e puoi rimostrarli quando vuoi da questa stessa pagina.')
                ->modalSubmitActionLabel($hidden ? 'Mostra' : 'Nascondi')
                ->authorize(fn (): bool => LegacyPlan::canToggle())
                ->action(function () use ($hidden): void {
                    $hidden ? LegacyPlan::show() : LegacyPlan::hide();
                    Notification::make()->title($hidden ? 'Piano precedente di nuovo visibile' : 'Piano precedente nascosto')->success()->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['hidden' => LegacyPlan::hidden()];
    }
}
