<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Onboarding\Iban;
use App\Models\Consultant;
use Illuminate\Console\Command;

final class LamessImportaIban extends Command
{
    protected $signature = 'lamess:importa-iban {file=database/data/odoo/conti_bancari.csv : CSV con ref;titolare;iban;banca;invio_denaro} {--prova : non scrive nulla, mostra solo il riepilogo} {--tutti : importa anche i conti con «Invio denaro» spento in Odoo} {--sovrascrivi : sostituisce un IBAN già presente}';

    protected $description = 'Inserisce gli IBAN dei conti bancari Odoo (res.partner.bank) sugli incaricati, abbinandoli per codice referral; registra tutto nel log di audit';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        $path = $absolute ? $path : base_path($path);
        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }
        $prova = (bool) $this->option('prova');
        $handle = fopen($path, 'r');
        $head = fgetcsv($handle, 0, ';');
        $byRef = [];
        while (($r = fgetcsv($handle, 0, ';')) !== false) {
            if (count($r) === count($head)) {
                $row = array_combine($head, $r);
                $byRef[$row['ref']][] = $row;
            }
        }
        fclose($handle);

        $esito = ['inseriti' => 0, 'già presenti (uguali)' => 0, 'saltati' => 0];
        $righe = [];
        $viste = [];
        foreach ($byRef as $ref => $conti) {
            $c = $ref === '' ? null : Consultant::where('referral_code', $ref)->first();
            if (! $c) {
                $righe[] = [$ref ?: '—', $conti[0]['titolare'], 'saltato', 'non è un incaricato nel portale (cliente o altro)'];
                $esito['saltati']++;

                continue;
            }
            $candidati = collect($conti)->filter(fn ($x) => $this->option('tutti') || $x['invio_denaro'] === '1')->values();
            if ($candidati->isEmpty()) {
                $righe[] = [$ref, $c->name, 'saltato', 'conto con «Invio denaro» spento in Odoo (usa --tutti per includerlo)'];
                $esito['saltati']++;

                continue;
            }
            $scelto = $candidati->first(fn ($x) => Iban::isValid($x['iban']));
            if (! $scelto) {
                $righe[] = [$ref, $c->name, 'saltato', 'IBAN non valido: '.$candidati->first()['iban']];
                $esito['saltati']++;

                continue;
            }
            $nuovo = Iban::normalize($scelto['iban']);
            $nota = $candidati->count() > 1 ? 'più conti validi: usato il primo' : '';
            if (isset($viste[$nuovo])) {
                $nota = trim($nota.' IBAN già assegnato a '.$viste[$nuovo]);
            }
            if ($c->iban === $nuovo) {
                $esito['già presenti (uguali)']++;

                continue;
            }
            if (filled($c->iban) && ! $this->option('sovrascrivi')) {
                $righe[] = [$ref, $c->name, 'saltato', 'ha già un IBAN diverso (usa --sovrascrivi)'];
                $esito['saltati']++;

                continue;
            }
            $viste[$nuovo] = $c->name;
            if (! $prova) {
                $prima = $c->iban;
                $c->update(['iban' => $nuovo, 'iban_holder' => $c->iban_holder ?: $c->name]);
                AuditLog::record('consultant.iban_import', "IBAN inserito da Odoo per {$c->name}", $c, ['iban' => ['prima' => $prima ? $this->mask($prima) : null, 'dopo' => $this->mask($nuovo)]]);
            }
            $righe[] = [$ref, $c->name, $prova ? 'da inserire' : 'inserito', trim($this->mask($nuovo).' '.$nota)];
            $esito['inseriti']++;
        }

        $this->info(($prova ? '[PROVA — nessuna scrittura] ' : '').'Importazione IBAN');
        $this->table(['Ref', 'Nome', 'Esito', 'Dettaglio'], $righe);
        $this->table(['Voce', 'Numero'], collect($esito)->map(fn ($n, $k) => [$k, $n])->values()->all());

        return self::SUCCESS;
    }

    private function mask(string $iban): string
    {
        return substr($iban, 0, 4).'…'.substr($iban, -4);
    }
}
