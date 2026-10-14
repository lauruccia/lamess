<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Models\Consultant;

/** Prossimo codice attività consecutivo (prefisso e larghezza da config/lamess.php, es. LM00144). */
final class ConsultantCodeGenerator
{
    public function next(): string
    {
        $prefix = (string) config('lamess.consultant_code_prefix');
        $max = 0;
        foreach (Consultant::where('code', 'like', $prefix.'%')->pluck('code') as $code) {
            $max = max($max, (int) substr((string) $code, strlen($prefix)));
        }

        return $prefix.str_pad((string) ($max + 1), (int) config('lamess.consultant_code_width'), '0', STR_PAD_LEFT);
    }
}
