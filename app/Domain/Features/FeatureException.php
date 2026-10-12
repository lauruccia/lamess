<?php

declare(strict_types=1);

namespace App\Domain\Features;

use RuntimeException;

/** Operazione sulle funzionalità non consentita (permesso, chiave protetta, regola bloccata). */
final class FeatureException extends RuntimeException {}
