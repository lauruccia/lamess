<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalRequests;

use App\Domain\Access\Superadmin;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WithdrawalService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Filament\Resources\WithdrawalRequests\Pages\EditWithdrawalRequest;
use App\Models\WithdrawalRequest;
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

/** Richieste di prelievo dal wallet (modalità di pagamento «su richiesta»). */
class WithdrawalRequestResource extends Resource
{
    use GatedByPermission;

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

    protected static ?string $model = WithdrawalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'richiesta di prelievo';

    protected static ?string $pluralModelLabel = 'Prelievi su richiesta';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $n = WithdrawalRequest::where('status', WithdrawalRequest::REQUESTED)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('gross')->label('Lordo €')->numeric(2),
            TextColumn::make('withholding')->label('Ritenuta €')->numeric(2)->toggleable(),
            TextColumn::make('inps')->label('INPS €')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('fee')->label('Commissione €')->numeric(2)->toggleable(),
            TextColumn::make('net')->label('Netto €')->numeric(2),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                'paid' => 'success', 'rejected', 'cancelled' => 'gray', 'approved' => 'info', default => 'warning',
            }),
            TextColumn::make('document_name')->label('Documento')->placeholder('— (da aggiungere)')->toggleable(),
            TextColumn::make('due_by')->label('Da pagare entro')->date('d/m/Y'),
            TextColumn::make('requested_at')->label('Richiesto il')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['requested' => 'Richiesto', 'approved' => 'Approvato', 'paid' => 'Pagato', 'rejected' => 'Rifiutato', 'cancelled' => 'Annullato']),
        ])->recordActions([
            Action::make('document')->label('Documento')->iconButton()->tooltip('Scarica il documento fiscale')->icon('heroicon-o-document-arrow-down')
                ->visible(fn (WithdrawalRequest $r) => $r->document_path !== null)
                ->action(fn (WithdrawalRequest $record) => \Illuminate\Support\Facades\Storage::disk('local')->download($record->document_path, $record->document_name ?: 'documento')),
            Action::make('approve')->authorize('payouts.manage')->label('Approva')->iconButton()->tooltip('Approva')->icon('heroicon-o-check')->color('success')
                ->visible(fn (WithdrawalRequest $r) => $r->status === WithdrawalRequest::REQUESTED)->requiresConfirmation()
                ->modalDescription('Ricalcola le trattenute e crea il pagamento «da pagare»: poi compare in Pagamenti e nel file SEPA.')
                ->action(function (WithdrawalRequest $record, WithdrawalService $svc): void {
                    try {
                        $svc->approve($record);
                        Notification::make()->title('Prelievo approvato: pagamento creato')->success()->send();
                    } catch (WalletException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reject')->authorize('payouts.manage')->label('Rifiuta')->iconButton()->tooltip('Rifiuta')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (WithdrawalRequest $r) => $r->status === WithdrawalRequest::REQUESTED)
                ->schema([Textarea::make('reason')->label('Motivo (lo legge l’incaricato)')->required()])
                ->action(function (array $data, WithdrawalRequest $record, WithdrawalService $svc): void {
                    try {
                        $svc->reject($record, $data['reason']);
                        Notification::make()->title('Prelievo rifiutato')->success()->send();
                    } catch (WalletException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWithdrawalRequests::route('/'), 'edit' => EditWithdrawalRequest::route('/{record}/edit')];
    }
}
