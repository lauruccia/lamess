<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes;

use App\Domain\Billing\InvoiceService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\EditCreditNote;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CreditNoteResource extends Resource
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

    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'nota di credito';

    protected static ?string $pluralModelLabel = 'Note di credito';

    protected static ?int $navigationSort = 15;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable()->sortable(),
            TextColumn::make('issued_at')->label('Data')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
            TextColumn::make('invoice.number')->label('Fattura'),
            TextColumn::make('reason')->label('Motivo')->limit(30),
            TextColumn::make('net')->label('Imponibile €')->numeric(2),
            TextColumn::make('vat')->label('IVA €')->numeric(2),
            TextColumn::make('total')->label('Totale €')->numeric(2),
            TextColumn::make('sink_status')->label('Consegna')->badge()
                ->formatStateUsing(fn (string $state) => ['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore'][$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'sent' => 'success', 'error' => 'danger', default => 'warning'
                })
                ->tooltip(fn (CreditNote $record) => $record->sink_error),
        ])->filters([
            SelectFilter::make('sink_status')->label('Consegna')->options(['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore']),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->iconButton()->tooltip('PDF')->icon('heroicon-o-document')->color('gray')->url(fn (CreditNote $record) => url('/admin-files/credit-note/'.$record->id))->openUrlInNewTab(),
            Action::make('xml')->label('XML')->iconButton()->tooltip('XML')->icon('heroicon-o-code-bracket')->color('gray')->visible(fn (CreditNote $record) => true)->url(fn (CreditNote $record) => url('/admin-files/credit-note/'.$record->id.'/xml')),
            Action::make('resend')->authorize('orders.manage')->label('Reinvia')->iconButton()->tooltip('Reinvia')->icon('heroicon-o-paper-airplane')
                ->visible(fn (CreditNote $record) => $record->sink_status !== 'sent')
                ->action(function (CreditNote $record): void {
                    app(InvoiceService::class)->deliver($record);
                    $ok = $record->refresh()->sink_status === 'sent';
                    Notification::make()->title($ok ? 'Documento consegnato' : 'Consegna non riuscita: '.$record->sink_error)->{$ok ? 'success' : 'danger'}()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCreditNotes::route('/'), 'edit' => EditCreditNote::route('/{record}/edit')];
    }
}
