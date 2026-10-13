<?php

declare(strict_types=1);

namespace App\Filament\Resources\IvdContracts;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Access\Actor;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\OnboardingException;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\IvdContracts\Pages\ListIvdContracts;
use App\Filament\Resources\IvdContracts\Pages\EditIvdContract;
use App\Models\IvdContract;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Domande di incarico IVD: coda di approvazione (RF-015, RF-125). */
class IvdContractResource extends Resource
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

    protected static ?string $model = IvdContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'domanda IVD';

    protected static ?string $pluralModelLabel = 'Domande IVD';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('user.name')->label('Richiedente')->searchable(),
            TextColumn::make('consultant.code')->label('Codice')->placeholder('—'),
            TextColumn::make('sponsor.name')->label('Sponsor')->placeholder('—'),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                'signed' => 'warning', 'accepted' => 'success', 'rejected', 'expired' => 'danger', default => 'gray',
            }),
            TextColumn::make('signed_at')->label('Firmata il')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->sortable(),
            TextColumn::make('expires_at')->label('Scade il')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options([
                'awaiting_signature' => 'In attesa di firma', 'signed' => 'Da accettare', 'accepted' => 'Accettata', 'rejected' => 'Rifiutata', 'expired' => 'Scaduta',
            ])->default('signed'),
        ])->recordActions([
            Action::make('accept')->authorize('network.manage')->label('Accetta')->iconButton()->tooltip('Accetta')->icon('heroicon-o-check')->color('success')
                ->visible(fn (IvdContract $record) => $record->status === 'signed')->requiresConfirmation()
                ->action(function (IvdContract $record): void {
                    try {
                        app(IvdService::class)->accept($record, Actor::label());
                        Notification::make()->title('Domanda accettata: inviata email di benvenuto e generato il tesserino')->success()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reject')->authorize('network.manage')->label('Rifiuta')->iconButton()->tooltip('Rifiuta')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (IvdContract $record) => $record->status === 'signed')
                ->schema([Textarea::make('reason')->label('Motivazione (interna)')])
                ->modalDescription(LegacyPlan::hidden() ? "L'account torna Cliente; gli acquisti dei suoi clienti nel frattempo restano allo sponsor proposto (OPEN-22)." : "L'account torna Cliente; i punti maturati nel frattempo restano allo sponsor proposto (OPEN-22).")
                ->action(function (IvdContract $record, array $data): void {
                    try {
                        app(IvdService::class)->reject($record, Actor::label(), (string) ($data['reason'] ?? ''));
                        Notification::make()->title('Domanda rifiutata')->success()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('pdf')->label('Lettera firmata')->iconButton()->tooltip('Lettera firmata')->icon('heroicon-o-document')->color('gray')
                ->visible(fn (IvdContract $record) => $record->pdf_path !== null)
                ->url(fn (IvdContract $record) => url("/admin-files/contract/{$record->id}"), shouldOpenInNewTab: true),
            Action::make('certificate')->authorize('network.manage')->label('Certificato di firma')->iconButton()->tooltip('Certificato di firma')->icon('heroicon-o-shield-check')->color('gray')
                ->visible(fn (IvdContract $record) => $record->signed_at !== null && $record->signature_provider !== 'odoo')
                ->url(fn (IvdContract $record) => url("/admin-files/contract/{$record->id}/certificate"), shouldOpenInNewTab: true),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIvdContracts::route('/'), 'edit' => EditIvdContract::route('/{record}/edit')];
    }
}
