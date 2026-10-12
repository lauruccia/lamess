<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Superadmin;
use App\Domain\System\CascadeDeleter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/** Azioni «Modifica» ed «Elimina» riservate al super_admin, uguali per ogni risorsa del back-office. */
final class SuperadminActions
{
    public const CONFIRM_WORD = 'ELIMINA';

    /** @param class-string<\Filament\Resources\Resource> $resource */
    public static function decorate(Table $table, string $resource): Table
    {
        if (! Superadmin::is()) {
            return $table;
        }
        $hasEdit = false;
        foreach ($table->getFlatActions() as $existing) {
            if ($existing->getName() === 'edit') {
                $hasEdit = true;
            }
            // l'eliminazione semplice della risorsa si ferma davanti ai dati collegati: al super_admin si mostra quella a cascata
            if ($existing instanceof DeleteAction || $existing instanceof DeleteBulkAction) {
                $existing->hidden(fn (): bool => Superadmin::is());
            }
        }
        $actions = [];
        if ($resource::hasPage('edit') && ! $hasEdit) {
            $actions[] = self::edit($resource);
        }
        $actions[] = self::delete($resource);
        $table->pushRecordActions($actions);
        $table->pushToolbarActions([self::bulkDelete($resource)]);

        return $table;
    }

    /** @param class-string<\Filament\Resources\Resource> $resource */
    public static function edit(string $resource): Action
    {
        return Action::make('superadminEdit')->label('Modifica')->iconButton()->tooltip('Modifica')->icon('heroicon-o-pencil-square')
            ->url(fn (Model $record): string => $resource::getUrl('edit', ['record' => $record]))
            ->visible(fn (Model $record): bool => Superadmin::is() && $resource::canEdit($record));
    }

    /** @param class-string<\Filament\Resources\Resource> $resource */
    public static function delete(string $resource): Action
    {
        return Action::make('superadminDelete')->label('Elimina')->iconButton()->tooltip('Elimina')->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (Model $record): bool => Superadmin::is() && $resource::canDelete($record))
            ->modalHeading('Eliminare definitivamente?')
            ->modalDescription(fn (Model $record): string => CascadeDeleter::describe($record))
            ->modalSubmitActionLabel('Elimina')
            ->schema(fn (Model $record): array => CascadeDeleter::hasDependents($record) ? [self::confirmField()] : [])
            ->requiresConfirmation()
            ->action(function (Model $record, Action $action): void {
                try {
                    CascadeDeleter::delete($record);
                    Notification::make()->title('Eliminato')->success()->send();
                    $action->success();
                } catch (Throwable $e) {
                    Notification::make()->title('Eliminazione non riuscita')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    /** @param class-string<\Filament\Resources\Resource> $resource */
    public static function bulkDelete(string $resource): BulkAction
    {
        return BulkAction::make('superadminBulkDelete')->label('Elimina selezionati')->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (): bool => Superadmin::is())
            ->requiresConfirmation()
            ->modalHeading('Eliminare definitivamente i record selezionati?')
            ->modalDescription('L\'eliminazione è definitiva. Insieme a ogni record vengono eliminati i dati che ne dipendono (il dettaglio resta nel log di audit).')
            ->schema([self::confirmField()])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($resource): void {
                $done = 0;
                $failed = [];
                foreach ($records as $record) {
                    try {
                        abort_unless($resource::canDelete($record), 403);
                        CascadeDeleter::delete($record);
                        $done++;
                    } catch (Throwable $e) {
                        $failed[] = '#'.$record->getKey().': '.$e->getMessage();
                    }
                }
                $n = Notification::make()->title("{$done} eliminati");
                $failed === [] ? $n->success() : $n->warning()->body('Non eliminati — '.implode(' · ', $failed));
                $n->send();
            });
    }

    private static function confirmField(): TextInput
    {
        return TextInput::make('conferma')->label('Scrivi '.self::CONFIRM_WORD.' per confermare')->required()
            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) !== self::CONFIRM_WORD) {
                    $fail('Scrivi '.self::CONFIRM_WORD.' per confermare.');
                }
            });
    }
}
