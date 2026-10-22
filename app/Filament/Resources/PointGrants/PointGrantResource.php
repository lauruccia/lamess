<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointGrants;

use App\Domain\Ledger\PointsGrant;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\PointGrants\Pages\ListPointGrants;
use App\Filament\Support\GrantPointsAction;
use App\Models\PvMovement;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * Punti assegnati dall'azienda a un incaricato senza un acquisto (quota del consorzio, premi, correzioni a discrezione).
 * Sono movimenti del registro di tipo «grant»: l'elenco li mostra con causale ed effetto sul piano.
 */
class PointGrantResource extends Resource
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

    protected static ?string $model = PvMovement::class;

    protected static ?string $slug = 'point-grants';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'assegnazione di punti';

    protected static ?string $pluralModelLabel = 'Punti assegnati';

    protected static ?string $navigationLabel = 'Punti assegnati';

    protected static ?int $navigationSort = 4;

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', PointsGrant::TYPE);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['consultant', 'period']))
            ->columns([
                TextColumn::make('event_at')->label('Data')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
                TextColumn::make('consultant.name')->label('Beneficiario')->description(fn (PvMovement $m): ?string => $m->consultant?->code)->searchable(),
                TextColumn::make('pv')->label('Punti')->numeric(2)->alignEnd()->sortable(),
                TextColumn::make('note')->label('Causale')->wrap()->searchable(),
                TextColumn::make('effect')->label('Effetto sul piano')->state(fn (PvMovement $m): string => PointsGrant::effectOf($m)),
                TextColumn::make('client')->label('Cliente conteggiato')->placeholder('—')
                    ->state(fn (PvMovement $m): ?string => $m->client_user_id === null ? null : User::withoutGlobalScopes()->find($m->client_user_id)?->name),
                TextColumn::make('amount')->label('Importo €')->numeric(2)->placeholder('—')->alignEnd(),
                TextColumn::make('period.code')->label('Periodo')->sortable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state): string => $state === 'cancelled' ? 'Annullata' : 'Valida')
                    ->color(fn (string $state): string => $state === 'cancelled' ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('consultant_id')->label('Beneficiario')->relationship('consultant', 'name')->searchable()->preload(),
                SelectFilter::make('status')->label('Stato')->options(['confirmed' => 'Valide', 'cancelled' => 'Annullate']),
            ])
            ->recordActions([
                Action::make('cancelGrant')->authorize('periods.manage')->label('Annulla')->iconButton()->tooltip('Annulla l\'assegnazione')
                    ->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (PvMovement $m): bool => $m->status !== 'cancelled' && ! $m->period->isClosed())
                    ->requiresConfirmation()->modalHeading('Annullare l\'assegnazione?')
                    ->modalDescription('I punti non contano più (per qualifica e compensi) e il cliente indicato non vale più come cliente per questa assegnazione. Resta nel registro e nel log di audit.')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(255)])
                    ->action(function (PvMovement $m, array $data): void {
                        try {
                            app(PointsGrant::class)->cancel($m, (string) $data['reason']);
                            Notification::make()->title('Assegnazione annullata')->success()->send();
                            GrantPointsAction::refreshCalculation();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title('Non annullata')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPointGrants::route('/')];
    }
}
