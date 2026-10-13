<?php

declare(strict_types=1);

namespace App\Filament\Resources\Periods;

use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Periods\Pages\ListPeriods;
use App\Filament\Resources\Periods\Pages\EditPeriod;
use App\Filament\Resources\Periods\Pages\ViewPeriod;
use App\Domain\Kpi\KpiService;
use App\Models\Period;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Periodi commissionali: sola lettura, il ciclo si governa con le azioni (RF-070/071). */
class PeriodResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'periods.view';
    }

    protected static function managePermission(): string
    {
        return 'periods.manage';
    }

    protected static ?string $model = Period::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'periodo';

    protected static ?string $pluralModelLabel = 'Periodi';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false; // l'apertura passa dall'azione «Apri periodo»
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Periodo')->columns(3)->schema([
                TextEntry::make('code')->label('Mese'),
                TextEntry::make('status')->label('Stato')->badge(),
                TextEntry::make('planVersion.name')->label('Piano')->formatStateUsing(fn ($state, Period $p): ?string => self::planName($p, $state)),
                TextEntry::make('starts_at')->label('Inizio')->dateTime('d/m/Y H:i', 'Europe/Rome'),
                TextEntry::make('ends_at')->label('Fine')->dateTime('d/m/Y H:i', 'Europe/Rome'),
                TextEntry::make('closed_at')->label('Chiuso il')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—'),
            ]),
            Section::make('Ultimo calcolo')->columns(3)->schema([
                TextEntry::make('latest_run')->label('Tipo / stato')->state(fn (Period $p) => ($r = $p->runs()->latest('id')->first()) ? "{$r->type} · {$r->status} · #{$r->id}" : 'nessun calcolo'),
                TextEntry::make('totals')->label('Compensi (€)')->columnSpan(2)->state(function (Period $p): string {
                    $t = $p->runs()->where('status', 'completed')->latest('id')->first()?->totals;

                    return $t === null ? '—' : collect(LegacyPlan::collapseBonusTypes((array) $t))->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ');
                }),
                TextEntry::make('warnings')->label('Avvisi')->columnSpanFull()->state(fn (Period $p) => implode("\n", $p->runs()->where('status', 'completed')->latest('id')->first()?->warnings ?? []) ?: '—'),
            ]),
        ]);
    }

    /** Nome della versione del piano; con il vecchio piano nascosto, quello delle versioni precedenti non si mostra. */
    private static function planName(Period $p, mixed $state): ?string
    {
        $legacyVersion = ($p->planVersion?->data['settings']['engine'] ?? 'multilevel_v3') !== 'kosmos_v1';

        return LegacyPlan::hidden() && $legacyVersion ? '—' : (is_string($state) ? $state : null);
    }

    /** Indicatori del periodo (stessa fonte del cruscotto), calcolati una volta sola per riga. */
    private static function snap(Period $p): array
    {
        static $memo = [];

        return $memo[$p->id] ??= app(KpiService::class)->snapshot($p->code);
    }

    public static function table(Table $table): Table
    {
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
        $num = fn ($v) => number_format((float) $v, 2, ',', '.');

        return $table
            ->defaultSort('code', 'desc')
            ->columns([
                TextColumn::make('code')->label('Mese')->sortable()->searchable(),
                TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                    'open' => 'info', 'calculating' => 'warning', 'review' => 'warning', 'closed' => 'success', default => 'gray',
                }),
                TextColumn::make('planVersion.name')->label('Piano')->limit(28)->formatStateUsing(fn ($state, Period $p): ?string => self::planName($p, $state))->tooltip(fn ($state, Period $p) => self::planName($p, $state)),
                TextColumn::make('cv')->label('Fatturato (CV)')->alignEnd()->state(fn (Period $p) => $eur(self::snap($p)['cv'])),
                TextColumn::make('pv')->label('PV')->alignEnd()->state(fn (Period $p) => $num(self::snap($p)['pv']))->visible(fn (): bool => LegacyPlan::visible()),
                TextColumn::make('commissions')->label('Compensi')->alignEnd()->weight('semibold')->state(fn (Period $p) => $eur(self::snap($p)['commissions_total'])),
                TextColumn::make('payout')->label('Payout')->alignEnd()->state(fn (Period $p) => ($r = self::snap($p)['payout_ratio']) === null ? '—' : number_format($r, 1, ',', '.').' %'),
                TextColumn::make('movements')->label('Movimenti')->alignEnd()->state(fn (Period $p) => self::snap($p)['movements'])
                    ->description(fn (Period $p) => ($n = self::snap($p)['pending_payment']) ? "{$n} da pagare" : null),
                TextColumn::make('last_run')->label('Ultimo calcolo')->state(function (Period $p) {
                    $r = $p->runs()->where('status', 'completed')->latest('id')->first();

                    return $r ? ($r->type === 'official' ? 'Ufficiale' : 'Stima').' · '.$r->finished_at?->setTimezone('Europe/Rome')->format('d/m H:i') : 'nessuno';
                })->color(fn ($state) => $state === 'nessuno' ? 'gray' : null),
                TextColumn::make('closed_at')->label('Chiuso il')->date('d/m/Y')->timezone('Europe/Rome')->placeholder('aperto'),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')->label('Stato')->options(['open' => 'Aperto', 'calculating' => 'In calcolo', 'review' => 'In revisione', 'closed' => 'Chiuso']),
            ])
            ->recordClasses(fn (Period $p) => $p->status === 'closed' ? null : 'lm-row-current')
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPeriods::route('/'), 'view' => ViewPeriod::route('/{record}'), 'edit' => EditPeriod::route('/{record}/edit')];
    }
}
