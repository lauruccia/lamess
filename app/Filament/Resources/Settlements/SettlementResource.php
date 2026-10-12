<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settlements;

use App\Domain\Access\Superadmin;
use App\Domain\Payouts\PayoutService;
use App\Domain\Plan\ActiveStatus;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\Pages\EditSettlement;
use App\Filament\Support\ConsultantList;
use App\Models\Settlement;
use App\Support\StatusLabels;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Liquidazioni: quanto ha maturato ogni incaricato in ogni mese chiuso, e a che punto è il pagamento. */
class SettlementResource extends Resource
{
    use GatedByPermission;

    /** Situazione già calcolata per liquidazione (una volta per richiesta). @var array<int, array{label:string, reasons:list<string>}> */
    private static array $situations = [];

    /** Soglia minima di pagamento per periodo (il piano si legge una volta per mese, non per riga). @var array<int, BigDecimal> */
    private static array $minPayouts = [];

    protected static function viewPermission(): string
    {
        return 'payouts.view';
    }

    protected static function managePermission(): string
    {
        return 'payouts.manage';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Superadmin::is();
    }

    public static function canDelete($record): bool
    {
        return Superadmin::is();
    }

    protected static ?string $model = Settlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'liquidazione';

    protected static ?string $pluralModelLabel = 'Liquidazioni';

    protected static ?int $navigationSort = 3;

    /**
     * Relazioni caricate in anticipo e dati calcolati nella stessa query (nessuna query per riga):
     * saldo ancora da pagare dell’incaricato, qualifica e attività nel mese (dal calcolo ufficiale del periodo).
     */
    public static function getEloquentQuery(): Builder
    {
        $stat = static fn (string $col): string => "(select s.{$col} from consultant_period_stats s where s.period_id = settlements.period_id and s.consultant_id = settlements.consultant_id and s.run_id = (select p.official_run_id from periods p where p.id = settlements.period_id))";

        return parent::getEloquentQuery()
            ->with(['period.planVersion', 'consultant', 'payout'])
            ->select('settlements.*')
            ->selectRaw(implode(', ', [
                "(select coalesce(sum(o.accrued), 0) from settlements o where o.consultant_id = settlements.consultant_id and o.status = 'open' and o.payout_id is null) as open_balance",
                $stat('effective_rank').' as stat_rank',
                $stat('is_active').' as stat_active',
            ]));
    }

    /**
     * Situazione della liquidazione: etichetta e motivi (per il suggerimento sul badge).
     *
     * @return array{label:string, reasons:list<string>}
     */
    public static function situationOf(Settlement $s): array
    {
        return self::$situations[$s->id] ??= (function () use ($s): array {
            if ($s->status === 'paid') {
                return ['label' => 'Pagato', 'reasons' => []];
            }
            if ($s->status === 'credited') {
                return ['label' => 'Nel wallet', 'reasons' => ['Accreditato sul wallet dell’incaricato']];
            }
            if ($s->payout_id !== null) {
                return ['label' => 'In pagamento', 'reasons' => ['Il bonifico è stato preparato: da eseguire in Pagamenti']];
            }
            $min = self::$minPayouts[$s->period_id] ??= $s->period->planVersion->plan()->minPayout;
            $sit = app(PayoutService::class)->situation($s->consultant, BigDecimal::of((string) ($s->open_balance ?? '0')), $min);

            return match ($sit['state']) {
                PayoutService::READY => ['label' => 'Pronto', 'reasons' => ['Pagabile: manca solo preparare il pagamento']],
                PayoutService::BELOW_MIN => ['label' => 'Sotto soglia (cumulato)', 'reasons' => $sit['reasons']],
                PayoutService::NEGATIVE => ['label' => 'Saldo negativo', 'reasons' => $sit['reasons']],
                default => ['label' => 'Bloccato: '.implode(', ', $sit['reasons']), 'reasons' => $sit['reasons']],
            };
        })();
    }

    public static function table(Table $table): Table
    {
        $euro = static fn (TextColumn $c): TextColumn => $c->numeric(2)->alignEnd();
        $tone = static fn ($state): ?string => (float) $state < 0 ? 'danger' : ((float) $state === 0.0 ? 'gray' : null);

        return $table->defaultSort('id', 'desc')->striped()->paginated([25, 50, 100])->defaultPaginationPageOption(50)->persistFiltersInSession()
            ->description(function ($livewire): string {
                // Totali dei risultati filtrati, su una sola riga sopra la tabella (al posto delle righe di riepilogo in fondo).
                $base = $livewire->getFilteredTableQuery()->toBase();
                $base->columns = null;
                $t = $base->reorder()->selectRaw('count(*) as n, coalesce(sum(settlements.accrued), 0) as a, coalesce(sum(settlements.held), 0) as h')->first();
                $eur = static fn (mixed $v): string => number_format((float) $v, 2, ',', '.').' €';

                return "{$t->n} liquidazioni · maturato {$eur($t->a)} · trattenuto {$eur($t->h)}";
            })
            ->columns([
                TextColumn::make('period.code')->label('Mese')->sortable()->searchable(),
                TextColumn::make('consultant.code')->label('Codice')->searchable()->copyable()->copyMessage('Codice copiato'),
                TextColumn::make('consultant.name')->label('Incaricato')->searchable()
                    ->url(fn (Settlement $s): string => ConsultantResource::getUrl('view', ['record' => $s->consultant_id])),
                TextColumn::make('stat_rank')->label('Qualifica')->badge()->color('gray')->toggleable()
                    ->formatStateUsing(fn ($state): string => preg_replace('/^Incaricato /', '', app(ConsultantList::class)->rankName($state)))
                    ->placeholder('—'),
                IconColumn::make('stat_active')->label('Attivo')->boolean()->toggleable()->visible(fn (): bool => ActiveStatus::applies())
                    ->tooltip(fn (Settlement $s): ?string => $s->stat_active === null ? 'Dato non disponibile' : ($s->stat_active ? 'Attivo nel mese' : 'Non attivo nel mese: i bonus di qualifica possono essere trattenuti')),
                TextColumn::make('lines_count')->label('Righe')->numeric()->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Numero di righe di compenso che compongono la liquidazione'),
                $euro(TextColumn::make('accrued')->label('Maturato')->sortable()->color($tone)),
                $euro(TextColumn::make('held')->label('Trattenuto')->sortable()
                    ->color(fn ($state): ?string => (float) $state !== 0.0 ? 'warning' : 'gray')),
                $euro(TextColumn::make('open_balance')->label('Saldo')->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('open_balance', $direction))
                    ->color($tone)->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Tutto ciò che l’incaricato deve ancora ricevere (mesi precedenti compresi), non ancora in un pagamento')),
                $euro(TextColumn::make('payout.amount')->label('Bonifico')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Importo del bonifico che comprende questa liquidazione (al netto delle trattenute)')),
                TextColumn::make('payout.paid_at')->label('Pagato')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payout.reference')->label('CRO')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)->copyable(),
                IconColumn::make('consultant.iban')->label('IBAN')->toggleable(isToggledHiddenByDefault: true)
                    ->icon(fn ($state): Heroicon => filled($state) ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                    ->color(fn ($state): string => filled($state) ? 'success' : 'danger')
                    ->tooltip(fn (Settlement $s): string => filled($s->consultant->iban) ? 'IBAN presente' : 'IBAN mancante: blocca il pagamento'),
                TextColumn::make('consultant.kyc_status')->label('KYC')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Settlement $s): string => $s->consultant->kyc_status ?: 'unverified')
                    ->formatStateUsing(fn (string $state) => StatusLabels::label($state))
                    ->color(fn (string $state): string => $state === 'verified' ? 'success' : 'warning'),
                TextColumn::make('created_at')->label('Generata il')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('situation')->label('Situazione')->badge()
                    ->state(fn (Settlement $s): string => self::situationOf($s)['label'])
                    ->tooltip(fn (Settlement $s): ?string => ($r = self::situationOf($s)['reasons']) === [] ? null : implode(' · ', $r))
                    ->color(fn (string $state) => match (true) {
                        $state === 'Pagato' || $state === 'Nel wallet' => 'success', $state === 'In pagamento', $state === 'Pronto' => 'info', str_starts_with($state, 'Bloccato') => 'danger', default => 'warning',
                    }),
            ])->filters([
                SelectFilter::make('period_id')->label('Mese')->relationship('period', 'code'),
                SelectFilter::make('status')->label('Stato')->options(['open' => 'Aperta', 'paid' => 'Pagata', 'credited' => 'Accreditata sul wallet']),
                Filter::make('non_zero')->label('Solo con importi (nascondi 0,00)')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $w) => $w->where('settlements.accrued', '<>', 0)->orWhere('settlements.held', '<>', 0))),
                Filter::make('held')->label('Con importi trattenuti')
                    ->query(fn (Builder $query): Builder => $query->where('settlements.held', '<>', 0)),
                Filter::make('negative')->label('Maturato negativo (recuperi)')
                    ->query(fn (Builder $query): Builder => $query->where('settlements.accrued', '<', 0)),
            ])->recordActions([
                Action::make('statement')->label('Statino')->icon('heroicon-o-document')->color('gray')->iconButton()->tooltip('Statino')
                    ->url(fn (Settlement $s) => url("/admin-files/statement/{$s->period->code}/{$s->consultant_id}"))->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSettlements::route('/'), 'edit' => EditSettlement::route('/{record}/edit')];
    }
}
