<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Payouts\PayoutException;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\SepaExport;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Models\Payout;
use App\Models\Period;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListPayouts extends SuperadminListRecords
{
    protected static string $resource = PayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')->authorize('payouts.manage')->label('Prepara pagamenti')->icon('heroicon-o-banknotes')
                ->modalDescription('Somma le liquidazioni aperte di ogni incaricato fino al mese scelto. Sopra la soglia minima e senza blocchi nasce un pagamento «da pagare»; il resto resta cumulato.')
                ->schema([Select::make('code')->label('Fino al mese')->required()->options(fn () => Period::where('status', Period::CLOSED)->orderByDesc('code')->pluck('code', 'code')->all())])
                ->action(function (array $data, PayoutService $svc): void {
                    try {
                        $r = $svc->preparePayouts(Period::where('code', $data['code'])->firstOrFail());
                        Notification::make()->title("{$r['created']} pagamenti preparati per € {$r['amount']}")
                            ->body("Sotto soglia: {$r['below_min']} · saldo negativo: {$r['negative']} · bloccati (KYC/IBAN/sospensione): {$r['blocked']}")->success()->send();
                    } catch (PayoutException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('export')->authorize('payouts.view')->label('Esporta bonifici da fare (CSV)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(url('/admin-files/export/payouts')),
            Action::make('sepa')->authorize('payouts.manage')->label('Esporta file SEPA (XML)')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->modalDescription('File di bonifici multipli da caricare nell\'home banking. Contiene i pagamenti «da pagare» con IBAN valido; gli altri vengono saltati e te li elenco. Prima di usarlo la prima volta fallo controllare dalla tua banca.')
                ->requiresConfirmation()
                ->action(function (SepaExport $sepa) {
                    $payouts = Payout::with('consultant', 'period')->where('status', Payout::TO_PAY)->orderBy('id')->get();
                    try {
                        $r = $sepa->build($payouts, [
                            'name' => (string) config('lamess.sepa.debtor_name'), 'iban' => (string) config('lamess.sepa.debtor_iban'), 'bic' => (string) config('lamess.sepa.debtor_bic'),
                        ], now('Europe/Rome'));
                    } catch (PayoutException $e) {
                        Notification::make()->title('File SEPA non creato')->body($e->getMessage())->danger()->send();

                        return null;
                    }
                    $ids = $payouts->filter(fn (Payout $p) => ! in_array("{$p->consultant->code} ({$p->consultant->name}): IBAN mancante o non valido", $r['skipped'], true))->pluck('id');
                    Payout::whereIn('id', $ids)->update(['exported_at' => now()]);
                    AuditLog::record('export.sepa', "Esportato il file SEPA: {$r['count']} bonifici per {$r['total']} €", null, ['pagamenti' => $ids->all(), 'saltati' => $r['skipped']]);
                    if ($r['skipped'] !== []) {
                        Notification::make()->title('Alcuni pagamenti sono stati saltati')->body(implode("\n", $r['skipped']))->warning()->persistent()->send();
                    }

                    return response()->streamDownload(fn () => print ($r['xml']), 'bonifici-sepa-'.now('Europe/Rome')->format('Ymd-His').'.xml', ['Content-Type' => 'application/xml']);
                }),
        ];
    }
}
