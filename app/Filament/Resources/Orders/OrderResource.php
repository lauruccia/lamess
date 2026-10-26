<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domain\Customers\AddressCheck;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\RefundService;
use App\Domain\Orders\ReturnRequestService;
use App\Domain\Orders\ReturnService;
use App\Domain\Orders\ShippingLabelService;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Orders\StripeWebhook;
use App\Domain\Periods\PeriodStateException;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Subscription;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use UnitEnum;

/** Ordini: conferma bonifici, preparazione, spedizione e consegna, annullo, resi con nota di credito, storno PV, rimborso dei soldi e giacenza; cronologia e note interne. */
class OrderResource extends Resource
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

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'ordine';

    protected static ?string $pluralModelLabel = 'Ordini';

    protected static ?int $navigationSort = 13;

    public const STATUS = [
        'pending_payment' => 'In attesa di pagamento', 'paid' => 'Pagato', 'processing' => 'In preparazione', 'shipped' => 'Spedito', 'delivered' => 'Consegnato',
        'cancelled' => 'Annullato', 'partially_refunded' => 'Reso parziale', 'refunded' => 'Reso',
    ];

    public const CARRIERS = ['BRT' => 'BRT', 'GLS' => 'GLS', 'DHL' => 'DHL', 'SDA' => 'SDA', 'Poste Italiane' => 'Poste Italiane', 'UPS' => 'UPS', 'Altro' => 'Altro'];

    /** Reso + nota di credito + (se richiesto) rimborso in denaro; mostra l'esito all'admin. */
    private static function doReturn(Order $record, array $qty, string $reason, bool $restock, bool $shipping, bool $money): ?CreditNote
    {
        try {
            $note = app(ReturnService::class)->refund($record, $qty, $reason, $restock, $shipping);
            $refunds = $money ? app(RefundService::class)->payBack($record->refresh(), $note, $reason) : [];
        } catch (OrderException|PeriodStateException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
        $failed = collect($refunds)->where('status', Refund::FAILED)->count();
        $manual = collect($refunds)->where('status', Refund::PENDING)->count();
        $body = match (true) {
            $failed > 0 => 'Il rimborso automatico non è riuscito: riprovalo o segnalo eseguito a mano dall\'ordine.',
            $manual > 0 => 'Resta da eseguire a mano il rimborso: poi segnalo «Rimborso eseguito» dall\'ordine.',
            $refunds !== [] => 'Soldi restituiti al cliente.',
            default => null,
        };
        Notification::make()->title('Reso registrato: nota di credito '.$note->number.' emessa')->body($body)->color($failed > 0 ? 'danger' : ($manual > 0 ? 'warning' : 'success'))->send();

        return $note;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (OrderException|PeriodStateException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('subscription'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Ordine')->searchable()->sortable(),
            TextColumn::make('buyer.name')->label('Cliente')->searchable(),
            TextColumn::make('buyer.email')->label('Email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('buyer_type')->label('Tipo')->formatStateUsing(fn (string $state) => $state === 'consultant' ? 'Incaricato' : 'Cliente'),
            TextColumn::make('beneficiary.name')->label(fn (): string => LegacyPlan::hidden() ? 'Incaricato' : 'PV a')->placeholder('— (vendita casa)'),
            TextColumn::make('subscription_id')->label('Ordine')->badge()
                ->state(fn (Order $r) => match ($r->subscription?->status) {
                    null => $r->subscription_id ? 'Abbonamento' : 'Singolo',
                    Subscription::CANCELLED => 'Abbonamento annullato',
                    Subscription::PAUSED => 'Abbonamento in pausa',
                    Subscription::PENDING => 'Abbonamento in attesa',
                    default => 'Abbonamento attivo',
                })
                ->color(fn (Order $r) => match ($r->subscription?->status) {
                    null => $r->subscription_id ? 'info' : 'gray',
                    Subscription::CANCELLED => 'gray',
                    Subscription::PAUSED, Subscription::PENDING => 'warning',
                    default => 'info',
                })
                ->icon(fn (Order $r) => match ($r->subscription?->status) {
                    null => $r->subscription_id ? 'heroicon-o-arrow-path' : null,
                    Subscription::CANCELLED => 'heroicon-o-x-circle',
                    Subscription::PAUSED => 'heroicon-o-pause-circle',
                    default => 'heroicon-o-arrow-path',
                }),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => self::STATUS[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'pending_payment' => 'warning', 'paid', 'processing', 'shipped', 'delivered' => 'success', 'cancelled' => 'gray', default => 'danger',
                }),
            TextColumn::make('payment_method')->label('Pagamento')->formatStateUsing(fn (string $state) => match ($state) {
                'card' => 'Carta', 'kmoney' => 'KMoney', default => 'Bonifico'
            }),
            TextColumn::make('discount_net')->label('Sconto')->state(fn (Order $r) => $r->discounts ? collect($r->discounts)->map(fn ($d) => $d['code'] ?: $d['name'])->implode(', ') : null)->placeholder('—')->toggleable(),
            TextColumn::make('total')->label('Totale €')->numeric(2),
            TextColumn::make('pv_total')->label('PV')->numeric(2)->visible(fn (): bool => LegacyPlan::visible()),
            TextColumn::make('paid_at')->label('Pagato il')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->sortable(),
            TextColumn::make('tracking_code')->label('Tracking')->placeholder('—'),
        ])->filters([
            // Ogni addebito fallito di un abbonamento ancora vivo (attivo o in pausa) lascia un ordine annullato: di default si nascondono.
            // Restano visibili gli ordini annullati degli abbonamenti disdetti o mai partiti (pagina di Stripe abbandonata).
            Filter::make('hide_failed_cycles')->label('Nascondi i tentativi di addebito non riusciti degli abbonamenti')->toggle()->default(true)
                ->query(fn ($query) => $query->whereNot(fn ($w) => $w->where('orders.status', 'cancelled')->whereNotNull('orders.subscription_id')
                    ->whereIn('orders.subscription_id', fn ($q) => $q->select('id')->from('subscriptions')->whereIn('status', ['active', 'paused'])))),
            SelectFilter::make('status')->label('Stato')->options(self::STATUS),
            TernaryFilter::make('subscription_id')->label('Abbonamento')->nullable()->placeholder('Tutti')->trueLabel('Solo abbonamenti')->falseLabel('Solo ordini singoli'),
            SelectFilter::make('payment_method')->label('Pagamento')->options(['card' => 'Carta', 'kmoney' => 'KMoney', 'transfer' => 'Bonifico']),
        ])->recordUrl(fn (Order $record) => static::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkAction::make('labels')->authorize('orders.manage')->label('Stampa etichette (foglio A4)')->icon('heroicon-o-printer')->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $ids = $records->filter(fn (Order $o) => app(ShippingLabelService::class)->isPrintable($o))->pluck('id');
                        if ($ids->isEmpty()) {
                            Notification::make()->title('Nessun ordine pronto da spedire tra quelli scelti')->warning()->send();

                            return;
                        }

                        return redirect('/admin-files/orders/etichette?ids='.$ids->implode(','));
                    }),
            ])
            ->recordActions(array_map(fn (Action $a) => $a->iconButton()->tooltip($a->getLabel()), self::orderActions()));
    }

    /**
     * Le azioni sull'ordine: le stesse nell'elenco e nella pagina di dettaglio.
     *
     * @return list<Action>
     */
    public static function orderActions(): array
    {
        return [
            Action::make('confirm')->authorize('orders.manage')->label('Conferma pagamento')->icon('heroicon-o-check')->color('success')
                ->visible(fn (Order $record) => $record->status === 'pending_payment' && ! $record->paysByCard())->requiresConfirmation()
                ->modalDescription(LegacyPlan::hidden() ? 'Conferma di aver ricevuto il pagamento. Verrà generata la fattura.' : 'Conferma di aver ricevuto il pagamento. Verranno generati i punti e la fattura.')
                ->action(fn (Order $record) => self::run(fn () => app(OrderService::class)->markPaid($record), LegacyPlan::hidden() ? 'Pagamento confermato: fattura generata' : 'Pagamento confermato: punti e fattura generati')),
            Action::make('sendPaymentDetails')->authorize('orders.manage')->label('Invia di nuovo i dati di pagamento')->icon('heroicon-o-envelope')->color('info')
                ->visible(fn (Order $record) => $record->status === 'pending_payment')->requiresConfirmation()
                ->modalHeading('Invia di nuovo i dati di pagamento')
                ->modalDescription(fn (Order $record) => 'Mandiamo a '.($record->buyer?->email ?? 'il cliente').' un\'email con '.match (true) {
                    $record->payment_method === 'transfer', $record->kmoney_rest_method === 'transfer' => 'IBAN, importo e causale del bonifico',
                    $record->paysByCard() => 'il link all\'ordine per pagare con carta',
                    default => 'il link all\'ordine per confermare il pagamento',
                }.'. Il link porta alla pagina dell\'ordine, dove il cliente trova anche i pulsanti per pagare.')
                ->modalSubmitActionLabel('Invia')
                ->action(function (Order $record): void {
                    try {
                        $to = app(OrderService::class)->sendPaymentDetails($record, reminder: true);
                        Notification::make()->title("Dati di pagamento inviati a {$to}")->success()->send();
                    } catch (OrderException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('checkStripe')->authorize('orders.manage')->label('Verifica su Stripe')->icon('heroicon-o-credit-card')->color('info')
                ->visible(fn (Order $record) => $record->status === 'pending_payment' && $record->paysByCard() && app(PaymentProvider::class) instanceof StripeCheckoutProvider)
                ->modalDescription('Chiede a Stripe se il cliente ha pagato. Se sì, l\'ordine viene confermato; la carta si conferma solo così, mai a mano.')
                ->action(function (Order $record): void {
                    $esito = app(StripeWebhook::class)->reconcile($record, app(PaymentProvider::class));
                    if (in_array($esito, ['not_paid', 'no_session'], true)) {
                        $perche = app(StripeWebhook::class)->explainPending($record, app(PaymentProvider::class));
                        OrderEvent::log($record, 'stripe_check', 'Verifica su Stripe: '.$perche, true);
                        Notification::make()->title('Pagamento non completato')->body($perche)->warning()->persistent()->send();

                        return;
                    }
                    $msg = match ($esito) {
                        'paid' => ['Pagamento trovato su Stripe: ordine confermato', 'success'],
                        'already_processed' => ['L\'ordine risulta già lavorato', 'info'],
                        'not_paid' => ['Stripe non ha ancora ricevuto il pagamento', 'warning'],
                        'amount_mismatch' => ['Importo diverso dall\'ordine: non confermato, controlla su Stripe', 'danger'],
                        'no_session' => ['Il cliente non ha ancora aperto la pagina di pagamento', 'warning'],
                        default => ['Stripe non risponde: riprova tra poco', 'danger'],
                    };
                    Notification::make()->title($msg[0])->{$msg[1]}()->send();
                }),
            Action::make('label')->authorize('orders.manage')->label('Stampa etichetta')->icon('heroicon-o-printer')->color('gray')
                ->visible(fn (Order $record) => app(ShippingLabelService::class)->isPrintable($record))
                ->url(fn (Order $record) => url('/admin-files/orders/etichette?ids='.$record->id), shouldOpenInNewTab: true),
            Action::make('processing')->authorize('orders.manage')->label('In preparazione')->icon('heroicon-o-cube')->color('gray')
                ->visible(fn (Order $record) => $record->status === 'paid')
                ->action(fn (Order $record) => self::run(fn () => app(OrderService::class)->startProcessing($record), 'Ordine in preparazione')),
            Action::make('ship')->authorize('orders.manage')->label('Spedisci')->icon('heroicon-o-truck')
                ->visible(fn (Order $record) => in_array($record->status, ['paid', 'processing', 'shipped'], true))
                ->schema([
                    Select::make('carrier')->label('Corriere')->options(self::CARRIERS)->native(false),
                    TextInput::make('tracking')->label('Codice tracking'),
                ])
                ->fillForm(fn (Order $record) => ['tracking' => $record->tracking_code, 'carrier' => $record->carrier])
                ->modalDescription('Il cliente riceve un\'email con corriere e tracking.')
                ->action(fn (Order $record, array $data) => self::run(fn () => app(OrderService::class)->ship($record, ($data['tracking'] ?? null) ?: null, ($data['carrier'] ?? null) ?: null), 'Ordine segnato come spedito')),
            Action::make('deliver')->authorize('orders.manage')->label('Segna consegnato')->icon('heroicon-o-home')->color('success')
                ->visible(fn (Order $record) => $record->status === 'shipped')->requiresConfirmation()
                ->action(fn (Order $record) => self::run(fn () => app(OrderService::class)->markDelivered($record), 'Ordine segnato come consegnato')),
            Action::make('refundKmoney')->authorize('orders.manage')->label('Rimborsa KMoney')->icon('heroicon-o-banknotes')->color('warning')
                ->visible(fn (Order $record) => $record->status === 'pending_payment' && app(OrderService::class)->hasKMoneyToRefund($record))->requiresConfirmation()
                ->modalDescription(fn (Order $record) => 'Il cliente non ha pagato la parte in euro. Riaccreditiamo € '.number_format((float) $record->kmoney_amount, 2, ',', '.').' sul suo conto KMoney e annulliamo l\'ordine.')
                ->action(fn (Order $record) => self::run(fn () => app(OrderService::class)->refundKMoneyAndCancel($record), 'Quota KMoney rimborsata e ordine annullato')),
            Action::make('cancel')->authorize('orders.manage')->label('Annulla')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Order $record) => $record->status === 'pending_payment')->requiresConfirmation()
                ->action(fn (Order $record) => self::run(fn () => app(OrderService::class)->cancel($record), 'Ordine annullato')),
            Action::make('refund')->authorize('orders.manage')->label('Reso e rimborso')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->visible(fn (Order $record) => in_array($record->status, Order::PAID_STATES, true))
                ->modalDescription(LegacyPlan::hidden() ? 'Emette una nota di credito e storna i compensi (se il periodo è già chiuso lo storno va nel mese corrente). Puoi anche restituire i soldi al cliente e rimettere la merce in giacenza.' : 'Emette una nota di credito e storna i punti (se il periodo è già chiuso lo storno va nel mese corrente). Puoi anche restituire i soldi al cliente e rimettere la merce in giacenza.')
                ->fillForm(fn (Order $record) => ['restock' => true, 'money' => true, 'items' => $record->items->filter(fn ($i) => $i->unit_net > 0)->map(fn ($i) => [
                    'id' => $i->id, 'label' => $i->name.' (resi '.$i->qty_refunded.'/'.$i->qty.')', 'qty' => 0,
                ])->values()->all()])
                ->schema([
                    Repeater::make('items')->label('Righe')->addable(false)->deletable(false)->reorderable(false)->columns(2)->schema([
                        Hidden::make('id'),
                        TextInput::make('label')->label('Prodotto')->disabled()->dehydrated(false),
                        TextInput::make('qty')->label('Quantità resa')->numeric()->minValue(0)->default(0),
                    ]),
                    Textarea::make('reason')->label('Motivazione (obbligatoria)')->required(),
                    Toggle::make('restock')->label('Rimetti la merce in giacenza')->default(true),
                    Toggle::make('shipping')->label('Rimborsa anche le spese di spedizione')->default(false)
                        ->visible(fn (Order $record) => (float) $record->shipping_net - (float) $record->shipping_refunded_net > 0)
                        ->helperText(fn (Order $record) => 'Spedizione ancora rimborsabile: € '.number_format(((float) $record->shipping_net - (float) $record->shipping_refunded_net) * (1 + (float) $record->shipping_vat_rate / 100), 2, ',', '.').' IVA inclusa.'),
                    Toggle::make('money')->label('Restituisci i soldi al cliente (sullo stesso metodo di pagamento)')->default(true),
                ])
                ->action(function (Order $record, array $data): void {
                    $qty = [];
                    foreach ($data['items'] as $row) {
                        $qty[(int) $row['id']] = (int) $row['qty'];
                    }
                    self::doReturn($record, $qty, (string) $data['reason'], (bool) ($data['restock'] ?? false), (bool) ($data['shipping'] ?? false), (bool) ($data['money'] ?? false));
                }),
            Action::make('approveReturn')->authorize('orders.manage')->label('Approva reso richiesto')->icon('heroicon-o-check-circle')->color('warning')
                ->visible(fn (Order $record) => $record->returnRequests()->where('status', ReturnRequest::REQUESTED)->exists())
                ->modalDescription(function (Order $record): string {
                    $rq = $record->returnRequests()->where('status', ReturnRequest::REQUESTED)->latest('id')->first();
                    $names = $record->items->whereIn('id', array_keys((array) $rq?->items))->map(fn ($i) => $i->name.' × '.($rq->items[$i->id] ?? 0))->implode(', ');

                    return 'Il cliente chiede il reso di: '.$names.'. Motivo: '.$rq?->reason;
                })
                ->schema([
                    Toggle::make('restock')->label('Rimetti la merce in giacenza')->default(true),
                    Toggle::make('shipping')->label('Rimborsa anche le spese di spedizione')->default(false)
                        ->visible(fn (Order $record) => (float) $record->shipping_net - (float) $record->shipping_refunded_net > 0),
                    Toggle::make('money')->label('Restituisci i soldi al cliente')->default(true),
                    Textarea::make('note')->label('Messaggio per il cliente (facoltativo)'),
                ])
                ->action(function (Order $record, array $data): void {
                    $rq = $record->returnRequests()->where('status', ReturnRequest::REQUESTED)->latest('id')->first();
                    if (! $rq) {
                        return;
                    }
                    $note = self::doReturn($record, array_map('intval', (array) $rq->items), (string) $rq->reason, (bool) ($data['restock'] ?? false), (bool) ($data['shipping'] ?? false), (bool) ($data['money'] ?? false));
                    if ($note) {
                        $rq->update(['status' => ReturnRequest::APPROVED, 'credit_note_id' => $note->id, 'admin_note' => ($data['note'] ?? null) ?: null, 'decided_at' => now()]);
                    }
                }),
            Action::make('rejectReturn')->authorize('orders.manage')->label('Rifiuta reso richiesto')->icon('heroicon-o-x-circle')->color('gray')
                ->visible(fn (Order $record) => $record->returnRequests()->where('status', ReturnRequest::REQUESTED)->exists())
                ->schema([Textarea::make('note')->label('Motivo del rifiuto (il cliente lo vede)')->required()])
                ->action(fn (Order $record, array $data) => self::run(function () use ($record, $data): void {
                    $rq = $record->returnRequests()->where('status', ReturnRequest::REQUESTED)->latest('id')->firstOrFail();
                    app(ReturnRequestService::class)->reject($rq, (string) $data['note']);
                }, 'Richiesta di reso rifiutata')),
            Action::make('refundPaid')->authorize('orders.manage')->label('Rimborso eseguito')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (Order $record) => $record->refunds()->whereIn('status', [Refund::PENDING, Refund::FAILED])->exists())
                ->modalDescription('Per i rimborsi fatti fuori dal portale (bonifico al cliente, dashboard della carta…): segnalo come eseguito.')
                ->schema(fn (Order $record) => [
                    Select::make('refund_id')->label('Rimborso')->required()->native(false)->options(
                        $record->refunds()->whereIn('status', [Refund::PENDING, Refund::FAILED])->get()->mapWithKeys(fn (Refund $r) => [$r->id => '€ '.number_format((float) $r->amount, 2, ',', '.').' · '.OrderService::methodLabel($r->method).($r->status === Refund::FAILED ? ' (non riuscito)' : '')])->all()
                    ),
                    TextInput::make('reference')->label('Riferimento (CRO, id operazione…)'),
                ])
                ->action(function (Order $record, array $data): void {
                    $refund = $record->refunds()->findOrFail((int) $data['refund_id']);
                    self::run(fn () => app(RefundService::class)->markDone($refund, ($data['reference'] ?? null) ?: null), 'Rimborso segnato come eseguito');
                }),
            Action::make('refundRetry')->authorize('orders.manage')->label('Riprova rimborso')->icon('heroicon-o-arrow-path')->color('warning')
                ->visible(fn (Order $record) => $record->refunds()->where('status', Refund::FAILED)->exists())->requiresConfirmation()
                ->action(function (Order $record): void {
                    foreach ($record->refunds()->where('status', Refund::FAILED)->get() as $refund) {
                        self::run(fn () => app(RefundService::class)->retry($refund), 'Rimborso ritentato: controlla la cronologia');
                    }
                }),
            Action::make('editShipping')->authorize('orders.manage')->label('Modifica indirizzo di consegna')->icon('heroicon-o-map-pin')->color('gray')
                ->visible(fn (Order $record) => in_array($record->status, OrderService::SHIPPING_EDITABLE, true))
                ->modalDescription('Cambia solo dove consegnare questo ordine: la fatturazione e le fatture non cambiano. Resta scritto nella cronologia.')
                ->fillForm(fn (Order $record) => Arr::only((array) (((array) $record->billing)['shipping'] ?? []), ['recipient', 'address', 'zip', 'city', 'province', 'phone']))
                ->schema([
                    TextInput::make('recipient')->label('Destinatario (se diverso dal cliente)')->maxLength(190),
                    TextInput::make('address')->label('Indirizzo (via e numero civico)')->required()->maxLength(190)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (($problem = AddressCheck::problem((string) $value)) !== null) {
                                $fail($problem);
                            }
                        }),
                    TextInput::make('zip')->label('CAP')->required()->maxLength(10),
                    TextInput::make('city')->label('Città')->required()->maxLength(120),
                    TextInput::make('province')->label('Provincia')->maxLength(3),
                    TextInput::make('phone')->label('Telefono per il corriere')->maxLength(30),
                ])
                ->action(fn (Order $record, array $data) => self::run(fn () => app(OrderService::class)->updateShippingAddress($record, $data), 'Indirizzo di consegna aggiornato')),
            Action::make('note')->authorize('orders.manage')->label('Aggiungi nota')->icon('heroicon-o-pencil-square')->color('gray')
                ->schema([Textarea::make('note')->label('Nota interna (il cliente non la vede)')->required()->rows(3)])
                ->action(fn (Order $record, array $data) => self::run(fn () => app(OrderService::class)->addNote($record, (string) $data['note']), 'Nota aggiunta')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}'), 'edit' => EditOrder::route('/{record}/edit')];
    }
}
