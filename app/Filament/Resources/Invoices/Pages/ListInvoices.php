<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Domain\Billing\InvoiceService;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ListInvoices extends SuperadminListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendPending')
                ->authorize('orders.manage')
                ->label(fn () => 'Invia le fatture in coda ('.app(InvoiceService::class)->pendingCount().')')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn () => app(InvoiceService::class)->pendingCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Invia le fatture in coda')
                ->modalDescription(fn () => 'Spedisce per email all\'indirizzo configurato (LAMESS_SDI_MAIL_TO) l\'XML di ogni fattura e nota di credito «Da inviare» o in errore. Al massimo 50 per volta: se ne restano, premi ancora.')
                ->action(function (): void {
                    if (($why = InvoiceService::sendBlocker()) !== null) {
                        Notification::make()->title('Invio non eseguito')->body($why)->danger()->send();

                        return;
                    }
                    $r = app(InvoiceService::class)->sendPending(50);
                    $body = "Inviati: {$r['sent']}".($r['failed'] ? ", non riusciti: {$r['failed']} (vedi il colore e il suggerimento nella colonna Consegna)" : '').($r['left'] ? ". Ne restano {$r['left']}: premi ancora." : '.');
                    Notification::make()->title('Invio delle fatture')->body($body)->{$r['failed'] ? 'warning' : 'success'}()->send();
                }),
        ];
    }
}
