<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use RuntimeException;

/** Invito non consentito (duplicato, limite giornaliero, reinvio troppo ravvicinato…): il messaggio è per l'incaricato. */
final class ReferralInviteException extends RuntimeException {}
