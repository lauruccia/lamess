<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\PasswordResetLinks;
use App\Domain\Audit\AuditLog;
use App\Domain\Customers\CustomerService;
use App\Models\Consultant;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;
use Throwable;

/**
 * «Nuovo cliente» dall'elenco Clienti: admin e super admin inseriscono a mano un cliente (privato o azienda), con gli
 * stessi controlli della registrazione pubblica. Chi ha il permesso «network.manage» (il super admin lo ha sempre).
 */
final class NewCustomerAction
{
    public static function make(): Action
    {
        return Action::make('newCustomer')->authorize('network.manage')
            ->label('Nuovo cliente')->icon('heroicon-o-user-plus')
            ->modalHeading('Nuovo cliente')->modalSubmitActionLabel('Crea cliente')->modalWidth('3xl')
            ->modalDescription('Inserisci i dati del cliente. Se lasci vuota la password, ne viene creata una che nessuno conosce e la persona sceglie la sua dal link che le mandi.')
            ->schema([
                Select::make('type')->label('Tipo')->native(false)->required()->default('private')->live()
                    ->options(['private' => 'Privato', 'company' => 'Azienda']),
                Grid::make(2)->schema([
                    TextInput::make('first_name')->label('Nome')->maxLength(100)->required(fn (Get $get): bool => $get('type') !== 'company')->visible(fn (Get $get): bool => $get('type') !== 'company'),
                    TextInput::make('last_name')->label('Cognome')->maxLength(100)->required(fn (Get $get): bool => $get('type') !== 'company')->visible(fn (Get $get): bool => $get('type') !== 'company'),
                    TextInput::make('company_name')->label('Ragione sociale')->maxLength(150)->required(fn (Get $get): bool => $get('type') === 'company')->visible(fn (Get $get): bool => $get('type') === 'company'),
                    TextInput::make('vat_number')->label('Partita IVA')->maxLength(20)->required(fn (Get $get): bool => $get('type') === 'company')->visible(fn (Get $get): bool => $get('type') === 'company'),
                    TextInput::make('sdi_code')->label('Codice SDI')->maxLength(7)->helperText('Serve il codice SDI o la PEC.')->visible(fn (Get $get): bool => $get('type') === 'company'),
                    TextInput::make('pec')->label('PEC')->email()->maxLength(150)->visible(fn (Get $get): bool => $get('type') === 'company'),
                    TextInput::make('email')->label('Email')->email()->required()->maxLength(150),
                    TextInput::make('phone')->label('Telefono')->tel()->maxLength(30),
                    TextInput::make('tax_code')->label('Codice fiscale')->maxLength(16),
                    TextInput::make('address')->label('Indirizzo')->maxLength(200),
                    TextInput::make('city')->label('Città')->maxLength(100),
                    TextInput::make('zip')->label('CAP')->maxLength(10),
                    TextInput::make('province')->label('Provincia')->maxLength(3),
                    TextInput::make('country_code')->label('Nazione (sigla)')->default('IT')->maxLength(2),
                ]),
                Select::make('consultant_id')->label('Incaricato di riferimento')->searchable()->placeholder('— nessuno (vale l\'incaricato «casa», se c\'è) —')
                    ->getSearchResultsUsing(fn (string $search): array => Consultant::query()->whereIn('status', (array) config('lamess.sponsoring_statuses'))
                        ->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orderBy('code')->limit(30)->get()->mapWithKeys(fn (Consultant $c): array => [$c->id => "{$c->code} — {$c->name}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : null),
                TextInput::make('password')->label('Password (facoltativa)')->password()->revealable()->minLength(10)->maxLength(100),
                Toggle::make('send_link')->label('Invia subito alla persona il link per scegliere la password')->default(true)
                    ->helperText('Parte una email dal portale (serve «Invio email attivo» nelle Impostazioni di sistema).'),
            ])
            ->action(fn (array $data, Action $action) => self::create($data, $action));
    }

    /** @param  array<string, mixed>  $data */
    private static function create(array $data, Action $action): void
    {
        try {
            $customer = app(CustomerService::class)->createByAdmin(
                collect($data)->except(['consultant_id', 'send_link'])->filter(fn ($v) => $v !== null && $v !== '')->all(),
                filled($data['consultant_id'] ?? null) ? Consultant::findOrFail($data['consultant_id']) : null,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Cliente non creato')->body($e->getMessage())->danger()->send();

            $action->halt();
        }
        AuditLog::record('customer.created_by_admin', "Cliente creato dall'amministrazione: {$customer->user->email}", $customer, ['incaricato' => $customer->consultant?->code]);

        [$title, $body] = self::invite($customer, (bool) ($data['send_link'] ?? false) && blank($data['password'] ?? null));
        Notification::make()->title($title)->body($body)->success()->send();
    }

    /** @return array{0:string,1:?string} */
    private static function invite(Customer $customer, bool $send): array
    {
        $title = "Cliente creato: {$customer->user->name}";
        if (! $send) {
            return [$title, null];
        }
        $links = app(PasswordResetLinks::class);
        if (! $links->mailEnabled()) {
            return [$title, 'Invio email disattivato: nessun link inviato. Quando lo accendi usa «Invia reset password» sulla sua riga.'];
        }
        try {
            $result = $links->send([$customer->user_id]);
        } catch (Throwable) {
            return [$title, 'Il link per la password non è partito (errore del server di posta). Riprova da «Invia reset password» sulla sua riga.'];
        }

        return [$title, $result['sent'] > 0 ? 'Link per scegliere la password inviato.' : 'Il link per la password non è partito: riprova da «Invia reset password» sulla sua riga.'];
    }
}
