<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts;

use App\Domain\Access\Superadmin;
use App\Domain\Payouts\PayoutException;
use App\Domain\Payouts\PayoutService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Pages\EditPayout;
use App\Models\Payout;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Pagamenti agli incaricati: da pagare / pagato / annullato. */
class PayoutResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'payouts.view';
    }

    protected static function managePermission(): string
    {
        return 'payouts.manage';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Superadmin::is();
    }

    public static function canDelete($record): bool
    {
        return Superadmin::is();
    }

    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'pagamento';

    protected static ?string $pluralModelLabel = 'Pagamenti';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('period.code')->label('Mese')->sortable(),
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('amount')->label('Importo €')->numeric(2)->sortable(),
            TextColumn::make('iban')->label('IBAN')->toggleable(),
            TextColumn::make('status')->label('Stato')->badge()
                ->formatStateUsing(fn (string $state) => ['to_pay' => 'Da pagare', 'paid' => 'Pagato', 'cancelled' => 'Annullato'][$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'paid' => 'success', 'cancelled' => 'gray', default => 'warning'
                }),
            TextColumn::make('paid_at')->label('Pagato il')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—'),
            TextColumn::make('reference')->label('Riferimento')->placeholder('—'),
            TextColumn::make('exported_at')->label('Esportato')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['to_pay' => 'Da pagare', 'paid' => 'Pagato', 'cancelled' => 'Annullato']),
            SelectFilter::make('period_id')->label('Mese')->relationship('period', 'code'),
        ])->recordActions([
            Action::make('paid')->authorize('payouts.manage')->label('Segna pagato')->iconButton()->tooltip('Segna pagato')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (Payout $p) => $p->status === Payout::TO_PAY)
                ->schema([
                    TextInput::make('reference')->label('Riferimento del bonifico (CRO)')->required(),
                    DatePicker::make('paid_at')->label('Data del bonifico')->required()->default(now()->toDateString())->maxDate(now()),
                ])
                ->action(function (array $data, Payout $record, PayoutService $svc): void {
                    try {
                        $svc->markPaid($record, $data['reference'], new \DateTimeImmutable($data['paid_at'], new \DateTimeZone('Europe/Rome')));
                        Notification::make()->title('Pagamento registrato')->success()->send();
                    } catch (PayoutException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('cancel')->authorize('payouts.manage')->label('Annulla')->iconButton()->tooltip('Annulla')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Payout $p) => $p->status === Payout::TO_PAY)
                ->schema([Textarea::make('reason')->label('Motivo')->required()])
                ->modalDescription('Le liquidazioni tornano disponibili e potranno essere incluse in un nuovo pagamento.')
                ->action(function (array $data, Payout $record, PayoutService $svc): void {
                    try {
                        $svc->cancel($record, $data['reason']);
                        Notification::make()->title('Pagamento annullato')->success()->send();
                    } catch (PayoutException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayouts::route('/'), 'edit' => EditPayout::route('/{record}/edit')];
    }
}
