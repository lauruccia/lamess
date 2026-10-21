<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices;

use App\Domain\Billing\EasyfattXmlBuilder;
use App\Domain\Billing\InvoiceService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
            TextColumn::make('easyfatt_exported_at')->label('Easyfatt')->badge()
                ->state(fn (Invoice $record) => $record->easyfatt_exported_at !== null)
                ->formatStateUsing(fn (bool $state) => $state ? 'Esportata' : 'Da esportare')
                ->color(fn (bool $state) => $state ? 'success' : 'gray')
                ->tooltip(fn (Invoice $record) => $record->easyfatt_exported_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i')),
        ])->filters([
            TernaryFilter::make('easyfatt_exported_at')->label('Easyfatt')->nullable()->placeholder('Tutte')->trueLabel('Esportate')->falseLabel('Da esportare'),
            SelectFilter::make('sink_status')->label('Consegna')->options(['pending' => 'Da inviare', 'sent' => 'Inviata', 'error' => 'Errore']),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->iconButton()->tooltip('PDF')->icon('heroicon-o-document')->color('gray')->url(fn (Invoice $record) => url('/admin-files/invoice/'.$record->id))->openUrlInNewTab(),
            Action::make('xml')->label('XML')->iconButton()->tooltip('XML')->icon('heroicon-o-code-bracket')->color('gray')->visible(fn (Invoice $record) => $record->type === 'invoice')->url(fn (Invoice $record) => url('/admin-files/invoice/'.$record->id.'/xml')),
            Action::make('easyfattToggle')->authorize('orders.manage')->iconButton()
                ->label(fn (Invoice $record) => $record->easyfatt_exported_at ? 'Rimetti da esportare per Easyfatt' : 'Segna come esportata per Easyfatt')
                ->tooltip(fn (Invoice $record) => $record->easyfatt_exported_at ? 'Rimetti da esportare per Easyfatt' : 'Segna come esportata per Easyfatt')
                ->icon(fn (Invoice $record) => $record->easyfatt_exported_at ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check-circle')->color('gray')
                ->action(fn (Invoice $record) => app(EasyfattXmlBuilder::class)->markExported([$record->id], [], $record->easyfatt_exported_at === null)),
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
