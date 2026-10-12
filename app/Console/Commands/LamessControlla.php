<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\GoLiveCheck;
use Illuminate\Console\Command;

final class LamessControlla extends Command
{
    protected $signature = 'lamess:controlla';

    protected $description = 'Controllo messa online: cosa manca prima di aprire il portale (esce con errore se ci sono blocchi)';

    public function handle(GoLiveCheck $check): int
    {
        $rows = $check->run();
        foreach ($rows as $r) {
            $mark = ['ok' => 'OK   ', 'warn' => 'ATTENZ', 'fail' => 'BLOCCO'][$r['status']];
            $this->line(sprintf('%-7s %s — %s', $mark, $r['label'], $r['detail']));
        }
        $this->newLine();
        $summary = GoLiveCheck::summary($rows);
        $fail = collect($rows)->contains('status', 'fail');
        $fail ? $this->error($summary) : $this->info($summary);

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
