<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Network\ConsultantConversion;
use App\Domain\Network\NetworkException;
use App\Models\Consultant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LamessDeclassaSenzaRank extends Command
{
    protected $signature = 'lamess:declassa-senza-rank {file=database/data/odoo/consulenti_integrazione.csv : CSV (separatore «;») con la colonna «ref» = codice referral} {--prova : non scrive nulla, mostra solo cosa succederebbe}';

    protected $description = 'Rimette tra i clienti chi non ha una qualifica (solo cliente, non incaricato). Salta chi ha già storico economico (prelievi, commissioni, PV…).';

    public function handle(ConsultantConversion $conversion): int
    {
        $path = (string) $this->argument('file');
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        $path = $absolute ? $path : base_path($path);
        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }
        $refs = $this->refs($path);
        $dry = (bool) $this->option('prova');
        $done = [];
        $skipped = [];
        $missing = 0;

        DB::beginTransaction();
        try {
            foreach ($refs as $ref) {
                $c = Consultant::where('referral_code', $ref)->first();
                if ($c === null) {
                    $missing++;

                    continue;
                }
                try {
                    $conversion->demoteToCustomer($c, 'Senza qualifica: è solo cliente');
                    $done[] = [$c->code, $c->name];
                } catch (NetworkException $e) {
                    $skipped[] = [$c->code, $c->name, $e->getMessage()];
                }
            }
            if ($dry) {
                DB::rollBack();
            } else {
                AuditLog::record('import.declassa_senza_rank', 'Declassati a cliente gli incaricati senza qualifica', null, ['declassati' => count($done), 'saltati' => count($skipped)]);
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info(($dry ? '[PROVA — nessuna scrittura] ' : '').'Declassati a cliente: '.count($done).' · saltati: '.count($skipped).' · non presenti nel portale: '.$missing);
        if ($skipped !== []) {
            $this->warn('Restano incaricati (hanno già storico economico): decidi a mano se cessarli.');
            $this->table(['Codice', 'Nome', 'Motivo'], $skipped);
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function refs(string $path): array
    {
        $h = fopen($path, 'r');
        $head = fgetcsv($h, 0, ';');
        $head[0] = ltrim((string) $head[0], "\xEF\xBB\xBF");
        $i = array_search('ref', $head, true);
        $out = [];
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            if ($i !== false && isset($row[$i]) && $row[$i] !== '') {
                $out[] = $row[$i];
            }
        }
        fclose($h);

        return $out;
    }
}
