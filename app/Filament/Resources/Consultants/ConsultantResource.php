<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consultants;

use App\Domain\Career\CareerProgress;
use App\Domain\Consultants\Consultant360;
use App\Domain\Features\Feature;
use App\Domain\Ledger\PointsGrant;
use App\Domain\Network\ConsultantCodeGenerator;
use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\Iban;
use App\Domain\Plan\ActiveStatus;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Consultants\Pages\CreateConsultant;
use App\Filament\Resources\Consultants\Pages\EditConsultant;
use App\Filament\Resources\Consultants\Pages\ListConsultants;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Filament\Support\ConsultantAccessAction;
use App\Filament\Support\ConsultantFilter;
use App\Filament\Support\ConsultantList;
use App\Filament\Support\FounderAction;
use App\Filament\Support\KycRequestAction;
use App\Filament\Support\PasswordResetAction;
use App\Filament\Support\RankHoldAction;
use App\Filament\Support\SendDocumentsAction;
use App\Models\Consultant;
use App\Models\User;
use App\Support\StatusLabels;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/** Incaricati: l'anagrafica passa da NetworkService; lo sponsor non è modificabile (Reg. 2.10.a). */
class ConsultantResource extends Resource
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

    protected static ?string $model = Consultant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'incaricato';

    protected static ?string $pluralModelLabel = 'Incaricati';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        $adult = now()->subYears((int) config('lamess.adult_age'))->toDateString();
        $personal = ['birth_date', 'birth_place', 'address', 'city', 'zip', 'province', 'phone', 'pec'];

        $six = ['default' => 1, 'md' => 6];
        $half = ['md' => 3];
        $third = ['md' => 2];

        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 3])->columnSpanFull()->schema([
                Group::make([
                    Section::make('1 · Iscrizione')->description('Codice, sponsor e data della lettera. Lo sponsor è facoltativo e non si cambia dopo l\'iscrizione.')->columns($six)->schema([
                        TextInput::make('code')->label('Codice attività')->required()->unique('consultants', 'code')
                            ->default(fn (): string => app(ConsultantCodeGenerator::class)->next())
                            ->disabled(fn (): bool => auth()->user()?->isSuperAdmin() !== true)->dehydrated()
                            ->helperText('Automatico; lo cambia solo il super admin.')->columnSpan($third),
                        Select::make('sponsor_id')->label('Sponsor (facoltativo)')->searchable()->placeholder('Nessuno: cerca per nome o codice')
                            ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', 'active')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                            ->getOptionLabelUsing(fn ($value) => Consultant::find($value)?->name)
                            ->helperText('Se resta vuoto entra senza sponsor. Non modificabile dopo l\'iscrizione.')->columnSpan(['md' => 4]),
                        DateTimePicker::make('since')->label('Firma Lettera IVD (ora italiana)')->timezone('Europe/Rome')->required()->default(now())->seconds(false)->columnSpan($half),
                    ]),
                    Section::make('2 · Anagrafica')->description('Solo persone fisiche maggiorenni.')->columns($six)->schema([
                        TextInput::make('first_name')->label('Nome')->required()->visibleOn('create')->columnSpan($half),
                        TextInput::make('last_name')->label('Cognome')->required()->visibleOn('create')->columnSpan($half),
                        TextInput::make('name')->label('Nome e cognome')->required()->visibleOn('edit')->columnSpanFull(),
                        DatePicker::make('birth_date')->label('Data di nascita')->maxDate($adult)->visibleOn('create')->columnSpan($half),
                        TextInput::make('birth_place')->label('Luogo di nascita')->visibleOn('create')->columnSpan($half),
                        TextInput::make('person_key')->label('Codice fiscale')->trim()->maxLength(20)->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper(trim((string) $state)) : null)
                            ->helperText('Serve anche a riconoscere la stessa persona in caso di re-iscrizione dopo 180 giorni.')->columnSpanFull(),
                    ]),
                    Section::make('3 · Residenza e contatti')->description('Per la lettera d\'incarico.')->columns($six)->visibleOn('create')->schema([
                        TextInput::make('address')->label('Indirizzo di residenza')->columnSpanFull(),
                        TextInput::make('city')->label('Città')->columnSpan($third),
                        TextInput::make('zip')->label('CAP')->maxLength(10)->columnSpan($third),
                        TextInput::make('province')->label('Provincia')->maxLength(3)->columnSpan($third),
                        Select::make('country_code')->label('Nazione')->default('IT')->searchable()->options(self::COUNTRIES)->columnSpan($half),
                        TextInput::make('phone')->label('Telefono')->tel()->columnSpan($half),
                        TextInput::make('pec')->label('PEC')->email()->trim()->columnSpan($half),
                        TextInput::make('email')->label('Email di accesso')->email()->trim()->requiredWith($personal)->columnSpan($half)
                            ->helperText('Obbligatoria se compili anagrafica o contatti. Riceve il link per scegliere la password.'),
                    ]),
                    Section::make('4 · Pagamento delle provvigioni (facoltativo)')->description('Se non lo conosci, lo inserisce l\'incaricato nell\'onboarding.')->columns($six)->visibleOn('create')->schema([
                        TextInput::make('iban')->label('IBAN')->trim()->maxLength(40)->columnSpan(['md' => 4])
                            ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (filled($value) && ! Iban::isValid((string) $value)) {
                                    $fail('IBAN non valido.');
                                }
                            }]),
                        TextInput::make('iban_holder')->label('Intestatario')->helperText('Se vuoto: nome e cognome.')->columnSpan($third),
                    ]),
                ])->columnSpan(['xl' => 2]),
                Group::make([
                    Section::make('Come funziona')->visibleOn('create')->compact()->schema([
                        Text::make('Compila i dati che conosci: i campi con l\'asterisco sono obbligatori.'),
                        Text::make('Con l\'email si crea l\'accesso al portale e parte l\'invito per scegliere la password.'),
                        Text::make('La lettera di incarico si firma dopo con OTP, oppure indichi tu la data di firma.'),
                        Text::make('Documenti d\'identità e foto si caricano nell\'onboarding; senza di essi non partono pagamenti e commissioni indirette.'),
                    ]),
                ])->columnSpan(['xl' => 1]),
            ]),
        ]);
    }

    /** Nazioni proposte nel modulo (codice ISO a 2 lettere). */
    private const COUNTRIES = [
        'IT' => 'Italia', 'ES' => 'Spagna', 'DE' => 'Germania', 'NL' => 'Olanda', 'FR' => 'Francia', 'IE' => 'Irlanda',
        'PT' => 'Portogallo', 'AT' => 'Austria', 'BE' => 'Belgio', 'CH' => 'Svizzera', 'GB' => 'Regno Unito', 'PA' => 'Panama',
    ];

    /**
     * Scheda 360°: dati chiave in alto, pulsanti-contatore che aprono le liste filtrate, tab per ogni area e storico
     * unico a lato. Ogni tab, il blocco dei contatori e lo storico si accendono dal registro funzionalità
     * («Sistema → Funzionalità e viste»): spegnerli li nasconde e non cambia nessun dato.
     */
    public static function infolist(Schema $schema): Schema
    {
        $career = fn (Consultant $c): ?array => app(CareerProgress::class)->forConsultant($c->id);
        $data = fn (string $tab) => fn (Consultant $record): array => ['t' => (new Consultant360($record))->tab($tab)];
        $tab = fn (string $key, string $label, string $view = 'partials.c360-tab') => Tab::make($label)
            ->visible(fn (): bool => Feature::enabled("consultant360.tab.{$key}"))
            ->schema([View::make($view)->viewData($data($key))]);

        return $schema->components([
            // Riga alta: scheda riassuntiva (a sinistra) e storico (a destra, scorrevole). Sotto, i tab a tutta larghezza.
            Grid::make(['default' => 1, 'xl' => 3])->columnSpanFull()->extraAttributes(['style' => 'align-items:stretch'])->schema([
                Grid::make(1)->columnSpan(fn (): array|string => Feature::enabled('consultant360.section.timeline') ? ['xl' => 2] : 'full')->schema([
                    Section::make()->schema([
                        View::make('partials.c360-hero')->viewData(function (Consultant $record) use ($career): array {
                            $c360 = new Consultant360($record);
                            $p = $career($record);
                            $ids = array_filter([$record->sponsor_id, $record->original_sponsor_id]);
                            $people = $ids ? Consultant::query()->whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id') : collect();
                            $url = static fn (?Consultant $x): ?string => $x ? static::getUrl('view', ['record' => $x->id]) : null;

                            return [
                                'c' => $record, 'rank' => $p['rank']['name'] ?? 'Qualifica —', 'active' => $p === null ? null : (bool) $p['activity']['active'], 'showActivity' => ActiveStatus::applies(),
                                'sponsor' => $people->get($record->sponsor_id), 'original' => $people->get($record->original_sponsor_id),
                                'sponsorUrl' => $url($people->get($record->sponsor_id)), 'originalUrl' => $url($people->get($record->original_sponsor_id)),
                                'directs' => Consultant::where('sponsor_id', $record->id)->count(),
                                'balance' => number_format((float) $c360->openBalance(), 2, ',', '.').' €',
                                'counters' => Feature::enabled('consultant360.section.counters') ? $c360->counters(auth()->user()) : [],
                            ];
                        }),
                    ]),
                ]),
                Section::make('Storico')->description('Dal più recente; le note fissate stanno in cima.')->compact()
                    ->visible(fn (): bool => Feature::enabled('consultant360.section.timeline'))->columnSpan(['xl' => 1])
                    ->schema([
                        View::make('partials.c360-timeline')->viewData(fn (Consultant $record): array => ['events' => (new Consultant360($record))->timeline(80, Feature::enabled('consultant360.section.notes'))]),
                    ]),
            ]),
            Tabs::make('Scheda')->persistTabInQueryString('tab')->columnSpanFull()->tabs([
                Tab::make('Riepilogo')->visible(fn (): bool => Feature::enabled('consultant360.tab.overview'))->schema([
                    View::make('partials.career-progress')->viewData(fn (Consultant $record) => ['p' => $career($record)]),
                    View::make('partials.granted-points')->viewData(fn (Consultant $record): array => ['g' => PointsGrant::summaryFor($record), 'admin' => true]),
                ]),
                $tab('profile', 'Anagrafica'),
                $tab('network', 'Rete'),
                $tab('volumes', 'Volumi e rank'),
                $tab('commissions', 'Commissioni'),
                $tab('wallet', 'Pagamenti'),
                $tab('sales', LegacyPlan::hidden() ? 'Ordini' : 'Ordini e PV'),
                $tab('documents', 'Documenti'),
                $tab('audit', 'Audit'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $list = static fn (): ConsultantList => app(ConsultantList::class);
        $inRun = static fn (Builder $query, string $col, callable $cond): Builder => ($run = app(ConsultantList::class)->runId()) === null
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('consultants.id', DB::table('consultant_period_stats')->where('run_id', $run)->where($cond)->select('consultant_id'));

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $list()->decorate($query))
            ->defaultSort('consultants.id', 'desc')
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->persistSortInSession()
            ->searchPlaceholder('Cerca nome, codice, email…')
            ->filtersLayout(FiltersLayout::Dropdown)->filtersFormWidth(Width::ThreeExtraLarge)->filtersFormMaxHeight('26rem')->filtersFormColumns(2)->deferFilters(false)
            ->columns([
                TextColumn::make('code')->label('Codice')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->label('Nome')->weight('semibold')->limit(20)
                    ->description(fn (Consultant $r): ?string => $r->user_email ? Str::limit($r->user_email, 28) : null)
                    ->tooltip(fn (Consultant $r): string => trim($r->name.($r->user_email ? "\n".$r->user_email : '')))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn ($w) => $w
                        ->where('consultants.name', 'like', "%{$search}%")->orWhere('consultants.referral_code', 'like', "%{$search}%")
                        ->orWhere('consultants.person_key', 'like', "%{$search}%")
                        ->orWhereIn('consultants.user_id', User::query()->where('email', 'like', "%{$search}%")->select('id'))))
                    ->sortable(),
                TextColumn::make('status')->label('Stato')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => StatusLabels::label($state))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'suspended' => 'warning', 'terminated' => 'danger', default => 'gray',
                    }),
                TextColumn::make('stat_rank')->label('Qualifica')->badge()->color('primary')
                    ->state(fn (Consultant $r): ?string => $r->stat_rank ?? ($r->status === 'terminated' ? null : $list()->entryRankCode()))
                    ->formatStateUsing(fn (?string $state) => ($n = $list()->rankName($state)) === 'Incaricato' ? $n : preg_replace('/^(Consulente|Incaricato) /', '', $n))
                    ->placeholder('—')->tooltip(fn (Consultant $r): ?string => $r->stat_rank === null ? 'Qualifica d\'ingresso: non ancora nel calcolo del periodo' : (($p = $list()->periodCode()) ? "Dal calcolo del periodo {$p}" : null)),
                TextColumn::make('stat_active')->label('Attività mese')->badge()->visible(fn (): bool => ActiveStatus::applies())
                    ->state(fn (Consultant $r): string => $r->stat_active === null ? '—' : ((bool) $r->stat_active ? 'Attivo' : 'Non attivo'))
                    ->color(fn (string $state) => match ($state) {
                        'Attivo' => 'success', 'Non attivo' => 'warning', default => 'gray',
                    })
                    ->tooltip(fn (Consultant $r): ?string => filled($r->stat_reason) ? (string) $r->stat_reason : (LegacyPlan::hidden() ? 'Requisiti del mese per maturare le commissioni' : 'Soglia di PR del mese per maturare le commissioni')),
                TextColumn::make('rank_hold_until')->label('Qualifica mantenuta fino al')->formatStateUsing(fn ($state): string => RankHoldAction::label($state ? Carbon::parse($state)->format('Y-m-d') : null))->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (Consultant $r): ?string => $r->rank_hold_reason),
                TextColumn::make('sponsor_name')->label('Sponsor')
                    ->state(fn (Consultant $r): ?string => $r->sponsor_name)->placeholder('— (radice)')
                    ->limit(22)->tooltip(fn (Consultant $r): ?string => $r->sponsor_name ? $r->sponsor_name.' · '.$r->sponsor_code : null)
                    ->url(fn (Consultant $r): ?string => $r->sponsor_id ? static::getUrl('view', ['record' => $r->sponsor_id]) : null)
                    ->searchable(false),
                TextColumn::make('directs_count')->label('Team 1° liv.')->tooltip('Incaricati sponsorizzati direttamente')->numeric()->alignEnd()->sortable()
                    ->url(fn (Consultant $r): ?string => $r->directs_count > 0 ? static::getUrl('index', ['filters' => ['sponsor' => ['value' => $r->id]]]) : null),
                TextColumn::make('stat_group_pr')->label('PR pers. / gruppo')->alignEnd()->placeholder('—')
                    ->state(fn (Consultant $r): ?string => $r->stat_pr === null ? null : number_format((float) $r->stat_pr, 0, ',', '.').' / '.number_format((float) $r->stat_group_pr, 0, ',', '.'))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('stat_group_pr', $direction))
                    ->visible(fn (): bool => Feature::enabled('backoffice.consultants_list.volumes'))->toggleable(),
                TextColumn::make('open_balance')->label('Da pagare')->money('EUR', locale: 'it')->alignEnd()->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('open_balance', $direction))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null)
                    ->visible(fn (): bool => Feature::enabled('backoffice.consultants_list.balance'))->toggleable(),
                TextColumn::make('kyc_status')->label('KYC')->badge()->toggleable()
                    ->state(fn (Consultant $r): string => $r->kyc_status ?: 'unverified')
                    ->formatStateUsing(fn (string $state) => $state === 'none' ? 'Nessuno' : StatusLabels::label($state))
                    ->color(fn (string $state) => $state === 'verified' ? 'success' : ($state === 'rejected' ? 'danger' : 'warning')),
                IconColumn::make('is_founder')->label('Founder')->tooltip('Incaricato founder: prezzi founder sui prodotti')->boolean()->toggleable(),
                IconColumn::make('onboarding_complete')->label('Onb.')->tooltip('Onboarding completo')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('referral_code')->label('Referral')->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('since')->label('Dal')->size('sm')->dateTime('d/m/y', 'Europe/Rome')->sortable(),
            ])->filters([
                ConsultantFilter::make('sponsor_id', 'sponsor', 'Sponsor'),
                SelectFilter::make('status')->label('Stato')->multiple()->options(['pending' => 'In attesa', 'active' => 'Attivo', 'suspended' => 'Sospeso', 'terminated' => 'Cessato']),
                SelectFilter::make('rank')->label('Qualifica')->options(fn (): array => $list()->rankNames())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $inRun($query, 'effective_rank', fn ($w) => $w->where('effective_rank', $data['value'])) : $query),
                SelectFilter::make('activity')->label('Attività del mese')->options(['active' => 'Attivi', 'inactive' => 'Non attivi'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $inRun($query, 'is_active', fn ($w) => $w->where('is_active', $data['value'] === 'active' ? 1 : 0)) : $query),
                SelectFilter::make('kyc_status')->label('KYC')->options(['verified' => 'Verificato', 'unverified' => 'Da verificare', 'none' => 'Nessun documento', 'rejected' => 'Rifiutato'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'verified', 'none', 'rejected' => $query->where('consultants.kyc_status', $data['value']),
                        'unverified' => $query->where(fn ($w) => $w->whereNull('consultants.kyc_status')->orWhereNotIn('consultants.kyc_status', ['verified', 'none', 'rejected'])),
                        default => $query,
                    }),
                TernaryFilter::make('onboarding_complete')->label('Onboarding completo')->placeholder('Tutti')->trueLabel('Completo')->falseLabel('Da completare'),
                TernaryFilter::make('has_balance')->label('Saldo da pagare')->placeholder('Tutti')->trueLabel('Con saldo')->falseLabel('Senza saldo')
                    ->visible(fn (): bool => Feature::enabled('backoffice.consultants_list.balance'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('consultants.id', DB::table('settlements')->where('status', 'open')->whereNull('payout_id')->groupBy('consultant_id')->havingRaw('sum(accrued) <> 0')->select('consultant_id')),
                        false: fn (Builder $query) => $query->whereNotIn('consultants.id', DB::table('settlements')->where('status', 'open')->whereNull('payout_id')->groupBy('consultant_id')->havingRaw('sum(accrued) <> 0')->select('consultant_id')),
                    ),
                TernaryFilter::make('is_root')->label('Posizione in rete')->placeholder('Tutti')->trueLabel('Con sponsor')->falseLabel('Radici (senza sponsor)')->nullable()->attribute('sponsor_id'),
                Filter::make('since')->label('Iscritti dal…')->schema([
                    DatePicker::make('from')->label('Dal'), DatePicker::make('until')->label('Al'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn ($w, $v) => $w->where('consultants.since', '>=', Carbon::parse($v, 'Europe/Rome')->startOfDay()->utc()))
                    ->when($data['until'] ?? null, fn ($w, $v) => $w->where('consultants.since', '<=', Carbon::parse($v, 'Europe/Rome')->endOfDay()->utc()))),
            ])->toolbarActions([
                KycRequestAction::bulk(),
                RankHoldAction::bulk()->visible(fn (): bool => Feature::enabled('consultant360.action.hold_rank')),
                SendDocumentsAction::bulk(),
                PasswordResetAction::bulk(),
            ])->recordActions([
                ActionGroup::make([
                    KycRequestAction::forRow(),
                    PasswordResetAction::forRow(),
                    ...ConsultantAccessAction::forRow(),
                    RankHoldAction::forRow()->visible(fn (Consultant $record): bool => $record->status !== 'terminated' && Feature::enabled('consultant360.action.hold_rank')),
                    FounderAction::forRow()->visible(fn (Consultant $record): bool => $record->status !== 'terminated'),
                    Action::make('printBadge')->authorize('network.manage')->label('Stampa tesserino')->icon('heroicon-o-identification')->color('gray')
                        ->visible(fn (Consultant $record): bool => app(BadgeService::class)->isIssuable($record))
                        ->url(fn (Consultant $record): string => url("/admin-files/badge/{$record->id}"), shouldOpenInNewTab: true),
                ])->icon('heroicon-m-ellipsis-vertical')->tooltip('Altre azioni')->label('Azioni'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsultants::route('/'), 'create' => CreateConsultant::route('/create'),
            'view' => ViewConsultant::route('/{record}'),
            'edit' => EditConsultant::route('/{record}/edit'),
        ];
    }
}
