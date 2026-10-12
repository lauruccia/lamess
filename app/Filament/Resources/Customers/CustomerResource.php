<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Domain\Network\ConsultantConversion;
use App\Domain\Network\NetworkException;
use App\Domain\Network\TreeOperations;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Support\ConsultantFilter;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/** Clienti / FAN registrati (sola lettura). */
class CustomerResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'network.view';
    }

    protected static function managePermission(): string
    {
        return 'network.manage';
    }

    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'Clienti (FAN)';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return list<Component> */
    private static function reassignForm(): array
    {
        return [
            Select::make('consultant_id')->label('Nuovo incaricato')->searchable()->placeholder('— nessuno —')
                ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', 'active')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : ''),
            Textarea::make('reason')->label('Motivo')->required(),
        ];
    }

    /** @param iterable<Customer> $customers */
    private static function reassign(iterable $customers, array $data): void
    {
        try {
            $n = app(TreeOperations::class)->reassignCustomers($customers, $data['consultant_id'] ? Consultant::findOrFail($data['consultant_id']) : null, $data['reason']);
            Notification::make()->title("Clienti riassegnati: {$n}")->success()->send();
        } catch (NetworkException $e) {
            Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return list<Component> */
    private static function promoteForm(): array
    {
        return [
            Select::make('sponsor_id')->label('Sponsor')->searchable()->placeholder('— il suo incaricato attuale —')
                ->helperText('Se lasci vuoto, lo sponsor è l\'incaricato a cui il cliente è assegnato.')
                ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', 'active')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : ''),
            Textarea::make('reason')->label('Motivo')->required(),
        ];
    }

    private static function promote(Customer $customer, array $data): void
    {
        try {
            $c = app(ConsultantConversion::class)->promoteToConsultant($customer->user, ! empty($data['sponsor_id']) ? Consultant::findOrFail($data['sponsor_id']) : null, $data['reason']);
            Notification::make()->title("{$c->name} è ora incaricato ({$c->code})")->body('Lettera d\'incarico e KYC vanno completati.')->success()->send();
        } catch (NetworkException $e) {
            Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        $paid = Order::PAID_STATES;

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user.profile', 'consultant'])
                ->withCount(['orders as paid_orders_count' => fn ($q) => $q->whereIn('status', $paid), 'subscriptions as active_subscriptions_count' => fn ($q) => $q->where('status', 'active')])
                ->withSum(['orders as spent_total' => fn ($q) => $q->whereIn('status', $paid)], 'total')
                ->withMax(['orders as last_purchase_at' => fn ($q) => $q->whereIn('status', $paid)], 'paid_at'))
            ->columns([
                TextColumn::make('user.name')->label('Nome')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable()->limit(32)->tooltip(fn ($state) => $state),
                TextColumn::make('user.profile.type')->label('Tipo')->badge()->formatStateUsing(fn ($state) => $state === 'company' ? 'Azienda' : 'Privato'),
                TextColumn::make('user.profile.phone')->label('Telefono')->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->label('Città')->state(fn (Customer $r) => ($p = $r->user?->profile) && $p->city ? $p->city.($p->province ? " ({$p->province})" : '') : null)->placeholder('—')->toggleable(),
                TextColumn::make('consultant.name')->label('Incaricato')->placeholder('— nessuno —')->searchable(),
                TextColumn::make('source')->label('Origine')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_orders_count')->label('Ordini')->numeric()->alignEnd()->sortable()->color(fn ($state) => $state ? null : 'gray'),
                TextColumn::make('spent_total')->label('Speso')->money('EUR', locale: 'it')->placeholder('—')->alignEnd()->sortable(),
                TextColumn::make('last_purchase_at')->label('Ultimo acq.')->date('d/m/Y')->timezone('Europe/Rome')->placeholder('mai')->sortable(),
                TextColumn::make('active_subscriptions_count')->label('Abbon.')->tooltip('Abbonamenti attivi')->numeric()->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registered_at')->label('Registrato il')->date('d/m/Y')->timezone('Europe/Rome')->sortable(),
            ])->filters([
                ConsultantFilter::make('assigned_consultant_id'),
                TernaryFilter::make('has_orders')->label('Hanno acquistato')->placeholder('Tutti')->trueLabel('Con acquisti')->falseLabel('Mai acquistato')
                    ->queries(
                        true: fn ($q) => $q->whereHas('orders', fn ($o) => $o->whereIn('status', Order::PAID_STATES)),
                        false: fn ($q) => $q->whereDoesntHave('orders', fn ($o) => $o->whereIn('status', Order::PAID_STATES)),
                        blank: fn ($q) => $q,
                    ),
                TernaryFilter::make('no_consultant')->label('Assegnazione')->placeholder('Tutti')->trueLabel('Senza incaricato')->falseLabel('Con incaricato')
                    ->queries(true: fn ($q) => $q->whereNull('assigned_consultant_id'), false: fn ($q) => $q->whereNotNull('assigned_consultant_id'), blank: fn ($q) => $q),
            ])->recordActions([
                Action::make('promote')->label('Promuovi a incaricato')->iconButton()->tooltip('Promuovi a incaricato')->icon('heroicon-o-arrow-up-circle')->authorize('network.manage')
                    ->visible(fn (Customer $record) => $record->user?->profile?->type !== 'company' && ! Consultant::where('user_id', $record->user_id)->where('status', '!=', 'terminated')->exists())
                    ->modalDescription('Il cliente entra subito nell\'albero come incaricato attivo, con codice e link referral nuovi. Lettera d\'incarico e KYC si completano dopo.')
                    ->schema(self::promoteForm())->action(fn (Customer $record, array $data) => self::promote($record, $data)),
                Action::make('reassign')->label('Riassegna')->iconButton()->tooltip('Riassegna')->icon('heroicon-o-arrows-right-left')->authorize('network.restructure')
                    ->schema(self::reassignForm())->action(fn (Customer $record, array $data) => self::reassign([$record], $data)),
            ])->toolbarActions([
                BulkAction::make('reassignBulk')->label('Riassegna selezionati')->icon('heroicon-o-arrows-right-left')->authorize('network.restructure')
                    ->schema(self::reassignForm())->deselectRecordsAfterCompletion()->action(fn (Collection $records, array $data) => self::reassign($records, $data)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/'), 'edit' => EditCustomer::route('/{record}/edit')];
    }
}
