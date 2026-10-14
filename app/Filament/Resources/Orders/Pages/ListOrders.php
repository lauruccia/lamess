<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodStateException;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListOrders extends SuperadminListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->createOrderAction(),
            Action::make('export')->label('Esporta CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->authorize('orders.view')
                ->modalDescription('Scarica gli ordini che vedi nell\'elenco (filtri e ricerca compresi), pronti per Excel.')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /** Ordine inserito a mano dall'admin (telefono, evento, cliente in negozio): stessi prezzi, sconti, punti e fattura del checkout. */
    private function createOrderAction(): Action
    {
        return Action::make('createOrder')->label('Nuovo ordine')->icon('heroicon-o-plus')->authorize('orders.manage')
            ->modalWidth('4xl')
            ->modalDescription(LegacyPlan::hidden() ? 'Crea un ordine per conto di un cliente o incaricato. Prezzi, sconti, spedizione e fattura sono gli stessi del negozio.' : 'Crea un ordine per conto di un cliente o incaricato. Prezzi, sconti, spedizione, punti e fattura sono gli stessi del negozio.')
            ->schema([
                Select::make('user_id')->label('Cliente')->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => User::where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->limit(30)->get()->mapWithKeys(fn (User $u) => [$u->id => $u->name.' — '.$u->email])->all())
                    ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? $u->name.' — '.$u->email : null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $pr = User::find($state)?->profile;
                        foreach (['type', 'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec', 'address', 'city', 'zip', 'province'] as $f) {
                            $set($f, $pr?->{$f} ?? ($f === 'type' ? 'private' : null));
                        }
                    }),
                Repeater::make('lines')->label('Prodotti')->required()->minItems(1)->columns(2)->defaultItems(1)->schema([
                    Select::make('product_id')->label('Prodotto')->required()->searchable()
                        ->options(fn () => Product::whereNull('parent_id')->where('active', true)->where('net_price', '>', 0)->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('qty')->label('Quantità')->numeric()->integer()->minValue(1)->default(1)->required(),
                ]),
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('type')->label('Tipo')->options(['private' => 'Privato', 'company' => 'Azienda'])->default('private')->live(),
                    TextInput::make('first_name')->label('Nome')->visible(fn (Get $get) => $get('type') !== 'company'),
                    TextInput::make('last_name')->label('Cognome')->visible(fn (Get $get) => $get('type') !== 'company'),
                    TextInput::make('company_name')->label('Ragione sociale')->visible(fn (Get $get) => $get('type') === 'company'),
                    TextInput::make('vat_number')->label('Partita IVA')->visible(fn (Get $get) => $get('type') === 'company'),
                    TextInput::make('sdi_code')->label('Codice SDI')->visible(fn (Get $get) => $get('type') === 'company'),
                    TextInput::make('pec')->label('PEC')->visible(fn (Get $get) => $get('type') === 'company'),
                    TextInput::make('tax_code')->label('Codice fiscale'),
                    TextInput::make('address')->label('Indirizzo')->required(),
                    TextInput::make('city')->label('Città')->required(),
                    TextInput::make('zip')->label('CAP')->required(),
                    TextInput::make('province')->label('Provincia')->maxLength(3),
                    Select::make('country')->label('Paese')->options(fn () => collect((array) config('lamess.shop_countries'))->mapWithKeys(fn ($c) => [$c => $c])->all())->default('IT')->required(),
                ]),
                Select::make('method')->label('Metodo di pagamento')->options(['transfer' => 'Bonifico', 'card' => 'Carta (paga il cliente su Stripe)', 'kmoney' => 'KMoney'])->default('transfer')->required()->live(),
                Toggle::make('paid')->visible(fn (Get $get) => $get('method') !== 'card')->label(LegacyPlan::hidden() ? 'Già pagato: conferma subito il pagamento (genera la fattura)' : 'Già pagato: conferma subito il pagamento (genera punti e fattura)')->default(false),
                Textarea::make('notes')->label('Note (facoltative)'),
            ])
            ->action(function (array $data): void {
                $lines = [];
                foreach ($data['lines'] as $row) {
                    $lines[(int) $row['product_id']] = ($lines[(int) $row['product_id']] ?? 0) + (int) $row['qty'];
                }
                $orders = app(OrderService::class);
                if ($data['method'] === 'card') {
                    $data['paid'] = false;   // la carta si conferma solo con il pagamento reale su Stripe
                }
                try {
                    $buyer = User::findOrFail($data['user_id']);
                    $order = $orders->place($buyer, $lines, (string) $data['country'], (string) $data['method'], $data, null, ($data['notes'] ?? null) ?: null);
                    OrderEvent::log($order, 'created_by_admin', 'Ordine creato a mano da '.(auth()->user()?->name ?? 'admin'));
                    if ($data['paid'] ?? false) {
                        $orders->markPaid($order);
                    }
                } catch (OrderException|PeriodStateException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Ordine '.$order->number.' creato'.(($data['paid'] ?? false) ? ' e pagamento confermato' : ''))->success()->send();
                $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
            });
    }

    /** CSV con punto e virgola e UTF-8 con BOM: si apre in Excel italiano senza passaggi. */
    private function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()->with(['buyer', 'beneficiary']);
        AuditLog::record('export.orders', 'Esportazione ordini in CSV', ['Order', 'elenco'], ['righe' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Ordine', 'Data', 'Cliente', 'Email', 'Stato', 'Pagamento', 'Imponibile', 'Spedizione (netto)', 'IVA', 'Totale', 'Pagato il', 'Corriere', 'Tracking'], ';');
            $query->reorder()->chunkById(200, function ($orders) use ($out): void {
                foreach ($orders as $o) {
                    fputcsv($out, [
                        $o->number, $o->created_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i'), $o->buyer?->name, $o->buyer?->email,
                        OrderResource::STATUS[$o->status] ?? $o->status, OrderService::methodLabel($o->payment_method),
                        number_format((float) $o->subtotal_net, 2, ',', ''), number_format((float) $o->shipping_net, 2, ',', ''),
                        number_format((float) $o->vat_total, 2, ',', ''), number_format((float) $o->total, 2, ',', ''),
                        $o->paid_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i'), $o->carrier, $o->tracking_code,
                    ], ';');
                }
            });
            fclose($out);
        }, 'ordini-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
