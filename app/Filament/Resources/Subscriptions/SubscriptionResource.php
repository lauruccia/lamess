<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions;

use App\Domain\Orders\OrderException;
use App\Domain\Subscriptions\SubscriptionService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\Subscription;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Abbonamenti dei clienti (RF-A01/A03/A05): elenco e gestione da parte dell'amministrazione. */
class SubscriptionResource extends Resource
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

    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'abbonamento';

    protected static ?string $pluralModelLabel = 'Abbonamenti';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('user.name')->label('Cliente')->searchable(),
            TextColumn::make('status')->label('Stato')->badge()
                ->formatStateUsing(fn (string $state) => ['pending' => 'In attesa 1° pagamento', 'active' => 'Attivo', 'paused' => 'In pausa', 'cancelled' => 'Annullato'][$state] ?? $state)
                ->color(fn (string $state) => ['pending' => 'warning', 'active' => 'success', 'paused' => 'warning', 'cancelled' => 'gray'][$state] ?? 'gray'),
            TextColumn::make('payment_method')->label('Pagamento')->formatStateUsing(fn (?string $state) => $state === 'kmoney' ? 'KMoney' : 'Carta'),
            TextColumn::make('interval_months')->label('Cadenza')->formatStateUsing(fn ($state) => (int) $state === 2 ? 'Bimestrale' : 'Mensile'),
            TextColumn::make('started_at')->label('Avvio')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
            TextColumn::make('commitment_months')->label('Durata')
                ->formatStateUsing(fn ($state) => (int) $state > 0 ? $state.' mesi' : 'Senza impegno'),
            IconColumn::make('free_shipping')->label('Sped. gratuita')->boolean(),
            TextColumn::make('cycles_done')->label('Cicli')
                ->formatStateUsing(fn ($state, Subscription $record) => $record->commitment_months > 0 ? $state.'/'.$record->commitmentCycles() : (string) $state),
            TextColumn::make('next_run_at')->label('Prossimo addebito')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—'),
            TextColumn::make('last_error')->label('Ultimo errore')->placeholder('—')->limit(40),
            IconColumn::make('early_cancel')->label('Disdetta anticipata')->boolean(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['active' => 'Attivi', 'paused' => 'In pausa', 'cancelled' => 'Annullati']),
        ])->recordActions([
            Action::make('pause')->authorize('orders.manage')->label('Pausa')->iconButton()->tooltip('Pausa')->icon('heroicon-o-pause')
                ->visible(fn (Subscription $record) => $record->status === Subscription::ACTIVE)
                ->requiresConfirmation()
                ->action(fn (Subscription $record) => self::run(fn () => app(SubscriptionService::class)->pause($record, 'Sospeso dall\'amministrazione'))),
            Action::make('resume')->authorize('orders.manage')->label('Riprendi')->iconButton()->tooltip('Riprendi')->icon('heroicon-o-play')->color('success')
                ->visible(fn (Subscription $record) => $record->status === Subscription::PAUSED)
                ->requiresConfirmation()
                ->action(fn (Subscription $record) => self::run(fn () => app(SubscriptionService::class)->resume($record))),
            Action::make('kmoneyConfirm')->authorize('orders.manage')->label('Invia email di conferma KMoney')->iconButton()->tooltip('Rimanda al cliente l\'email per confermare l\'addebito su KMoney')->icon('heroicon-o-envelope')->color('warning')
                ->visible(fn (Subscription $record) => $record->payment_method === Subscription::METHOD_KMONEY && $record->status === Subscription::ACTIVE && $record->last_error !== null)
                ->requiresConfirmation()
                ->modalDescription('Riprova ora l\'addebito su KMoney: se serve ancora la conferma del cliente, gli arriva di nuovo l\'email con il pulsante «Conferma l\'addebito». Non consuma tentativi e non sposta le scadenze.')
                ->action(function (Subscription $record): void {
                    try {
                        $esito = app(SubscriptionService::class)->requestConfirmation($record);
                    } catch (OrderException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $record->refresh();
                    if ($esito === 'paid') {
                        Notification::make()->title('Addebito riuscito: il mese è stato pagato')->success()->send();
                    } elseif ($record->last_error === SubscriptionService::CONFIRM) {
                        Notification::make()->title('Email di conferma inviata a '.($record->user?->email ?? 'cliente'))->success()->send();
                    } else {
                        Notification::make()->title('Addebito non riuscito')->body((string) $record->last_error)->warning()->persistent()->send();
                    }
                }),
            Action::make('cancel')->authorize('orders.manage')->label('Annulla')->iconButton()->tooltip('Annulla')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Subscription $record) => $record->status !== Subscription::CANCELLED)
                ->schema([Textarea::make('reason')->label('Motivo')->required()])
                ->action(fn (Subscription $record, array $data) => self::run(fn () => app(SubscriptionService::class)->cancel($record, $data['reason']))),
        ]);
    }

    private static function run(callable $fn): void
    {
        try {
            $fn();
            Notification::make()->title('Fatto')->success()->send();
        } catch (OrderException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/'), 'edit' => EditSubscription::route('/{record}/edit')];
    }
}
