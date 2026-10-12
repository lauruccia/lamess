<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices;

use App\Domain\Billing\InvoiceService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class InvoiceResource extends Resource
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

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'fattura';

    protected static ?string $pluralModelLabel = 'Fatture';

    protected static ?int $navigationSort = 14;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable()->sortable(),
            TextColumn::make('issued_at')->label('Data')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
            TextColumn::make('order.number')->label('Ordine'),
            TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (string $state) => $state === 'receipt' ? 'Ricevuta' : 'Fattura'),
            TextColumn::make('buyer.name')->label('Intestata a'),
            TextColumn::make('net')->label('Imponibile €')->numeric(2),
            TextColumn::make('vat')->label('IVA €')->numeric(2),
            TextColumn::make('total')->label('Totale €')->numeric(2),
            TextColumn::make('sink_status')->label('Consegna')->badge()
                ->formatStateUsing(fn (string $state) => ['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore'][$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'sent' => 'success', 'error' => 'danger', default => 'warning'
                })
                ->tooltip(fn (Invoice $record) => $record->sink_error),
        ])->filters([
            SelectFilter::make('sink_status')->label('Consegna')->options(['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore']),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->iconButton()->tooltip('PDF')->icon('heroicon-o-document')->color('gray')->url(fn (Invoice $record) => url('/admin-files/invoice/'.$record->id))->openUrlInNewTab(),
            Action::make('xml')->label('XML')->iconButton()->tooltip('XML')->icon('heroicon-o-code-bracket')->color('gray')->visible(fn (Invoice $record) => $record->type === 'invoice')->url(fn (Invoice $record) => url('/admin-files/invoice/'.$record->id.'/xml')),
            Action::make('resend')->authorize('orders.manage')->label('Reinvia')->iconButton()->tooltip('Reinvia')->icon('heroicon-o-paper-airplane')
                ->visible(fn (Invoice $record) => $record->sink_status !== 'sent')
                ->action(function (Invoice $record): void {
                    app(InvoiceService::class)->deliver($record);
                    $ok = $record->refresh()->sink_status === 'sent';
                    Notification::make()->title($ok ? 'Documento consegnato' : 'Consegna non riuscita: '.$record->sink_error)->{$ok ? 'success' : 'danger'}()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/'), 'edit' => EditInvoice::route('/{record}/edit')];
    }
}
