<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListWalletTransactions extends SuperadminListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->authorize('payouts.manage')->label('Rettifica manuale')->icon('heroicon-o-adjustments-horizontal')
                ->modalDescription('Aggiunge (importo positivo) o toglie (negativo) un importo dal wallet. Il motivo è obbligatorio e finisce nel log.')
                ->schema([
                    Select::make('consultant_id')->label('Incaricato')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Consultant::where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} [{$c->code}]"])->all()),
                    TextInput::make('amount')->label('Importo € (con segno)')->required()->numeric(),
                    Textarea::make('reason')->label('Motivo')->required(),
                ])
                ->action(function (array $data, WalletService $svc): void {
                    try {
                        $svc->adjust(Consultant::findOrFail($data['consultant_id']), (string) $data['amount'], $data['reason']);
                        Notification::make()->title('Rettifica registrata')->success()->send();
                    } catch (WalletException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
