<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sanctions;

use App\Domain\Access\Superadmin;
use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Sanctions\Pages\ListSanctions;
use App\Filament\Resources\Sanctions\Pages\EditSanction;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\Sanction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Sanzioni in corso e storiche; qui si revocano. */
class SanctionResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'compliance.view';
    }

    protected static function managePermission(): string
    {
        return 'compliance.manage';
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

    protected static ?string $model = Sanction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'sanzione';

    protected static ?string $pluralModelLabel = 'Sanzioni';

    protected static ?int $navigationSort = 4;

    /** Campi per applicare una sanzione. @return list<\Filament\Schemas\Components\Component> */
    public static function applyForm(bool $withConsultant): array
    {
        return array_values(array_filter([
            $withConsultant ? Select::make('consultant_id')->label('Incaricato')->searchable()->required()
                ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', '!=', 'terminated')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : '') : null,
            Select::make('type')->label('Provvedimento')->required()->live()->options(fn () => array_diff_key(Sanction::TYPES, auth()->user()?->can('network.manage') ? [] : ['termination' => 1]))
                ->helperText('Richiamo: solo registrato. Blocco compensi: ferma pagamenti e prelievi. Sospensione: l’incaricato passa a «sospeso». Cessazione: definitiva, i diretti passano allo sponsor superiore.'),
            DatePicker::make('ends_at')->label('Fino al')->minDate(now()->addDay()->toDateString())->visible(fn ($get) => in_array($get('type'), ['commission_hold', 'suspension'], true))
                ->helperText('Vuoto: fino a revoca. Alla scadenza lo stato precedente si ripristina da solo.'),
            Textarea::make('reason')->label('Motivazione (la legge l’incaricato)')->required()->rows(4),
        ]));
    }

    public static function apply(Consultant $c, array $data, ?ComplianceCase $case = null): void
    {
        try {
            $end = ! empty($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'], config('app.timezone'))->endOfDay() : null;
            app(ComplianceService::class)->sanction($c, $data['type'], $data['reason'], $case, $end);
            Notification::make()->title('Provvedimento applicato')->success()->send();
        } catch (ComplianceException $e) {
            Notification::make()->title('Non applicabile')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('consultant.code')->label('Incaricato')->searchable(),
            TextColumn::make('type')->label('Provvedimento')->formatStateUsing(fn (string $state) => Sanction::TYPES[$state] ?? $state)->badge(),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => ['active' => 'In corso', 'lifted' => 'Revocata', 'expired' => 'Scaduta'][$state] ?? $state)
                ->color(fn (string $state) => $state === 'active' ? 'danger' : 'gray'),
            TextColumn::make('starts_at')->label('Dal')->dateTime('d/m/Y', 'Europe/Rome'),
            TextColumn::make('ends_at')->label('Fino al')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('revoca'),
            TextColumn::make('complianceCase.number')->label('Caso')->placeholder('—'),
            TextColumn::make('reason')->label('Motivazione')->limit(60)->wrap(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['active' => 'In corso', 'lifted' => 'Revocata', 'expired' => 'Scaduta'])->default('active'),
            SelectFilter::make('type')->label('Provvedimento')->options(Sanction::TYPES),
        ])->recordActions([
            Action::make('lift')->label('Revoca')->iconButton()->tooltip('Revoca')->icon('heroicon-o-arrow-uturn-left')->color('gray')->authorize('compliance.manage')
                ->visible(fn (Sanction $s) => $s->status === 'active' && $s->type !== 'termination')
                ->schema([Textarea::make('reason')->label('Motivo della revoca')->required()])
                ->action(function (Sanction $s, array $data): void {
                    try {
                        app(ComplianceService::class)->lift($s, $data['reason']);
                        Notification::make()->title('Sanzione revocata')->success()->send();
                    } catch (ComplianceException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSanctions::route('/'), 'edit' => EditSanction::route('/{record}/edit')];
    }
}
