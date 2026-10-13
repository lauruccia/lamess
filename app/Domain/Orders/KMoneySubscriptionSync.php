<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Subscription;

/**
 * Un provider KMoney che sa mandare a KMoney la copia di un abbonamento, perché il cliente lo veda nella sua area KMoney
 * (importo, prossimo addebito, stato) e possa bloccarlo.
 *
 * Separata da KMoneyProvider di proposito: chi implementa solo l'addebito (driver di sviluppo, spento, doppioni nei test) non deve saperne nulla.
 */
interface KMoneySubscriptionSync
{
    /**
     * Manda a KMoney lo stato attuale dell'abbonamento. Idempotente: si può ripetere a ogni cambiamento.
     * Non lancia mai eccezioni per un problema di rete o di collegamento: la copia su KMoney è un servizio al cliente, non una condizione per addebitare.
     */
    public function syncSubscription(Subscription $subscription): void;
}
