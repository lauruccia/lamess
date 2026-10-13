<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportReconciliations;

use App\Domain\Audit\AuditLog;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ImportReconciliations\Pages\EditImportReconciliation;
use App\Filament\Resources\ImportReconciliations\Pages\ListImportReconciliations;
use App\Models\ImportReconciliation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class ImportReconciliationResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'orders.view';
    }

    protected static function managePermission(): string
    {
        return 'orders.manage';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static ?string $model = ImportReconciliation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'differenza di importazione';

    protected static ?string $pluralModelLabel = 'Riconciliazione Odoo';

    protected static ?int $navigationSort = 16;

    public static function getNavigationBadge(): ?string
    {
        $n = ImportReconciliation::query()->where('status', 'open')->whereIn('severity', ['error', 'warning'])->count();

        return $n > 0 ? (string) $n : null;
    }

    /** @param  iterable<ImportReconciliation>  $records */
    private static function accept(iterable $records, string $note): int
    {
        $n = 0;
        foreach ($records as $r) {
            if ($r->status === 'accepted') {
                continue;
            }
            $r->update(['status' => 'accepted', 'resolution_note' => $note !== '' ? $note : null, 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
            AuditLog::record('import.reconciliation_accepted', "Differenza Odoo approvata: {$r->subject_ref}", $r, ['stato' => ['prima' => 'aperta', 'dopo' => 'approvata']], $note !== '' ? $note : null);
            $n++;
        }

        return $n;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('severity')->defaultPaginationPageOption(25)->columns([
            TextColumn::make('severity')->label('Gravità')->badge()->sortable()
                ->formatStateUsing(fn (string $state) => ['error' => 'Bloccante', 'warning' => 'Da verificare', 'info' => 'Informativa'][$state] ?? $state)
                ->color(fn (string $state) => ['error' => 'danger', 'warning' => 'warning', 'info' => 'gray'][$state] ?? 'gray'),
            TextColumn::make('code')->label('Tipo')->formatStateUsing(fn (string $state) => LegacyPlan::scrub(ImportReconciliation::CODES[$state] ?? $state))->sortable(),
            TextColumn::make('subject_ref')->label('Riferimento')->searchable()->sortable(),
            TextColumn::make('subject_name')->label('Nome')->searchable()->limit(28),
            TextColumn::make('message')->label('Dettaglio')->wrap()->limit(140)->formatStateUsing(fn (?string $state): ?string => $state === null ? null : LegacyPlan::scrub($state))->tooltip(fn (ImportReconciliation $r) => LegacyPlan::scrub((string) $r->message)),
            TextColumn::make('status')->label('Stato')->badge()
                ->formatStateUsing(fn (string $state) => $state === 'accepted' ? 'Approvata' : 'Aperta')
                ->color(fn (string $state) => $state === 'accepted' ? 'success' : 'warning'),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['open' => 'Aperte', 'accepted' => 'Approvate'])->default('open'),
            SelectFilter::make('severity')->label('Gravità')->options(['error' => 'Bloccanti', 'warning' => 'Da verificare', 'info' => 'Informative']),
            SelectFilter::make('code')->label('Tipo')->options(array_map(fn (string $l): string => LegacyPlan::scrub($l), ImportReconciliation::CODES)),
        ])->recordActions([
            Action::make('accept')->authorize('orders.manage')->label('Approva')->iconButton()->tooltip('Approva')->icon('heroicon-o-check')->color('success')
                ->visible(fn (ImportReconciliation $r) => $r->status === 'open')
                ->schema([Textarea::make('note')->label('Nota (facoltativa)')->rows(2)])
                ->action(function (ImportReconciliation $record, array $data): void {
                    self::accept([$record], (string) ($data['note'] ?? ''));
                    Notification::make()->title('Differenza approvata')->success()->send();
                }),
        ])->toolbarActions([
            BulkAction::make('acceptMany')->authorize('orders.manage')->label('Approva selezionate')->icon('heroicon-o-check')->color('success')
                ->schema([Textarea::make('note')->label('Nota (facoltativa)')->rows(2)])
                ->deselectRecordsAfterCompletion()
                ->action(function (Collection $records, array $data): void {
                    $n = self::accept($records, (string) ($data['note'] ?? ''));
                    Notification::make()->title("{$n} differenze approvate")->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListImportReconciliations::route('/'), 'edit' => EditImportReconciliation::route('/{record}/edit')];
    }
}
