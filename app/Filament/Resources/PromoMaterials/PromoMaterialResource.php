<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoMaterials;

use App\Domain\Access\Superadmin;
use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\MaterialService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\PromoMaterials\Pages\ListPromoMaterials;
use App\Filament\Resources\PromoMaterials\Pages\EditPromoMaterial;
use App\Models\PromoMaterial;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Materiali promozionali: richieste degli incaricati e materiali ufficiali. */
class PromoMaterialResource extends Resource
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

    protected static ?string $model = PromoMaterial::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'materiale promozionale';

    protected static ?string $pluralModelLabel = 'Materiali da approvare';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $n = PromoMaterial::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    private static function guard(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (ComplianceException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('code')->label('Codice')->placeholder('—')->searchable(),
            TextColumn::make('title')->label('Titolo')->searchable()->limit(50),
            TextColumn::make('submitter.code')->label('Proposto da')->placeholder('Consorzio'),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => ['pending' => 'Da approvare', 'approved' => 'Approvato', 'rejected' => 'Non approvato', 'withdrawn' => 'Ritirato'][$state] ?? $state)
                ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success'][$state] ?? 'gray'),
            IconColumn::make('shared')->label('Per tutta la rete')->boolean(),
            TextColumn::make('valid_until')->label('Valido fino al')->date('d/m/Y')->placeholder('—'),
            TextColumn::make('claims')->label('Testo')->limit(80)->wrap()->toggleable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['pending' => 'Da approvare', 'approved' => 'Approvato', 'rejected' => 'Non approvato', 'withdrawn' => 'Ritirato'])->default('pending'),
        ])->recordActions([
            Action::make('approve')->label('Approva')->iconButton()->tooltip('Approva')->icon('heroicon-o-check')->color('success')->authorize('compliance.manage')
                ->visible(fn (PromoMaterial $m) => $m->status === 'pending')
                ->schema([
                    Toggle::make('shared')->label('Metti a disposizione di tutta la rete')->default(false),
                    DatePicker::make('valid_until')->label('Valido fino al')->helperText('Vuoto: senza scadenza.'),
                    Textarea::make('note')->label('Note per l’incaricato'),
                ])
                ->action(fn (PromoMaterial $m, array $data) => self::guard(fn () => app(MaterialService::class)->approve($m, (string) ($data['note'] ?? ''), (bool) $data['shared'], ! empty($data['valid_until']) ? CarbonImmutable::parse($data['valid_until']) : null), 'Materiale approvato')),
            Action::make('reject')->label('Non approvare')->iconButton()->tooltip('Non approvare')->icon('heroicon-o-x-mark')->color('danger')->authorize('compliance.manage')
                ->visible(fn (PromoMaterial $m) => $m->status === 'pending')
                ->schema([Textarea::make('note')->label('Motivo (lo legge l’incaricato)')->required()])
                ->action(fn (PromoMaterial $m, array $data) => self::guard(fn () => app(MaterialService::class)->reject($m, $data['note']), 'Richiesta respinta')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPromoMaterials::route('/'), 'edit' => EditPromoMaterial::route('/{record}/edit')];
    }
}
