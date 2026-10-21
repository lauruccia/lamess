<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Audit\AuditLog;
use App\Filament\Concerns\SavesWithAuditDiff;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\SuperadminActions;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Modifica dei dati di un cliente, per admin (permesso «network.manage») e super_admin: una scheda a sezioni che
 * raccoglie i dati di utente, profilo e cliente. L'incaricato assegnato non si cambia da qui ma con «Riassegna»
 * (ha un motivo da registrare). Ogni salvataggio scrive nel log di audit i valori prima e dopo.
 */
class EditCustomer extends EditRecord
{
    use SavesWithAuditDiff;

    protected static string $resource = CustomerResource::class;

    public static bool $formActionsAreSticky = true;

    private const PROFILE_FIELDS = ['type', 'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec', 'birth_date', 'birth_place', 'phone', 'address', 'city', 'zip', 'province', 'country_code'];

    private const PROFILE_LABELS = [
        'type' => 'Tipo', 'first_name' => 'Nome', 'last_name' => 'Cognome', 'company_name' => 'Ragione sociale', 'tax_code' => 'Codice fiscale',
        'vat_number' => 'Partita IVA', 'sdi_code' => 'Codice SDI', 'pec' => 'PEC', 'birth_date' => 'Data di nascita', 'birth_place' => 'Luogo di nascita',
        'phone' => 'Telefono', 'address' => 'Indirizzo', 'city' => 'Città', 'zip' => 'CAP', 'province' => 'Provincia', 'country_code' => 'Nazione',
    ];

    private const SOURCES = [
        'referral' => 'Dal link referral di un incaricato', 'cookie' => 'Da un link visitato in precedenza', 'form_code' => 'Da un codice scritto nel modulo',
        'invite' => 'Da un invito', 'default' => 'Assegnato all\'incaricato «casa»', 'manual' => 'Inserito a mano dall\'amministrazione',
    ];

    public function getTitle(): string|Htmlable
    {
        return 'Modifica cliente';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Aggiorna i dati del cliente. L\'incaricato assegnato si cambia con «Riassegna».';
    }

    public function form(Schema $schema): Schema
    {
        $divider = ['style' => 'border-bottom:1px solid rgba(187,180,175,.35);padding-bottom:1.25rem'];
        $isCompany = fn (Get $get): bool => $get('type') === 'company';
        $isPrivate = fn (Get $get): bool => $get('type') !== 'company';

        return $schema->components([
            View::make('partials.customer-edit-hero')->viewData(fn (): array => $this->heroData())->columnSpanFull(),
            Tabs::make('Dati')->persistTabInQueryString('sezione')->columnSpanFull()->tabs([
                Tab::make('Anagrafica')->icon(Heroicon::OutlinedUser)->schema([
                    Section::make('Chi è')->description('Privato o azienda, come compare negli ordini e nelle fatture.')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        Select::make('type')->label('Tipo')->native(false)->required()->live()->options(['private' => 'Privato', 'company' => 'Azienda'])->columnSpanFull(),
                        TextInput::make('first_name')->label('Nome')->maxLength(100)->required($isPrivate)->visible($isPrivate),
                        TextInput::make('last_name')->label('Cognome')->maxLength(100)->required($isPrivate)->visible($isPrivate),
                        TextInput::make('company_name')->label('Ragione sociale')->maxLength(150)->required($isCompany)->visible($isCompany)->columnSpanFull(),
                        TextInput::make('tax_code')->label('Codice fiscale')->trim()->maxLength(16)->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper(trim((string) $state)) : null),
                        TextInput::make('vat_number')->label('Partita IVA')->maxLength(20)->required($isCompany)->visible($isCompany),
                        TextInput::make('sdi_code')->label('Codice SDI')->maxLength(7)->helperText('Per la fattura elettronica: serve il codice SDI o la PEC.')->visible($isCompany),
                    ]),
                    Section::make('Nascita')->aside()->contained(false)->extraAttributes($divider)->columns(2)->visible($isPrivate)->schema([
                        DatePicker::make('birth_date')->label('Data di nascita')->native(false)->displayFormat('d/m/Y')->format('Y-m-d')->closeOnDateSelection(),
                        TextInput::make('birth_place')->label('Luogo di nascita')->maxLength(120),
                    ]),
                ]),
                Tab::make('Contatti e residenza')->icon(Heroicon::OutlinedMapPin)->schema([
                    Section::make('Accesso e contatti')->description('L\'email è anche quella con cui entra nel portale.')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        TextInput::make('email')->label('Email di accesso')->email()->trim()->required()->maxLength(190)->columnSpanFull()
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }
                                if (User::withoutGlobalScopes()->where('email', mb_strtolower(trim((string) $value)))->where('id', '!=', $this->getRecord()->user_id)->exists()) {
                                    $fail('Questa email è già usata da un altro utente.');
                                }
                            }])
                            ->helperText('Cambiandola, il cliente entra con la nuova email.'),
                        TextInput::make('phone')->label('Telefono')->tel()->maxLength(40),
                        TextInput::make('pec')->label('PEC')->email()->trim()->maxLength(190),
                    ]),
                    Section::make('Indirizzo di fatturazione')->description('Per gli indirizzi di spedizione il cliente usa la sua area riservata.')->aside()->contained(false)->extraAttributes($divider)->columns(6)->schema([
                        TextInput::make('address')->label('Indirizzo')->maxLength(190)->columnSpanFull(),
                        TextInput::make('city')->label('Città')->maxLength(120)->columnSpan(['default' => 6, 'md' => 3]),
                        TextInput::make('zip')->label('CAP')->maxLength(10)->columnSpan(['default' => 3, 'md' => 1]),
                        TextInput::make('province')->label('Prov.')->maxLength(3)->columnSpan(['default' => 3, 'md' => 2]),
                        Select::make('country_code')->label('Nazione')->searchable()->options(ConsultantResource::COUNTRIES)->default('IT')->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Registrazione')->icon(Heroicon::OutlinedIdentification)->schema([
                    Section::make('Incaricato di riferimento')->description('Chi segue il cliente e a cui vanno i suoi acquisti nel piano.')->aside()->contained(false)->extraAttributes($divider)->schema([
                        Text::make(fn (): string => 'Assegnato a: '.($this->consultantLabel() ?? 'nessun incaricato')),
                        Text::make('Per cambiarlo usa «Riassegna» in alto: chiede un motivo e finisce nel log.'),
                    ]),
                    Section::make('Registrazione')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        DateTimePicker::make('registered_at')->label('Registrato il')->required()->native(false)->displayFormat('d/m/Y H:i')->timezone('Europe/Rome')->seconds(false),
                        Text::make(fn (): string => 'Origine: '.(self::SOURCES[$this->getRecord()->source] ?? (string) $this->getRecord()->source)),
                    ]),
                ]),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function heroData(): array
    {
        /** @var Customer $c */
        $c = $this->getRecord();
        $user = $this->customerUser();
        $profile = $user === null ? null : Profile::query()->where('user_id', $user->id)->first();
        $paid = Order::query()->where('buyer_user_id', $c->user_id)->whereIn('status', Order::PAID_STATES);

        return [
            'name' => $user?->name, 'email' => $user?->email, 'type' => $profile?->type ?? 'private',
            'registeredAt' => $c->registered_at?->timezone('Europe/Rome')->format('d/m/Y'),
            'consultant' => $c->assigned_consultant_id ? Consultant::query()->find($c->assigned_consultant_id, ['id', 'code', 'name']) : null,
            'orders' => (clone $paid)->count(), 'spent' => (float) (clone $paid)->sum('total'),
            'subscriptions' => $c->subscriptions()->where('status', 'active')->count(),
            'isConsultant' => Consultant::query()->where('user_id', $c->user_id)->where('status', '!=', 'terminated')->exists(),
        ];
    }

    private function customerUser(): ?User
    {
        return User::withoutGlobalScopes()->find($this->getRecord()->user_id);
    }

    private function consultantLabel(): ?string
    {
        $c = $this->getRecord()->assigned_consultant_id ? Consultant::query()->find($this->getRecord()->assigned_consultant_id, ['code', 'name']) : null;

        return $c ? "{$c->name} ({$c->code})" : null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->customerUser();
        $profile = $user === null ? null : Profile::query()->where('user_id', $user->id)->first();

        return [
            'registered_at' => $data['registered_at'] ?? null,
            'type' => $profile?->type ?? 'private', 'first_name' => $profile?->first_name, 'last_name' => $profile?->last_name,
            'company_name' => $profile?->company_name, 'tax_code' => $profile?->tax_code, 'vat_number' => $profile?->vat_number, 'sdi_code' => $profile?->sdi_code,
            'pec' => $profile?->pec, 'birth_date' => $profile?->birth_date?->format('Y-m-d'), 'birth_place' => $profile?->birth_place, 'phone' => $profile?->phone,
            'address' => $profile?->address, 'city' => $profile?->city, 'zip' => $profile?->zip, 'province' => $profile?->province,
            'country_code' => $profile?->country_code ?: 'IT', 'email' => $user?->email,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Customer $record */
        $changes = [];
        DB::transaction(function () use ($record, $data, &$changes): void {
            $user = User::withoutGlobalScopes()->findOrFail($record->user_id);
            $profile = Profile::query()->firstOrNew(['user_id' => $user->id]);
            foreach (self::PROFILE_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    $profile->{$field} = filled($value) ? (is_string($value) ? trim($value) : $value) : ($field === 'country_code' ? 'IT' : null);
                }
            }
            if ($profile->type === 'company') {
                $profile->first_name = $profile->last_name = $profile->birth_date = $profile->birth_place = null;
            } else {
                $profile->company_name = $profile->vat_number = $profile->sdi_code = null;
            }
            $user->name = $profile->fullName() ?: $user->name;
            if (filled($data['email'] ?? null)) {
                $user->email = mb_strtolower(trim((string) $data['email']));
            }
            $changes += $this->saveWithDiff($user, ['name' => 'Nome utente', 'email' => 'Email di accesso']);
            $changes += $this->saveWithDiff($profile, self::PROFILE_LABELS);
            if (array_key_exists('registered_at', $data)) {
                $record->fill(['registered_at' => $data['registered_at']]);
            }
            $changes += $this->saveWithDiff($record, ['registered_at' => 'Registrato il']);
        });
        if ($changes !== []) {
            AuditLog::record('customer.updated', "Dati modificati: cliente {$record->user?->email}", $record, $changes);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Torna ai clienti')->icon(Heroicon::OutlinedArrowLeft)->color('gray')->url(fn (): string => $this->listUrl()),
            Action::make('reassign')->label('Riassegna')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')->authorize('network.restructure')
                ->schema(CustomerResource::reassignForm())
                ->action(function (array $data): void {
                    CustomerResource::reassign([$this->getRecord()], $data);
                    $this->getRecord()->refresh();
                    $this->refreshFormData(['assigned_consultant_id']);
                }),
            SuperadminActions::delete(static::getResource())->record($this->getRecord())->successRedirectUrl($this->listUrl()),
        ];
    }

    private function listUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Salva modifiche');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Annulla')->color('gray')->url(fn (): string => $this->listUrl());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Dati del cliente salvati';
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->listUrl();
    }
}
