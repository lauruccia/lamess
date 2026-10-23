<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use RuntimeException;

/** KMoney non può addebitare in automatico: serve la conferma del cliente (l'ordine resta in sospeso e il cliente riceve il link). */
final class ConfirmationRequired extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $url = null)
    {
        parent::__construct($message);
    }
}
