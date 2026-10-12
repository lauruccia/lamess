<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

/** Numerazione progressiva senza buchi per prefisso e anno (es. FT/2026/00001). */
final class DocumentNumbers
{
    public static function next(string $prefix, int $year): string
    {
        $key = "{$prefix}-{$year}";

        return DB::transaction(function () use ($prefix, $year, $key): string {
            $row = DB::table('document_counters')->where('key', $key)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('document_counters')->insert(['key' => $key, 'value' => 1]);
                $n = 1;
            } else {
                $n = $row->value + 1;
                DB::table('document_counters')->where('key', $key)->update(['value' => $n]);
            }

            return sprintf('%s/%d/%05d', $prefix, $year, $n);
        });
    }
}
