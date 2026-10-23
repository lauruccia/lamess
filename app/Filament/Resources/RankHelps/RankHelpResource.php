<?php

declare(strict_types=1);

namespace App\Filament\Resources\RankHelps;

use App\Domain\Ledger\RankHelpGrant;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\RankHelps\Pages\ListRankHelps;
use App\Filament\Support\GrantPointsAction;
use App\Models\RankHelp;
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
 * Agenti regalati a un incaricato per aiutarlo a qualificarsi: valgono solo per i requisiti della qualifica
 * (vedi {@see RankHelpGrant}). Si aggiungono dalla finestra «Assegna punti».
 */
class RankHelpResource extends Resource
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

    protected static ?string $model = RankHelp::class;

    protected static ?string $slug = 'rank-helps';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'aiuto in agenti';

    protected static ?string $pluralModelLabel = 'Agenti regalati';

    protected static ?string $navigationLabel = 'Agenti regalati';

    protected static ?int $navigationSort = 5;

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('consultant'))
            ->columns([
                TextColumn::make('starts_at')->label('Dal')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
                TextColumn::make('consultant.name')->label('Beneficiario')->description(fn (RankHelp $h): ?string => $h->consultant?->code)->searchable(),
                TextColumn::make('agents')->label('Agenti regalati')->state(fn (RankHelp $h): string => RankHelpGrant::label($h->rank_code, $h->quantity)),
                TextColumn::make('valid_until')->label('Valgono fino al')->date('d/m/Y')->placeholder('Finché non annullati'),
                TextColumn::make('reason')->label('Causale')->wrap()->searchable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state): string => $state === 'cancelled' ? 'Annullati' : 'Validi')
                    ->color(fn (string $state): string => $state === 'cancelled' ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('consultant_id')->label('Beneficiario')->relationship('consultant', 'name')->searchable()->preload(),
                SelectFilter::make('status')->label('Stato')->options(['confirmed' => 'Validi', 'cancelled' => 'Annullati']),
            ])
            ->recordActions([
                Action::make('cancelHelp')->authorize('periods.manage')->label('Annulla')->iconButton()->tooltip('Annulla gli agenti regalati')
                    ->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (RankHelp $h): bool => $h->status !== 'cancelled')
                    ->requiresConfirmation()->modalHeading('Annullare gli agenti regalati?')
                    ->modalDescription('Gli agenti non contano più per i requisiti della qualifica dal prossimo calcolo. Resta nel log di audit.')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(255)])
                    ->action(function (RankHelp $h, array $data): void {
                        try {
                            app(RankHelpGrant::class)->cancel($h, (string) $data['reason']);
                            Notification::make()->title('Agenti annullati')->success()->send();
                            GrantPointsAction::refreshCalculation();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title('Non annullati')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRankHelps::route('/')];
    }
}
