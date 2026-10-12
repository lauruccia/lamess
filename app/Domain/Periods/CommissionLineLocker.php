<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Models\CalculationRun;
use Illuminate\Support\Facades\DB;

/** Blocca (lock) le righe di un runtime ufficiale alla chiusura del periodo. */
final class CommissionLineLocker
{
    public static function lock(CalculationRun $run): int
    {
        return DB::table('commission_lines')->where('run_id', $run->id)->update(['status' => 'locked', 'updated_at' => now()]);
    }
}
