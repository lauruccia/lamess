<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tiene i compensi provvisori del mese aperto sempre aggiornati: dopo ogni fatto che cambia i numeri (pagamento, reso,
 * movimento inserito a mano) lancia lo stesso calcolo «live» del ricalcolo notturno, a risposta già inviata.
 * Chi paga o registra non aspetta il calcolo e un errore qui non tocca mai l'operazione che l'ha causato.
 * Il calcolo live non scrive dati ufficiali: i compensi restano provvisori fino al runtime ufficiale e alla chiusura del mese.
 */
final class LiveRecalcDispatcher
{
    public static function afterResponse(): void
    {
        if (! config('lamess.recalc_on_payment', true)) {
            return;
        }
        dispatch(function (): void {
            $lock = Cache::lock('lamess.live_recalc', 120);
            if (! $lock->get()) {
                return;   // un ricalcolo è già in corso: il prossimo evento o il ricalcolo notturno lo recuperano
            }
            try {
                app(NightlyRecalculation::class)->run();
            } catch (Throwable $e) {
                Log::warning('Ricalcolo live non riuscito: '.$e->getMessage());
            } finally {
                $lock->release();
            }
        })->afterResponse();
    }
}
