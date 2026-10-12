<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use RuntimeException;

/** Operazione non consentita nello stato corrente del periodo (es. ricalcolo di un periodo chiuso). */
final class PeriodStateException extends RuntimeException {}
