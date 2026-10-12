<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use RuntimeException;

/** Errore di regola di business su ordini, pagamenti e resi (messaggio mostrabile all'utente). */
final class OrderException extends RuntimeException {}
