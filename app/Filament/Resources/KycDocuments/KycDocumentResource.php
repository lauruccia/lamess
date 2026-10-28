<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocuments;

use App\Domain\Access\Actor;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\KycDocuments\Pages\ListKycDocuments;
use App\Filament\Resources\KycDocuments\Pages\EditKycDocument;
use App\Models\KycDocument;
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

/** Revisione documenti KYC (RF-014/125). */
class KycDocumentResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'kyc.view';
    }

    protected static function managePermission(): string
    {
        return 'kyc.manage';
    }

    protected static ?string $model = KycDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'documento KYC';

    protected static ?string $pluralModelLabel = 'Documenti KYC';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('type')->label('Documento')->formatStateUsing(fn (string $state) => config('lamess.kyc_document_types')[$state] ?? $state),
            TextColumn::make('original_name')->label('File')->limit(30),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                'pending' => 'warning', 'approved' => 'success', default => 'danger',
            }),
            TextColumn::make('created_at')->label('Caricato il')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['pending' => 'Da verificare', 'approved' => 'Approvati', 'rejected' => 'Rifiutati'])->default('pending'),
        ])->recordActions([
            Action::make('open')->label('Apri')->iconButton()->tooltip('Apri')->icon('heroicon-o-eye')->color('gray')
                ->url(fn (KycDocument $record) => url("/admin-files/kyc/{$record->id}"), shouldOpenInNewTab: true),
            Action::make('approve')->authorize('kyc.manage')->label('Approva')->iconButton()->tooltip('Approva')->icon('heroicon-o-check')->color('success')
                ->visible(fn (KycDocument $record) => $record->status === 'pending')->requiresConfirmation()
                ->action(fn (KycDocument $record) => self::review($record, true)),
            Action::make('reject')->authorize('kyc.manage')->label('Rifiuta')->iconButton()->tooltip('Rifiuta')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (KycDocument $record) => $record->status === 'pending')
                ->schema([Textarea::make('notes')->label('Motivazione (visibile all’incaricato)')->required()])
                ->action(fn (KycDocument $record, array $data) => self::review($record, false, (string) $data['notes'])),
        ]);
    }

    private static function review(KycDocument $doc, bool $approve, string $notes = ''): void
    {
        try {
            app(KycService::class)->review($doc, $approve, Actor::label(), $notes);
            Notification::make()->title($approve ? 'Documento approvato' : 'Documento rifiutato')->success()->send();
        } catch (OnboardingException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListKycDocuments::route('/'), 'edit' => EditKycDocument::route('/{record}/edit')];
    }
}
