<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditEntry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Log di audit — SOLO super_admin e SOLO lettura. Non esiste un permesso «audit»: nessun admin può riceverlo.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'evento di audit';

    protected static ?string $pluralModelLabel = 'Log di audit';

    protected static ?string $navigationLabel = 'Log di audit';

    protected static ?int $navigationSort = 92;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canView($record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('id')->label('Numero'),
                TextEntry::make('occurred_at')->label('Data e ora')->formatStateUsing(fn (string $state) => Carbon::parse($state)->setTimezone('Europe/Rome')->format('d/m/Y H:i:s')),
                TextEntry::make('action')->label('Azione')->badge(),
                TextEntry::make('actor_label')->label('Chi'),
                TextEntry::make('ip')->label('Indirizzo IP')->placeholder('—'),
                TextEntry::make('subject')->label('Su cosa')->state(fn (AuditEntry $r) => $r->subject_type ? $r->subject_type.' #'.$r->subject_id : '—'),
                TextEntry::make('description')->label('Descrizione')->columnSpanFull(),
                TextEntry::make('reason')->label('Motivo')->placeholder('—')->columnSpanFull(),
                TextEntry::make('changes')->label('Dettagli')->placeholder('—')->columnSpanFull()
                    ->state(fn (AuditEntry $r) => $r->changes === null ? null : json_encode($r->changesArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace']),
            ]),
            Section::make('Catena di hash')->collapsed()->columns(1)->schema([
                TextEntry::make('prev_hash')->label('Hash precedente')->fontFamily('mono')->copyable(),
                TextEntry::make('hash')->label('Hash di questa riga')->fontFamily('mono')->copyable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('occurred_at')->label('Quando')->sortable()
                ->formatStateUsing(fn (string $state) => Carbon::parse($state)->setTimezone('Europe/Rome')->format('d/m/Y H:i:s')),
            TextColumn::make('actor_label')->label('Chi')->searchable(),
            TextColumn::make('action')->label('Azione')->badge()->searchable(),
            TextColumn::make('description')->label('Descrizione')->limit(70)->searchable()->tooltip(fn (AuditEntry $r) => $r->description),
            TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('action')->label('Azione')->searchable()
                ->options(fn () => AuditEntry::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            Filter::make('period')->label('Periodo')->schema([DatePicker::make('from')->label('Dal'), DatePicker::make('to')->label('Al')])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d, 'Europe/Rome')->startOfDay()->utc()->format('Y-m-d\TH:i:s')))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', Carbon::parse($d, 'Europe/Rome')->endOfDay()->utc()->format('Y-m-d\TH:i:s.u\Z')))),
        ])->recordActions([ViewAction::make()->iconButton()->tooltip('Visualizza')])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
