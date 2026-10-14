<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompPlanVersions;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Policy\PolicySpecs;
use App\Domain\Plan\LegacyPlan;
use App\Domain\Plan\PlanFormMapper;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\CompPlanVersions\Pages\CreateCompPlanVersion;
use App\Filament\Resources\CompPlanVersions\Pages\EditCompPlanVersion;
use App\Filament\Resources\CompPlanVersions\Pages\ListCompPlanVersions;
use App\Filament\Resources\CompPlanVersions\Pages\ViewCompPlanVersion;
use App\Models\CompPlanVersion;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Piano compensi versionato: bozza → attivo → archiviato. Le versioni attive non si modificano (RF-060). */
class CompPlanVersionResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'plan.view';
    }

    protected static function managePermission(): string
    {
        return 'plan.manage';
    }

    protected static ?string $model = CompPlanVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'versione del piano';

    protected static ?string $pluralModelLabel = 'Piano compensi';

    protected static ?int $navigationSort = 1;

    /** Il Piano Incaricati ha una pagina propria, fedele alle presentazioni; il piano a PR resta nella forma storica. */
    private static function isIncaricati(Get $get): bool
    {
        return LegacyPlan::hidden() || $get('data.settings.engine', isAbsolute: true) === 'kosmos_v1';
    }

    /** Versione del piano precedente (non Incaricati): con il vecchio piano nascosto non si vede, non si apre, non si modifica. */
    private static function isLegacyVersion(CompPlanVersion $r): bool
    {
        return ! self::isKosmos($r);
    }

    public static function canView($record): bool
    {
        return ! (LegacyPlan::hidden() && $record instanceof CompPlanVersion && self::isLegacyVersion($record));
    }

    public static function form(Schema $schema): Schema
    {
        $inc = static fn (Get $get): bool => self::isIncaricati($get);
        $leg = static fn (Get $get): bool => LegacyPlan::visible() && ! self::isIncaricati($get);

        $policyFields = [];
        foreach (PolicySpecs::all() as $key => $spec) {
            $policyFields[] = self::policyField($key, $spec);
        }
        $settingFields = [];
        foreach (PlanConfig::SETTINGS as $key => $def) {
            if ($key === 'engine') {
                continue;
            }
            $settingFields[] = $key === 'fsb_enabled'
                ? Toggle::make("settings.{$key}")->label($def[0])->helperText($def[2])
                : TextInput::make("settings.{$key}")->label($def[0])->helperText($def[2])->required();
        }
        $policy = static fn (string $key): Toggle|Select => self::policyField($key, PolicySpecs::all()[$key]);

        return $schema->columns(1)->components([
            Section::make('Versione')->columnSpanFull()->columns(2)->schema([
                TextInput::make('name')->label('Nome versione')->required(),
                Select::make('settings.engine')->label('Piano')->required()->live()->visible($leg)
                    ->options(['kosmos_v1' => 'Piano Incaricati (presentazioni 08/10/2026)'] + (LegacyPlan::visible() ? ['multilevel_v3' => 'Piano a PR (pre-lancio, vecchio)'] : []))
                    ->helperText('Scegli «Piano Incaricati» per usare la nuova pagina.'),
                Textarea::make('notes')->label('Note')->rows(2)->visible($leg),
                TextInput::make('reason')->label('Motivo della modifica')->helperText('Finisce nel log di audit insieme ai valori prima e dopo.')
                    ->required(fn ($operation) => $operation === 'edit')->visible(fn ($operation) => $operation === 'edit'),
            ]),

            // ───────── Piano Incaricati ─────────
            Section::make('Base di calcolo')->visible($inc)->columnSpanFull()->columns(2)
                ->description('I compensi si calcolano sul 66,66666% (V.A.P.) dell’importo in euro (IVA inclusa) di ogni acquisto: una Box da 150 € dà una base di 100 €. I punti (PV) e lo stato ATTIVO non esistono più: i compensi maturano sempre, senza soglia di spesa.')
                ->schema([
                    TextInput::make('kosmos.vap_share_pct')->label('Quota dell’importo su cui si calcolano i compensi (V.A.P., %)')->required()->helperText('Vale per i prodotti senza un V.A.P. proprio: ogni prodotto può avere il suo (in % o in euro) dalla scheda prodotto.'),
                    Select::make('kosmos.client_rule')->label('Chi è un cliente')->required()->live()
                        ->options(['any_order' => 'Chi fa almeno 1 ordine nel mese', 'min_amount' => 'Chi fa almeno 1 ordine di un importo minimo'])
                        ->helperText('Vale per scaglioni dei diretti, livelli, qualifiche e Bonus di Struttura. Si può far valere un singolo prodotto come cliente a prescindere dall\'importo: interruttore «Rende cliente» nella scheda prodotto.'),
                    TextInput::make('kosmos.client_min_amount')->label('Importo minimo di un ordine (€, IVA inclusa)')->numeric()->minValue(0)
                        ->required(fn (Get $get): bool => $get('kosmos.client_rule') === 'min_amount')
                        ->visible(fn (Get $get): bool => $get('kosmos.client_rule') === 'min_amount')->dehydratedWhenHidden()
                        ->helperText('Le righe dello stesso ordine si sommano (150 = una Box a listino). Conta il totale dell’ordine, qualunque siano i prodotti. Un ordine sotto questo importo non rende cliente, salvo contenga un prodotto con «Rende cliente» acceso.'),
                    TextInput::make('settings.commission_base_per_pv')->label('Euro per punto dei movimenti importati senza importo')->numeric()->minValue(0)->required()
                        ->helperText('Serve solo per i movimenti storici arrivati dal vecchio sistema con i PV e senza importo (1,5 = 100 PV → 150 €).'),
                    $policy('kosmos_own_purchase_as_client')->columnSpanFull()->helperText(null),
                ]),
            Section::make('Compensi diretti')->visible($inc)->columnSpanFull()
                ->description('Percentuale sul V.A.P. dei clienti personali, in base a quanti clienti personali ha l’incaricato («Da 3 clienti → 15%»).')
                ->schema([
                    Repeater::make('kosmos.direct_tiers')->hiddenLabel()->addActionLabel('Aggiungi scaglione')->defaultItems(0)
                        ->table([TableColumn::make('Da N clienti personali'), TableColumn::make('Percentuale %')])
                        ->schema([
                            TextInput::make('min_clients')->numeric()->minValue(0)->required(),
                            TextInput::make('pct')->required(),
                        ]),
                ]),
            Section::make('Compensi indiretti (reddito residuale)')->visible($inc)->columnSpanFull()
                ->description('Percentuali sugli importi dei clienti della propria downline, per livello. Ogni livello si sblocca con un minimo di clienti personali e di Pearl al 1° livello.')
                ->schema([
                    Repeater::make('kosmos.indirect_levels')->hiddenLabel()->addActionLabel('Aggiungi livello')->defaultItems(0)
                        ->table([TableColumn::make('Livello'), TableColumn::make('Percentuale %'), TableColumn::make('Clienti personali minimi'), TableColumn::make('Pearl al 1° livello minimi')])
                        ->schema([
                            TextInput::make('level')->numeric()->minValue(1)->required(),
                            TextInput::make('pct')->required(),
                            TextInput::make('min_clients')->numeric()->minValue(0)->required(),
                            TextInput::make('min_pearl')->numeric()->minValue(0)->required(),
                        ]),
                ]),
            Section::make('Compensi indiretti estesi')->visible($inc)->columnSpanFull()
                ->description('Ruby, Emerald e Diamond: percentuale su tutti i clienti sotto il 5° livello, fino al 5° livello del successivo con la stessa qualifica.')
                ->schema([
                    Repeater::make('kosmos.extended_pct')->hiddenLabel()->addActionLabel('Aggiungi qualifica')->defaultItems(0)
                        ->table([TableColumn::make('Qualifica'), TableColumn::make('Percentuale %')])
                        ->schema([TextInput::make('rank')->required()->disabled()->dehydrated(), TextInput::make('pct')->required()])
                        ->addable(false)->deletable(false)->reorderable(false),
                ]),

            // ───────── Qualifiche: unica tabella per entrambi i piani ─────────
            Section::make('Qualifiche e requisiti')->columnSpanFull()
                ->description(fn (Get $get) => self::isIncaricati($get)
                    ? 'Oltre al Codice Lamèss. «Struttura» = qualifiche su colonne diverse (es. SAPPHIRE:2, RUBY:2). 0 o vuoto = non richiesto.'
                    : 'Percentuali in punti percentuali: 18 = 18%. I requisiti di struttura indicano quante linee con quale rank servono.')
                ->collapsed(fn (Get $get) => ! self::isIncaricati($get))
                ->schema([
                    Repeater::make('ranks')->hiddenLabel()->addActionLabel('Aggiungi rank')->columns(4)
                        ->addable($leg)->deletable($leg)->reorderable($leg)
                        ->itemLabel(fn (array $state) => ($state['code'] ?? '?').' — '.($state['name'] ?? ''))
                        ->table(fn (Get $get) => self::isIncaricati($get) ? [
                            TableColumn::make('Qualifica'), TableColumn::make('Clienti personali'), TableColumn::make('Pearl al 1° liv.'),
                            TableColumn::make('Struttura (qualifica:n)'), TableColumn::make('Colonne'), TableColumn::make('Clienti per colonna'),
                        ] : null)
                        ->schema(fn (Get $get): array => self::rankFields(self::isIncaricati($get))),
                    $policy('kosmos_rank_maintenance')->visible($inc)->helperText(null),
                    TextInput::make('kosmos.rank_grace_days')->label('Mantieni le qualifiche attuali per … giorni dall’attivazione del piano')->visible($inc)->numeric()->minValue(0)->maxValue(3650)->suffix('giorni')
                        ->helperText('Per questi giorni dall’attivazione del piano le qualifiche non si riducono per mancanza di clienti, così gli incaricati hanno il tempo di passare al nuovo piano (90 = circa tre mesi). I giorni si contano dal giorno di attivazione e ogni mese iniziato nella finestra è protetto per intero. Vuoto o 0 = nessuna protezione a giorni.'),
                    DatePicker::make('kosmos.rank_grace_until')->label('Oppure: mantieni le qualifiche attuali fino al')->visible($inc)->native(false)->displayFormat('d/m/Y')->format('Y-m-d')
                        ->helperText('Data fissa, alternativa ai giorni (vale la più lontana tra le due). Un mese è protetto se finisce entro la data: scegli 31/12 per proteggere anche dicembre. Vuoto = nessuna data fissa.'),
                ]),

            Section::make('Bonus di Struttura (PearlQ)')->visible($inc)->columnSpanFull()->columns(2)
                ->description('Un nuovo incaricato con 3 clienti personali entro 30 giorni dall’attivazione diventa PearlQ e genera un bonus per la sua upline: ogni livello riceve il bonus della propria qualifica meno quello della maggiore qualifica già presente fra chi è diventato PearlQ e se stesso.')
                ->schema([
                    TextInput::make('kosmos.structure_clients')->label('Clienti personali necessari')->numeric()->minValue(1),
                    TextInput::make('kosmos.structure_window_days')->label('Giorni dall’attivazione del Codice Lamèss')->numeric()->minValue(1),
                    Repeater::make('kosmos.structure_bonus')->hiddenLabel()->addActionLabel('Aggiungi qualifica')->defaultItems(0)->columnSpanFull()
                        ->table([TableColumn::make('Qualifica (codice)'), TableColumn::make('Importo €')])
                        ->schema([TextInput::make('rank')->required(), TextInput::make('amount')->required()]),
                ]),
            Section::make('Pagamenti')->visible($inc)->columnSpanFull()->columns(3)
                ->description('Il Bonus di Struttura si calcola ogni settimana (giorno e ora nelle impostazioni di sistema); le altre commissioni si pagano ogni mese.')
                ->schema([
                    TextInput::make('settings.payout_mode')->label('Pagamento delle provvigioni')->helperText('monthly_auto = ogni mese · on_request = su richiesta')->required(),
                    TextInput::make('settings.payout_day')->label('Giorno del mese successivo entro cui si paga')->required(),
                    TextInput::make('settings.min_payout')->label('Pagamento minimo (€)')->required(),
                ]),

            // ───────── Piano a PR (storico) ─────────
            Section::make('Parametri generali')->visible($leg)->description('Soglie, importi e interruttori del piano. Cambiali qui, non nel codice.')
                ->columnSpanFull()->columns(3)->schema($settingFields),
            Section::make('Matching')->visible($leg)->description('Aliquote per livello, separate da virgola (es. «10, 5, 5» = 10% al 1° livello, 5% al 2° e 3°).')
                ->columnSpanFull()->collapsed()->schema([
                    Repeater::make('matching')->hiddenLabel()->addActionLabel('Aggiungi rank')->columns(2)->schema([
                        TextInput::make('rank')->label('Rank (codice)')->required(),
                        TextInput::make('levels')->label('Aliquote %')->required(),
                    ]),
                ]),
            Section::make('Politiche e decisioni')->visible($leg)->description('Le regole ambigue del regolamento. «Provvisorio» = da confermare con il cliente prima di pagare davvero.')
                ->columnSpanFull()->collapsed()->columns(2)->schema($policyFields),
        ]);
    }

    /**
     * Colonne della tabella qualifiche. Nel Piano Incaricati i campi del vecchio piano non esistono proprio
     * (un campo nascosto occuperebbe comunque una cella della tabella).
     *
     * @return list<Component>
     */
    private static function rankFields(bool $inc): array
    {
        $fields = [TextInput::make('code')->label('Codice')->required()->disabled($inc)->dehydrated()];
        if (! $inc) {
            $fields[] = TextInput::make('name')->label('Nome')->required();
        }
        if (! $inc) {
            array_push($fields,
                TextInput::make('sequence')->label('Ordine')->numeric()->required(),
                TextInput::make('pr_required')->label('PR richiesti')->required(),
                TextInput::make('direct_pct')->label('Direct %')->required(),
                TextInput::make('rank_bonus')->label('Rank Bonus (€)')->required(),
            );
        }
        array_push($fields,
            TextInput::make('min_clients')->label('Clienti personali minimi')->numeric()->minValue(0)->default(0),
            TextInput::make('min_pearl_first_level')->label('Pearl al 1° livello minimi')->numeric()->minValue(0)->default(0),
        );
        if ($inc) {
            $fields[] = TextInput::make('structure_text')->label('Struttura')->placeholder('es. CRYSTAL:2');
        }
        array_push($fields,
            TextInput::make('min_columns')->label('Colonne con clienti minimi')->numeric()->minValue(0)->default(0),
            TextInput::make('min_column_clients')->label('Clienti minimi per colonna')->numeric()->minValue(0)->default(0),
        );
        if (! $inc) {
            $fields[] = Select::make('disabled_requirements')->label('Requisiti NON controllati')->multiple()->options([
                'clients' => 'Clienti personali', 'pearl' => 'Pearl al 1° livello', 'structure' => 'Struttura', 'columns' => 'Colonne',
            ])->placeholder('Tutti controllati');
        }
        if (! $inc) {
            array_push($fields,
                Toggle::make('fifty_percent_rule')->label('Regola del 50%'),
                Repeater::make('structure')->label('Requisiti di struttura')->addActionLabel('Aggiungi requisito')->columnSpanFull()->columns(2)->schema([
                    TextInput::make('rank')->label('Rank richiesto (codice)')->required(),
                    TextInput::make('count')->label('Quante linee')->numeric()->minValue(1)->required(),
                ]),
            );
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $spec */
    private static function policyField(string $key, array $spec): Toggle|Select
    {
        $status = ['certain' => 'certo', 'client_decided' => 'deciso dal cliente', 'provisional' => 'provvisorio'][$spec['default_status']] ?? $spec['default_status'];
        $label = "{$spec['decision']} · {$spec['label']} [{$status}]";

        return is_bool($spec['default'])
            ? Toggle::make("policies.{$key}")->label($label)->helperText($spec['source'])
            : Select::make("policies.{$key}")->label($label)->options($spec['options'])->required()->helperText($spec['source']);
    }

    public static function dataFromForm(array $form, array $base): array
    {
        return PlanFormMapper::toData($form, $base);
    }

    public static function canEdit($record): bool
    {
        if (LegacyPlan::hidden() && $record instanceof CompPlanVersion && self::isLegacyVersion($record)) {
            return false;
        }

        return (PlanVersionService::isSuper() || $record->status === CompPlanVersion::DRAFT) && (bool) auth()->user()?->can(static::managePermission());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('name')->label('Nome'),
                TextEntry::make('status')->label('Stato')->badge(),
                TextEntry::make('approved_by')->label('Approvato da')->placeholder('—'),
                TextEntry::make('activated_at')->label('Attivato il')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—'),
                TextEntry::make('effective_from')->label('Efficace dal')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('content_hash')->label('Impronta')->limit(16),
                TextEntry::make('notes')->label('Note')->placeholder('—'),
            ]),
            Section::make('Cosa cambia rispetto alla versione precedente')->collapsed()->schema([
                TextEntry::make('changes')->hiddenLabel()->state(function (CompPlanVersion $r): string {
                    $prev = CompPlanVersion::where('id', '<', $r->id)->orderByDesc('id')->first();
                    if (! $prev) {
                        return 'Prima versione.';
                    }
                    $d = PlanVersionService::diff($prev->data, $r->data);

                    return $d === [] ? 'Nessuna differenza.' : collect($d)->map(fn ($c, $k) => sprintf('%s: %s → %s', $k, $c['prima'] ?? '—', $c['dopo'] ?? '—'))->implode("\n");
                })->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Piano Incaricati')->visible(fn (CompPlanVersion $r) => self::isKosmos($r))->columnSpanFull()->schema([
                TextEntry::make('summary')->hiddenLabel()->state(fn (CompPlanVersion $r) => new HtmlString(self::kosmosSummary($r->data)))->html(),
            ]),
            Section::make('Qualifiche')->visible(fn (CompPlanVersion $r) => ! self::isKosmos($r) && LegacyPlan::visible())->schema([
                TextEntry::make('ranks')->hiddenLabel()->state(function (CompPlanVersion $r): string {
                    return collect($r->data['ranks'] ?? [])->map(fn ($x) => sprintf(
                        '%s — PR %s · Direct %s%% · Rank Bonus %s €', $x['code'] ?? '?', $x['pr_required'] ?? '0', $x['direct_pct'] ?? '0', $x['rank_bonus'] ?? '0',
                    ))->implode("\n");
                })->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Politiche attive')->visible(fn (CompPlanVersion $r) => ! self::isKosmos($r) && LegacyPlan::visible())->schema([
                TextEntry::make('policies')->hiddenLabel()->state(function (CompPlanVersion $r): string {
                    $vals = $r->plan()->policies->toArray();
                    $specs = PolicySpecs::all();

                    return collect($vals)->map(fn ($v, $k) => sprintf('%s · %s = %s [%s]', $specs[$k]['decision'], $k, is_bool($v) ? ($v ? 'sì' : 'no') : $v, $specs[$k]['default_status']))->implode("\n");
                })->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    private static function isKosmos(CompPlanVersion $r): bool
    {
        return ($r->data['settings']['engine'] ?? 'multilevel_v3') === 'kosmos_v1';
    }

    /** Riepilogo del Piano Incaricati nell'ordine delle presentazioni, con le stesse tabelle. */
    private static function kosmosSummary(array $d): string
    {
        $k = $d['kosmos'] ?? [];
        $pct = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 5, ',', ''), '0'), ',').'%';
        $table = static function (array $head, array $rows, string $title, string $note = ''): string {
            $h = '<h4 style="font-weight:600;margin:1.25rem 0 .25rem">'.e($title).'</h4>'.($note !== '' ? '<p style="opacity:.7;font-size:.85rem;margin:0 0 .5rem">'.e($note).'</p>' : '');
            $h .= '<table style="border-collapse:collapse;font-size:.9rem"><thead><tr>';
            foreach ($head as $c) {
                $h .= '<th style="text-align:left;padding:.3rem .9rem .3rem 0;opacity:.7;font-weight:500">'.e($c).'</th>';
            }
            $h .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $h .= '<tr>';
                foreach ($row as $c) {
                    $h .= '<td style="padding:.3rem .9rem .3rem 0;border-top:1px solid rgba(128,128,128,.25)">'.e((string) $c).'</td>';
                }
                $h .= '</tr>';
            }

            return $h.'</tbody></table>';
        };
        $dis = ['clients' => 'clienti', 'pearl' => 'Pearl', 'structure' => 'struttura', 'columns' => 'colonne'];

        $out = '<p>Compensi calcolati sul <strong>'.e($pct($k['vap_share_pct'] ?? 0)).'</strong> dell’importo in euro (V.A.P.). '.(($k['client_rule'] ?? 'min_amount') === 'any_order' ? 'Un cliente è chi fa almeno <strong>1 ordine</strong> nel mese.' : 'Un cliente è chi fa almeno <strong>1 ordine da '.e(number_format((float) ($k['client_min_amount'] ?? 150), 2, ',', '.')).' €</strong> (IVA inclusa) nel mese.').' '.(! empty($k['rank_grace_days']) ? 'Le qualifiche attuali sono mantenute per <strong>'.e((string) (int) $k['rank_grace_days']).' giorni</strong> dall’attivazione del piano. ' : '').(! empty($k['rank_grace_until']) ? 'Le qualifiche attuali sono mantenute fino al <strong>'.e(Carbon::parse($k['rank_grace_until'])->format('d/m/Y')).'</strong>.' : '').'</p>';
        $out .= $table(['Da N clienti personali', 'Percentuale'], array_map(fn ($t) => [$t['min_clients'], $pct($t['pct'])], $k['direct_tiers'] ?? []), 'Compensi diretti');
        $out .= $table(['Livello', 'Percentuale', 'Clienti personali', 'Pearl al 1° liv.'], array_map(fn ($l) => [$l['level'].'°', $pct($l['pct']), $l['min_clients'], $l['min_pearl']], $k['indirect_levels'] ?? []), 'Compensi indiretti');
        $out .= $table(['Qualifica', 'Percentuale'], collect($k['extended_pct'] ?? [])->map(fn ($v, $c) => [$c, $pct($v)])->values()->all(), 'Compensi indiretti estesi', 'Sotto il '.($k['extended_stop_levels'] ?? 5).'° livello, fino al successivo con la stessa qualifica.');
        $out .= $table(['Qualifica', 'Clienti personali', 'Pearl al 1° liv.', 'Struttura', 'Colonne', 'Non controllati'], array_map(fn ($r) => [
            $r['name'].' ('.$r['code'].')', $r['min_clients'] ?? 0, $r['min_pearl_first_level'] ?? 0, PlanFormMapper::structureToText($r['structure'] ?? []) ?: '—',
            ($r['min_columns'] ?? 0) > 0 ? $r['min_columns'].' × '.$r['min_column_clients'] : '—', implode(', ', array_map(fn ($x) => $dis[$x] ?? $x, $r['disabled_requirements'] ?? [])) ?: '—',
        ], $d['ranks'] ?? []), 'Qualifiche e requisiti', 'Ogni qualifica richiede anche il Codice Lamèss.');
        $out .= $table(['Qualifica', 'Importo'], collect($k['structure_bonus'] ?? [])->map(fn ($v, $c) => [$c, number_format((float) $v, 2, ',', '.').' €'])->values()->all(), 'Bonus di Struttura (PearlQ)', ($k['structure_clients'] ?? 3).' clienti personali entro '.($k['structure_window_days'] ?? 30).' giorni dall’attivazione.');
        $st = $d['settings'] ?? [];
        $out .= '<p style="margin-top:1.25rem">Pagamento: <strong>'.e(($st['payout_mode'] ?? '') === 'on_request' ? 'su richiesta' : 'mensile automatico').'</strong>, entro il giorno '.e((string) ($st['payout_day'] ?? '')).' del mese successivo; minimo '.e((string) ($st['min_payout'] ?? '')).' €.</p>';

        return $out;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => LegacyPlan::hidden() ? $query->where('data->settings->engine', 'kosmos_v1') : $query)->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('name')->label('Nome')->searchable(),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                'active' => 'success', 'draft' => 'gray', 'archived' => 'warning', 'pending_approval' => 'danger', default => 'info',
            })->formatStateUsing(fn (string $state) => ['draft' => 'Bozza', 'active' => 'Attiva', 'scheduled' => 'Programmata', 'archived' => 'Archiviata', 'pending_approval' => 'In approvazione'][$state] ?? $state),
            TextColumn::make('effective_from')->label('Efficace dal')->date('d/m/Y')->placeholder('—'),
            TextColumn::make('activated_at')->label('Attivato il')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompPlanVersions::route('/'), 'create' => CreateCompPlanVersion::route('/create'),
            'view' => ViewCompPlanVersion::route('/{record}'), 'edit' => EditCompPlanVersion::route('/{record}/edit'),
        ];
    }
}
