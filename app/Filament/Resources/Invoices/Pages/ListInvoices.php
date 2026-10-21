<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\EasyfattXmlBuilder;
use App\Domain\Billing\InvoiceService;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

class ListInvoices extends SuperadminListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('easyfatt')
                ->authorize('orders.manage')
                ->label('Esporta per Easyfatt (XML)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->modalHeading('Esporta le fatture per Easyfatt')
                ->modalDescription('Crea un file Easyfatt-Xml con le fatture e le note di credito emesse nel periodo, da importare in Easyfatt con Utilità → Importa documenti Easyfatt-Xml. Non è il file per lo SdI e non cambia nulla nel portale. Easyfatt assegna il suo numero ai documenti importati: il numero del portale resta scritto nelle note interne.')
                ->schema([
                    DatePicker::make('from')->label('Dal')->required()->default(now('Europe/Rome')->startOfMonth()->toDateString())->native(false),
                    DatePicker::make('until')->label('Al')->required()->default(now('Europe/Rome')->toDateString())->native(false)->afterOrEqual('from'),
                    Toggle::make('receipts')->label('Includi anche le ricevute dei privati (entrano come fattura)')->default(false),
                ])
                ->action(function (array $data) {
                    $r = app(EasyfattXmlBuilder::class)->forPeriod((string) $data['from'], (string) $data['until'], (bool) ($data['receipts'] ?? false));
                    if ($r['count'] === 0) {
                        Notification::make()->title('Nessun documento nel periodo')->body($r['receipts_skipped'] > 0 ? "Ci sono {$r['receipts_skipped']} ricevute: riprova includendole." : 'Controlla le date.')->warning()->send();

                        return null;
                    }
                    AuditLog::record('export.easyfatt', "Esportazione Easyfatt-Xml {$data['from']} – {$data['until']}: {$r['invoices']} fatture, {$r['credit_notes']} note di credito", ['Invoice', 'elenco'], ['dal' => $data['from'], 'al' => $data['until'], 'fatture' => $r['invoices'], 'note_credito' => $r['credit_notes'], 'ricevute_escluse' => $r['receipts_skipped']]);
                    if ($r['receipts_skipped'] > 0) {
                        Notification::make()->title('Ricevute non incluse')->body("{$r['receipts_skipped']} ricevute di privati sono rimaste fuori dal file.")->warning()->send();
                    }

                    return response()->streamDownload(fn () => print ($r['xml']), 'easyfatt-lamess-'.$data['from'].'_'.$data['until'].'.xml', ['Content-Type' => 'application/xml']);
                }),
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
