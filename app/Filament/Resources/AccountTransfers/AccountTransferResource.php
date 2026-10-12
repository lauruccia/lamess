<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountTransfers;

use App\Domain\Access\Superadmin;
use App\Domain\Succession\SuccessionException;
use App\Domain\Succession\TransferService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\AccountTransfers\Pages\ListAccountTransfers;
use App\Filament\Resources\AccountTransfers\Pages\EditAccountTransfer;
use App\Models\AccountTransfer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Cessioni del codice attività: fee, approvazione e subentro. */
class AccountTransferResource extends Resource
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

    protected static ?string $model = AccountTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'cessione';

    protected static ?string $pluralModelLabel = 'Cessioni del codice';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        $n = AccountTransfer::where('status', 'paid')->count();

        return $n > 0 ? (string) $n : null;
    }

    public const STATUS = ['awaiting_payment' => 'In attesa della fee', 'paid' => 'Da decidere', 'approved' => 'Approvata', 'rejected' => 'Respinta', 'cancelled' => 'Annullata'];

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (SuccessionException $e) {
            Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable(),
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('assignor.email')->label('Cedente'),
            TextColumn::make('assignee.email')->label('Cessionario'),
            TextColumn::make('fee_amount')->label('Fee €')->numeric(2),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => self::STATUS[$state] ?? $state)
                ->color(fn (string $state) => ['paid' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
            TextColumn::make('requested_at')->label('Richiesta')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(self::STATUS),
        ])->recordActions([
            Action::make('fee')->label('Fee pagata')->iconButton()->tooltip('Fee pagata')->icon('heroicon-o-banknotes')->color('success')->authorize('fees.manage')
                ->visible(fn (AccountTransfer $t) => $t->status === 'awaiting_payment')
                ->schema([Select::make('method')->label('Come è stata pagata')->options(['transfer' => 'Bonifico', 'card' => 'Carta', 'cash' => 'Altro'])->default('transfer')->required()])
                ->action(fn (AccountTransfer $t, array $data) => self::run(fn () => app(TransferService::class)->markFeePaid($t, $data['method']), 'Fee registrata')),
            Action::make('approve')->label('Approva')->iconButton()->tooltip('Approva')->icon('heroicon-o-check')->color('success')->authorize('network.manage')
                ->visible(fn (AccountTransfer $t) => $t->status === 'paid')
                ->modalDescription('Il codice passa subito al nuovo intestatario e resta sospeso finché non firma la nuova Lettera IVD e tu non la accetti. Verifica prima i documenti di entrambi.')
                ->schema(fn (AccountTransfer $t) => [
                    Text::make('Compensi maturati e non ancora pagati: '.app(TransferService::class)->unpaidBalance($t->consultant).' €'),
                    Toggle::make('follows')->label('Eventuali compensi non pagati passano al nuovo intestatario')->default(false)
                        ->helperText('Spento: se c\'è un saldo positivo l\'approvazione si ferma, e va prima liquidato al cedente.'),
                    Textarea::make('note')->label('Nota interna'),
                ])
                ->action(fn (AccountTransfer $t, array $data) => self::run(fn () => app(TransferService::class)->approve($t, (bool) $data['follows'], (string) ($data['note'] ?? '')), 'Cessione approvata')),
            Action::make('reject')->label('Respingi')->iconButton()->tooltip('Respingi')->icon('heroicon-o-x-mark')->color('danger')->authorize('network.manage')
                ->visible(fn (AccountTransfer $t) => $t->isOpen())
                ->modalDescription('Il diniego è discrezionale e non va motivato al richiedente (Reg. 2.10.c). La fee non viene restituita in automatico.')
                ->schema([Textarea::make('note')->label('Nota interna (facoltativa)')])
                ->action(fn (AccountTransfer $t, array $data) => self::run(fn () => app(TransferService::class)->reject($t, (string) ($data['note'] ?? '')), 'Richiesta respinta')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccountTransfers::route('/'), 'edit' => EditAccountTransfer::route('/{record}/edit')];
    }
}
