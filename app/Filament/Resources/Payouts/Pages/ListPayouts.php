<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Onboarding\Iban;
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
                        $outside = Payout::where('status', Payout::TO_PAY)->get()->filter(fn (Payout $p) => Iban::isValid((string) $p->iban) && ! Iban::isSepa((string) $p->iban))->count();
                        if ($outside > 0) {
                            Notification::make()->title("{$outside} pagamenti con IBAN fuori area SEPA")
                                ->body('Non entrano nel file SEPA: vanno pagati a parte con un bonifico estero. Scarica «Bonifici fuori area SEPA (CSV)».')->warning()->persistent()->send();
                        }
                    } catch (PayoutException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('export')->authorize('payouts.view')->label('Esporta bonifici da fare (CSV)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(url('/admin-files/export/payouts')),
            Action::make('outsideSepa')->authorize('payouts.view')->label('Bonifici fuori area SEPA (CSV)')->icon('heroicon-o-globe-europe-africa')->color('gray')
                ->modalDescription('Elenco dei pagamenti «da pagare» con IBAN di un paese fuori dall\'area SEPA (per esempio la Tunisia). Non entrano nel file SEPA: si pagano a parte con un bonifico estero, usando IBAN e BIC/SWIFT di questo elenco.')
                ->url(url('/admin-files/export/payouts-fuori-sepa')),
            Action::make('sepa')->authorize('payouts.manage')->label('Esporta file SEPA (XML)')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->modalDescription('File di bonifici multipli da caricare nell\'home banking. Contiene i pagamenti «da pagare» con IBAN valido dell\'area SEPA; quelli con IBAN mancante o non valido vengono saltati e quelli fuori area SEPA restano fuori e vanno pagati a parte (te li elenco). Prima di usarlo la prima volta fallo controllare dalla tua banca.')
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
                    $ids = collect($r['ids']);
                    Payout::whereIn('id', $ids)->update(['exported_at' => now()]);
                    AuditLog::record('export.sepa', "Esportato il file SEPA: {$r['count']} bonifici per {$r['total']} €", null, ['pagamenti' => $ids->all(), 'saltati' => $r['skipped'], 'fuori_sepa' => $r['outside']]);
                    if ($r['outside'] !== []) {
                        Notification::make()->title(count($r['outside']).' pagamenti fuori area SEPA: non sono nel file')
                            ->body("Restano «da pagare» e vanno fatti a parte con un bonifico estero (scarica «Bonifici fuori area SEPA (CSV)»).\n".implode("\n", $r['outside']))->warning()->persistent()->send();
                    }
                    if ($r['skipped'] !== []) {
                        Notification::make()->title('Alcuni pagamenti sono stati saltati')->body(implode("\n", $r['skipped']))->warning()->persistent()->send();
                    }

                    return response()->streamDownload(fn () => print ($r['xml']), 'bonifici-sepa-'.now('Europe/Rome')->format('Ymd-His').'.xml', ['Content-Type' => 'application/xml']);
                }),
        ];
    }
}
