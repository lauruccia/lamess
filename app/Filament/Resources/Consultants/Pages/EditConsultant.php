<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants\Pages;

use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Domain\Career\CareerProgress;
use App\Domain\Features\Feature;
use App\Domain\Network\ConsultantAccess;
use App\Domain\Network\NetworkException;
use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\Iban;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Support\ConsultantAccessAction;
use App\Filament\Support\RankHoldAction;
use App\Filament\Support\SuperadminActions;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Profile;
use App\Models\User;
use App\Support\StatusLabels;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Modifica dei dati di un incaricato, per admin (permesso «network.manage») e super_admin.
 *
 * La scheda è divisa in sezioni per argomento (anagrafica, contatti, pagamenti, iscrizione, piano) e raccoglie in un
 * solo posto i dati che stanno in tabelle diverse (incaricato, utente di accesso, profilo anagrafico). Restano fuori
 * di proposito: lo sponsor (Reg. 2.10.a: si sposta solo dalla scheda, super_admin) e lo stato (sospendi, riattiva e
 * cessa hanno ciascuno le proprie conseguenze e un motivo da registrare). Il codice attività lo cambia solo il
 * super_admin. L'IBAN si mostra mascherato e si sostituisce senza leggerlo per intero (la lettura piena resta
 * nell'azione «Mostra IBAN», che la registra nel log). Ogni salvataggio scrive nel log i valori prima e dopo.
 */
class EditConsultant extends EditRecord
{
    protected static string $resource = ConsultantResource::class;

    /** Il pulsante «Salva modifiche» resta visibile in fondo allo schermo anche con la scheda lunga. */
    public static bool $formActionsAreSticky = true;

    /** Campi dell'anagrafica che stanno nel profilo dell'utente di accesso. */
    private const PROFILE_FIELDS = ['first_name', 'last_name', 'birth_date', 'birth_place', 'address', 'city', 'zip', 'province', 'country_code', 'phone', 'pec'];

    /** Dati personali e recapiti: senza un utente di accesso non hanno dove essere salvati, quindi servono insieme all'email. */
    private const NEEDS_ACCESS_FIELDS = ['birth_date', 'birth_place', 'phone', 'pec', 'address', 'city', 'zip', 'province'];

    /** Colonne dell'incaricato modificabili da qui, con il nome da mostrare nel log. */
    private const CONSULTANT_LABELS = [
        'name' => 'Nome e cognome', 'person_key' => 'Codice fiscale', 'code' => 'Codice attività', 'referral_code' => 'Codice referral',
        'since' => 'Firma della lettera', 'network_since' => 'Nella rete dal', 'onboarding_complete' => 'Onboarding completato',
        'onboarding_completed_at' => 'Onboarding completato il', 'badge_number' => 'Numero tesserino', 'badge_valid_until' => 'Tesserino valido fino al',
        'bonus_hold' => 'Compensi bloccati', 'is_founder' => 'Founder', 'rank_cap_code' => 'Qualifica massima consentita',
        'previous_rank_code' => 'Qualifica precedente', 'fiscal_regime' => 'Regime fiscale', 'kyc_status' => 'Verifica identità (KYC)',
        'iban_holder' => 'Intestatario IBAN', 'rank_hold_reason' => 'Motivo della protezione della qualifica',
    ];

    private const PROFILE_LABELS = [
        'first_name' => 'Nome', 'last_name' => 'Cognome', 'birth_date' => 'Data di nascita', 'birth_place' => 'Luogo di nascita',
        'address' => 'Indirizzo', 'city' => 'Città', 'zip' => 'CAP', 'province' => 'Provincia', 'country_code' => 'Nazione', 'phone' => 'Telefono', 'pec' => 'PEC',
        'tax_code' => 'Codice fiscale (anagrafica)',
    ];

    /** Colonne di testo che, lasciate vuote, si salvano come «nessun valore». */
    private const NULLABLE_TEXT = ['person_key', 'referral_code', 'rank_cap_code', 'previous_rank_code', 'badge_number', 'iban_holder'];

    public function getTitle(): string|Htmlable
    {
        return 'Modifica incaricato';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Aggiorna i dati dell\'incaricato. Sponsor e stato si gestiscono dalla scheda.';
    }

    public function form(Schema $schema): Schema
    {
        $adult = now()->subYears((int) config('lamess.adult_age'))->toDateString();
        $divider = ['style' => 'border-bottom:1px solid rgba(187,180,175,.35);padding-bottom:1.25rem'];
        $noAccess = fn (): bool => $this->getRecord()->user_id === null;
        $needsEmail = fn (Get $get): bool => $noAccess() && collect(self::NEEDS_ACCESS_FIELDS)->contains(fn (string $f): bool => filled($get($f)));
        $dates = fn (DatePicker $d): DatePicker => $d->native(false)->displayFormat('d/m/Y')->format('Y-m-d')->closeOnDateSelection();
        $dateTimes = fn (DateTimePicker $d): DateTimePicker => $d->native(false)->displayFormat('d/m/Y H:i')->timezone('Europe/Rome')->seconds(false);

        return $schema->components([
            View::make('partials.c360-edit-hero')->viewData(fn (): array => $this->heroData())->columnSpanFull(),
            Tabs::make('Dati')->persistTabInQueryString('sezione')->columnSpanFull()->tabs([
                Tab::make('Anagrafica')->icon(Heroicon::OutlinedUser)->schema([
                    Section::make('Dati personali')->description('Come compaiono nella lettera d\'incarico e nei documenti.')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        TextInput::make('first_name')->label('Nome')->required()->maxLength(100),
                        TextInput::make('last_name')->label('Cognome')->required()->maxLength(100),
                        TextInput::make('person_key')->label('Codice fiscale')->trim()->maxLength(20)->columnSpanFull()
                            ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper(trim((string) $state)) : null)
                            ->helperText('Riconosce la stessa persona se si re-iscrive dopo 180 giorni dalla cessazione.'),
                        $dates(DatePicker::make('birth_date')->label('Data di nascita')->maxDate($adult)),
                        TextInput::make('birth_place')->label('Luogo di nascita')->maxLength(120),
                        Text::make('Questo incaricato non ha un accesso al portale: per salvare data e luogo di nascita inserisci prima l\'email nella sezione «Contatti e residenza».')
                            ->visible($noAccess)->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Contatti e residenza')->icon(Heroicon::OutlinedMapPin)->schema([
                    Section::make('Accesso al portale')->description('L\'email con cui entra nel portale e a cui arrivano le comunicazioni.')->aside()->contained(false)->extraAttributes($divider)->schema([
                        Text::make('Non ha ancora un accesso: scrivi qui la sua email per crearlo. Se lasci vuoto, restano solo il nome e i dati d\'iscrizione.')->visible($noAccess),
                        TextInput::make('email')->label('Email di accesso')->email()->trim()->maxLength(190)
                            ->required($needsEmail)
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $userId = $this->getRecord()->user_id;
                                if (blank($value) || $userId === null) {
                                    return;
                                }
                                if (User::withoutGlobalScopes()->where('email', mb_strtolower(trim((string) $value)))->where('id', '!=', $userId)->exists()) {
                                    $fail('Questa email è già usata da un altro utente.');
                                }
                            }])
                            ->helperText(fn (): ?string => $noAccess() ? 'Obbligatoria se compili data di nascita o recapiti: serve a salvarli.' : 'Cambiandola, l\'incaricato entra con la nuova email.'),
                        Toggle::make('send_invite')->label('Invia subito l\'invito per scegliere la password')->default(true)->inline(false)->visible($noAccess),
                    ]),
                    Section::make('Recapiti')->description('Telefono e PEC per le comunicazioni ufficiali.')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        TextInput::make('phone')->label('Telefono')->tel()->maxLength(40),
                        TextInput::make('pec')->label('PEC')->email()->trim()->maxLength(190),
                    ]),
                    Section::make('Residenza')->description('Indirizzo che compare nella lettera d\'incarico.')->aside()->contained(false)->extraAttributes($divider)->columns(6)->schema([
                        TextInput::make('address')->label('Indirizzo')->maxLength(190)->columnSpanFull(),
                        TextInput::make('city')->label('Città')->maxLength(120)->columnSpan(['default' => 6, 'md' => 3]),
                        TextInput::make('zip')->label('CAP')->maxLength(10)->columnSpan(['default' => 3, 'md' => 1]),
                        TextInput::make('province')->label('Prov.')->maxLength(3)->columnSpan(['default' => 3, 'md' => 2]),
                        Select::make('country_code')->label('Nazione')->searchable()->options(ConsultantResource::COUNTRIES)->default('IT')->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Pagamenti e fisco')->icon(Heroicon::OutlinedBanknotes)->schema([
                    Section::make('Pagamento delle provvigioni')->description('Dove ricevono i bonifici. L\'IBAN non si mostra per intero: per cambiarlo scrivi quello nuovo.')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        Text::make(fn (): string => 'IBAN attuale: '.($this->maskedIban() ?? 'non inserito'))->columnSpanFull(),
                        TextInput::make('iban_new')->label('Nuovo IBAN')->trim()->maxLength(40)->placeholder('Lascia vuoto per non cambiarlo')
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! Iban::isValid((string) $value)) {
                                    $fail('IBAN non valido.');
                                }
                            }]),
                        TextInput::make('iban_holder')->label('Intestatario')->maxLength(120)->helperText('Se cambi l\'IBAN e lo lasci vuoto: nome e cognome.'),
                    ]),
                    Section::make('Regime fiscale e verifica identità')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        Select::make('fiscal_regime')->label('Regime fiscale')->native(false)->required()
                            ->options(['occasional' => 'Prestazione occasionale (con ritenuta)', 'vat_holder' => 'Titolare di partita IVA (fattura propria)']),
                        Select::make('kyc_status')->label('Verifica identità (KYC)')->native(false)->required()
                            ->options(['none' => 'Nessun documento', 'submitted' => 'Documenti inviati', 'verified' => 'Verificata', 'rejected' => 'Rifiutata'])
                            ->disabled(fn (): bool => ! $this->canManageKyc())->dehydrated(fn (): bool => $this->canManageKyc())
                            ->helperText('Di norma cambia quando verifichi i documenti nella scheda.'),
                    ]),
                ]),
                Tab::make('Iscrizione e stato')->icon(Heroicon::OutlinedIdentification)->schema([
                    Section::make('Identificativi')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        TextInput::make('code')->label('Codice attività')->required()->maxLength(20)->unique('consultants', 'code', ignoreRecord: true)
                            ->disabled(fn (): bool => ! Superadmin::is())->dehydrated(fn (): bool => Superadmin::is())
                            ->helperText(fn (): string => Superadmin::is() ? 'Cambiarlo cambia il codice mostrato ovunque.' : 'Lo cambia solo il super admin.'),
                        TextInput::make('referral_code')->label('Codice referral')->trim()->maxLength(20)->alphaDash()->unique('consultants', 'referral_code', ignoreRecord: true)
                            ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper(trim((string) $state)) : null)
                            ->helperText('Cambiarlo rende non più validi i link già condivisi.'),
                    ]),
                    Section::make('Posizione in rete')->description('Sponsor e stato hanno regole proprie e si gestiscono dalla scheda.')->aside()->contained(false)->extraAttributes($divider)->schema([
                        Text::make(fn (): string => 'Stato: '.StatusLabels::label($this->getRecord()->status)),
                        Text::make(fn (): string => 'Sponsor: '.($this->sponsorLabel($this->getRecord()->sponsor_id) ?? 'nessuno (radice della rete)')),
                        Text::make(fn (): string => 'Sponsor originale: '.($this->sponsorLabel($this->getRecord()->original_sponsor_id) ?? 'nessuno')),
                        Text::make(fn (): string => 'Cessato il '.$this->getRecord()->terminated_at?->timezone('Europe/Rome')->format('d/m/Y').($this->getRecord()->termination_reason ? ' — '.$this->getRecord()->termination_reason : ''))
                            ->visible(fn (): bool => $this->getRecord()->terminated_at !== null),
                        Actions::make([
                            Action::make('openScheda')->label('Apri la scheda per sospendere, riattivare, cessare o cambiare sponsor')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->link()
                                ->url(fn (): string => $this->viewUrl()),
                        ]),
                    ]),
                    Section::make('Date')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        $dateTimes(DateTimePicker::make('since')->label('Firma della lettera d\'incarico')->required()),
                        $dateTimes(DateTimePicker::make('network_since')->label('Nella rete dal'))
                            ->helperText('Se vuoto vale la data della firma.'),
                    ]),
                    Section::make('Onboarding e tesserino')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        Toggle::make('onboarding_complete')->label('Onboarding completato')->inline(false)->columnSpanFull()
                            ->helperText('Foto, documento, dati fiscali, IBAN e KYC. Si aggiorna anche da solo quando l\'incaricato completa i passaggi.'),
                        TextInput::make('badge_number')->label('Numero tesserino')->maxLength(40),
                        $dates(DatePicker::make('badge_valid_until')->label('Tesserino valido fino al')),
                    ]),
                ]),
                Tab::make('Piano e compensi')->icon(Heroicon::OutlinedSparkles)->schema([
                    Section::make('Compensi e prezzi')->aside()->contained(false)->extraAttributes($divider)->schema([
                        Toggle::make('bonus_hold')->label('Compensi bloccati')->inline(false)
                            ->helperText('Ferma liquidazioni e prelievi finché non lo spegni. Si accende anche da solo con un blocco di conformità.'),
                        Toggle::make('is_founder')->label('Founder')->inline(false)
                            ->helperText('Compra ai prezzi founder dei prodotti che ne hanno uno. I punti non cambiano.'),
                    ]),
                    Section::make('Qualifica')->aside()->contained(false)->extraAttributes($divider)->columns(2)->schema([
                        Select::make('rank_cap_code')->label('Qualifica massima consentita')->native(false)->searchable()->placeholder('Nessun limite')
                            ->options(fn (): array => $this->rankOptions())->helperText('La più alta che può raggiungere, anche se i requisiti ne darebbero una superiore.')->columnSpanFull(),
                        Toggle::make('rank_hold_open')->label('Mantieni la qualifica fino a revoca')->inline(false)->live()->columnSpanFull()
                            ->visible(fn (): bool => $this->holdIsAvailable()),
                        $dates(DatePicker::make('rank_hold_until')->label('Mantieni la qualifica fino al'))
                            ->disabled(fn (Get $get): bool => (bool) $get('rank_hold_open'))->dehydrated()
                            ->visible(fn (): bool => $this->holdIsAvailable())
                            ->helperText('Sono protetti i mesi che finiscono entro la data. Vuoto = nessuna protezione.'),
                        TextInput::make('rank_hold_reason')->label('Motivo della protezione')->maxLength(500)->live(onBlur: true)
                            ->required(fn (Get $get): bool => filled($get('rank_hold_until')) || (bool) $get('rank_hold_open'))
                            ->visible(fn (): bool => $this->holdIsAvailable()),
                        TextInput::make('previous_rank_code')->label('Qualifica del piano precedente')->maxLength(40)
                            ->visible(fn (): bool => Superadmin::is() && ! LegacyPlan::hidden())
                            ->helperText('Solo super admin: serve al confronto con il piano Incaricati.'),
                    ]),
                ]),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function heroData(): array
    {
        /** @var Consultant $c */
        $c = $this->getRecord();
        try {
            $rank = app(CareerProgress::class)->forConsultant($c->id)['rank']['name'] ?? null;
        } catch (Throwable) {
            $rank = null;
        }
        $rank = is_string($rank) ? preg_replace('/^(Consulente|Incaricato) /', '', $rank) : null;
        $sponsor = $c->sponsor_id ? Consultant::query()->find($c->sponsor_id, ['id', 'code', 'name']) : null;

        return [
            'c' => $c, 'rank' => $rank, 'sponsor' => $sponsor,
            'sponsorUrl' => $sponsor ? ConsultantResource::getUrl('view', ['record' => $sponsor->id]) : null,
            'email' => $this->accessUser()?->email,
        ];
    }

    private function accessUser(): ?User
    {
        $id = $this->getRecord()->user_id;

        return $id === null ? null : User::withoutGlobalScopes()->find($id);
    }

    private function sponsorLabel(?int $id): ?string
    {
        $s = $id === null ? null : Consultant::query()->find($id, ['code', 'name']);

        return $s ? "{$s->name} ({$s->code})" : null;
    }

    private function maskedIban(): ?string
    {
        $iban = preg_replace('/\s+/', '', (string) $this->getRecord()->iban);
        if ($iban === '') {
            return null;
        }

        return strlen($iban) <= 8 ? str_repeat('•', strlen($iban)) : substr($iban, 0, 4).str_repeat('•', strlen($iban) - 8).substr($iban, -4);
    }

    private function canManageKyc(): bool
    {
        return Superadmin::is() || (bool) auth()->user()?->can('kyc.manage');
    }

    private function holdIsAvailable(): bool
    {
        return Feature::enabled('consultant360.action.hold_rank') && $this->getRecord()->status !== 'terminated';
    }

    /** @return array<string, string> */
    private function rankOptions(): array
    {
        $options = collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])
            ->mapWithKeys(fn (array $r): array => [$r['code'] => (string) preg_replace('/^(Consulente|Incaricato) /', '', (string) $r['name'])])->all();
        $current = $this->getRecord()->rank_cap_code;
        if (filled($current) && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    private function viewUrl(): string
    {
        return ConsultantResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /** I dati dell'utente di accesso e del profilo entrano nel modulo; l'IBAN no (si mostra mascherato). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Consultant $c */
        $c = $this->getRecord();
        unset($data['iban']);
        $user = $this->accessUser();
        $profile = $user === null ? null : Profile::query()->where('user_id', $user->id)->first();
        [$first, $last] = array_pad(explode(' ', trim($c->name), 2), 2, '');

        return array_merge($data, [
            'first_name' => $profile?->first_name ?: $first,
            'last_name' => $profile?->last_name ?: $last,
            'birth_date' => $profile?->birth_date?->format('Y-m-d'),
            'birth_place' => $profile?->birth_place,
            'address' => $profile?->address, 'city' => $profile?->city, 'zip' => $profile?->zip, 'province' => $profile?->province,
            'country_code' => $profile?->country_code ?: 'IT', 'phone' => $profile?->phone, 'pec' => $profile?->pec,
            'email' => $user?->email, 'send_invite' => true, 'iban_new' => null,
        ], RankHoldAction::fill($c->rank_hold_until?->format('Y-m-d')));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Consultant $record */
        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        // L'accesso è l'unico passaggio che può essere rifiutato per regole di business: se fallisce non si salva nulla.
        if ($record->user_id === null && filled($data['email'] ?? null)) {
            $this->createAccess($record, $data, $name);
            $record->refresh();
        }

        $changes = [];
        try {
            DB::transaction(function () use ($record, $data, $name, &$changes): void {
                $changes += $this->saveBank($record, $data, $name);
                $changes += $this->saveConsultant($record, $data, $name);
                $changes += $this->saveAccessAndProfile($record, $data, $name);
            });
        } catch (NetworkException|OnboardingException $e) {
            Notification::make()->title('Salvataggio non riuscito')->body($e->getMessage())->danger()->send();
            throw new Halt;
        }
        $changes += $this->saveRankHold($record, $data);

        $badges = app(BadgeService::class);
        if (blank($record->refresh()->badge_number) && $badges->isIssuable($record)) {
            $badges->generateQuietly($record); // incaricati iscritti prima che il tesserino nascesse con la firma
        }

        if ($changes !== []) {
            AuditLog::record('consultant.updated', "Dati modificati: incaricato {$record->code} ({$record->name})", $record, $changes);
        }

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function createAccess(Consultant $record, array $data, string $name): void
    {
        try {
            $access = app(ConsultantAccess::class);
            $profile = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));
            $access->grant($record, (string) $data['email'], (bool) ($data['send_invite'] ?? false), $profile);
            ConsultantAccessAction::notifyOutcome((string) $data['email'], $access->inviteSent);
        } catch (NetworkException $e) {
            Notification::make()->title('Accesso non creato')->body($e->getMessage())->danger()->send();
            throw new Halt;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveBank(Consultant $record, array $data, string $name): array
    {
        if (blank($data['iban_new'] ?? null)) {
            return [];
        }
        $before = $this->maskedIban();
        $holder = filled($data['iban_holder'] ?? null) ? (string) $data['iban_holder'] : $name;
        app(OnboardingService::class)->saveBankDetails($record, (string) $data['iban_new'], $holder);
        $record->refresh();

        return ['IBAN' => ['prima' => $before ?? 'non inserito', 'dopo' => $this->maskedIban()]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveConsultant(Consultant $record, array $data, string $name): array
    {
        $attributes = array_intersect_key(['name' => $name] + $data, self::CONSULTANT_LABELS);
        foreach (self::NULLABLE_TEXT as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = filled($attributes[$column]) ? trim((string) $attributes[$column]) : null;
            }
        }
        if (array_key_exists('onboarding_complete', $attributes) && (bool) $attributes['onboarding_complete'] !== (bool) $record->onboarding_complete) {
            $attributes['onboarding_completed_at'] = $attributes['onboarding_complete'] ? now() : null;
        }
        if (array_key_exists('rank_hold_reason', $attributes)) {
            unset($attributes['rank_hold_reason']); // si salva insieme alla data, da saveRankHold()
        }
        if (filled($data['iban_new'] ?? null) && blank($attributes['iban_holder'] ?? null)) {
            unset($attributes['iban_holder']); // con un IBAN nuovo e intestatario vuoto vale il nome, già scritto da saveBank()
        }
        $record->fill($attributes);

        return $this->saveWithDiff($record, self::CONSULTANT_LABELS);
    }

    /**
     * Email, nome dell'utente e profilo anagrafico. Senza utente di accesso non c'è dove salvarli (e il modulo chiede l'email).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveAccessAndProfile(Consultant $record, array $data, string $name): array
    {
        $user = $this->accessUser();
        if ($user === null) {
            return [];
        }
        $user->name = $name;
        if (filled($data['email'] ?? null)) {
            $user->email = mb_strtolower(trim((string) $data['email']));
        }
        $changes = $this->saveWithDiff($user, ['name' => 'Nome utente', 'email' => 'Email di accesso']);

        $profile = Profile::query()->firstOrNew(['user_id' => $user->id]);
        if (! $profile->exists) {
            $profile->type = 'private';
        }
        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $profile->{$field} = filled($data[$field]) ? (is_string($data[$field]) ? trim($data[$field]) : $data[$field]) : ($field === 'country_code' ? 'IT' : null);
            }
        }

        if (array_key_exists('person_key', $data)) {
            $profile->tax_code = filled($data['person_key']) ? mb_strtoupper(trim((string) $data['person_key'])) : null; // l'onboarding legge il codice fiscale dal profilo
        }

        return $changes + $this->saveWithDiff($profile, self::PROFILE_LABELS);
    }

    /**
     * Protezione della qualifica: la data passa da RankHoldAction (che scrive il proprio log); il solo motivo si aggiorna qui.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveRankHold(Consultant $record, array $data): array
    {
        if (! array_key_exists('rank_hold_until', $data) || ! $this->holdIsAvailable()) {
            return [];
        }
        $until = ! empty($data['rank_hold_open']) ? RankHoldAction::OPEN_ENDED : (filled($data['rank_hold_until'] ?? null) ? (string) $data['rank_hold_until'] : null);
        $reason = trim((string) ($data['rank_hold_reason'] ?? ''));
        $record->refresh();
        if ($record->rank_hold_until?->format('Y-m-d') !== $until) {
            RankHoldAction::apply([$record], $until, $reason);

            return [];
        }
        if ($until !== null && $reason !== '' && $reason !== (string) $record->rank_hold_reason) {
            $record->fill(['rank_hold_reason' => $reason]);

            return $this->saveWithDiff($record, self::CONSULTANT_LABELS);
        }

        return [];
    }

    /**
     * Salva il modello e restituisce i campi cambiati, con il nome in italiano, per il log.
     *
     * @param  array<string, string>  $labels
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    private function saveWithDiff(Model $model, array $labels): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $new) {
            $changes[$labels[$field] ?? $field] = ['prima' => $this->plain($model->getOriginal($field)), 'dopo' => $this->plain($model->getAttribute($field))];
        }
        if ($model->isDirty() || ! $model->exists) {
            $model->save();
        }

        return $changes;
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            is_bool($value) => $value ? 'sì' : 'no',
            default => $value,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Torna alla scheda')->icon(Heroicon::OutlinedArrowLeft)->color('gray')->url(fn (): string => $this->viewUrl()),
            SuperadminActions::delete(static::getResource())->record($this->getRecord())->successRedirectUrl(static::getResource()::getUrl('index')),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Salva modifiche');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Annulla')->color('gray')->url(fn (): string => $this->viewUrl());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Dati dell\'incaricato salvati';
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->viewUrl();
    }
}
