<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Consultant;
use App\Models\IvdContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Allinea gli incaricati del portale ai contatti Odoo (`anagrafica_odoo.csv`, prodotto da database/import/odoo_partner_etl.py):
 *
 *  - firma della lettera d'incarico: registrata come lettera «firmata in Odoo (storico)», senza PDF e senza certificato (Odoo non li esporta);
 *  - stato KYC: si aggiorna solo se nel portale è ancora «none»; se è diverso resta com'è e finisce in riconciliazione;
 *  - tutto ciò che è dubbio (sponsor diverso, firma senza le tre accettazioni, KYC verificato senza documenti, incaricati creati
 *    solo perché hanno firmato) va in `import_reconciliations`, dove si può correggere o chiudere dopo l'importazione.
 * Non cancella e non sovrascrive niente di ciò che l'amministrazione ha già modificato. Idempotente, nessuna email.
 */
final class OdooAnagraficaImporter
{
    private const TZ = 'Europe/Rome';

    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<string,array{0:string,1:string,2:string,3:?string,4:string,5:array<string,mixed>}> */
    private array $issues = [];

    /** @return array{counts: array<string,int>, issues: array<string,int>} */
    public function import(string $dir, bool $dryRun = false): array
    {
        $rows = $this->rows($dir.DIRECTORY_SEPARATOR.'anagrafica_odoo.csv');
        $fromSignature = [];
        $extra = $dir.DIRECTORY_SEPARATOR.'consulenti_integrazione.csv';
        if (is_file($extra)) {
            $fromSignature = array_fill_keys(array_column($this->rows($extra), 'ref'), true);
        }
        $this->counts = array_fill_keys(['contatti_letti', 'solo_clienti', 'firme_registrate', 'firme_gia_presenti', 'firme_in_sospeso', 'kyc_aggiornati'], 0);
        $this->issues = [];
        $byRef = Consultant::query()->whereNotNull('referral_code')->get()->keyBy('referral_code');
        $refById = $byRef->mapWithKeys(fn (Consultant $c) => [$c->id => $c->referral_code])->all();

        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $this->counts['contatti_letti']++;
                $c = $byRef[$r['ref']] ?? null;
                if ($c === null) {
                    $this->counts['solo_clienti']++;

                    continue;
                }
                $this->signature($c, $r);
                $this->kyc($c, $r);
                $this->sponsor($c, $r, $refById);
                if (isset($fromSignature[$r['ref']])) {
                    $this->issue('incaricato_da_firma', 'warning', $r['ref'], $r['nome'], 'In Odoo non risultava incaricato (nessuna qualifica): creato perché ha firmato la lettera il '.substr($r['firma'], 0, 10).'. Verificare se è davvero un incaricato; se no, trasformarlo di nuovo in cliente.', $r);
                }
            }
            $this->syncIssues();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $perCode = [];
        foreach ($this->issues as $i) {
            $perCode[$i[0]] = ($perCode[$i[0]] ?? 0) + 1;
        }

        return ['counts' => $this->counts, 'issues' => $perCode];
    }

    /** @param array<string,string> $r */
    private function signature(Consultant $c, array $r): void
    {
        if ($r['firma'] === '') {
            if ($r['richiesta_firma'] !== '') {
                $this->counts['firme_in_sospeso']++; // richiesta di firma inviata in Odoo, mai completata
            }

            return;
        }
        if ($c->user_id === null || IvdContract::where('consultant_id', $c->id)->whereIn('status', ['signed', 'accepted'])->exists()) {
            $this->counts['firme_gia_presenti']++;

            return;
        }
        $at = CarbonImmutable::parse($r['firma'], self::TZ)->utc();
        $all = $r['acc_dati'] === '1' && $r['acc_privacy'] === '1' && $r['acc_fiscali'] === '1';
        IvdContract::create([
            'user_id' => $c->user_id, 'consultant_id' => $c->id, 'sponsor_id' => $c->sponsor_id,
            'template_version' => 'odoo-storico', 'status' => 'accepted',
            'signature_provider' => 'odoo', 'signature_ref' => $r['richiesta_firma'] !== '' ? 'odoo-sign-request:'.$r['richiesta_firma'] : null,
            'signed_at' => $at, 'starts_at' => $at, 'expires_at' => null, // nessuna scadenza: il canone degli storici si decide a parte
            'accepted_by' => 'Importazione Odoo', 'accepted_at' => $at,
            'evidence' => [
                'source' => 'odoo', 'note' => 'Lettera firmata in Odoo (storico importato): PDF e prove della firma restano in Odoo.',
                'accettazioni' => ['dati_veritieri' => $r['acc_dati'] === '1', 'privacy' => $r['acc_privacy'] === '1', 'responsabilita_fiscali' => $r['acc_fiscali'] === '1'],
            ],
        ]);
        $this->counts['firme_registrate']++;
        if (! $all) {
            $this->issue('firma_senza_accettazioni', 'info', $r['ref'], $r['nome'], 'Lettera firmata in Odoo ma senza tutte e tre le accettazioni (dati veritieri, privacy, responsabilità fiscali).', $r);
        }
    }

    /** @param array<string,string> $r */
    private function kyc(Consultant $c, array $r): void
    {
        $odoo = $r['kyc'];
        $now = (string) ($c->kyc_status ?: 'none');
        if ($odoo !== 'none' && $now === 'none') {
            $c->forceFill(['kyc_status' => $odoo])->save();
            $this->counts['kyc_aggiornati']++;
            $now = $odoo;
        }
        if ($odoo !== $now && ! ($odoo === 'none' && $now !== 'none')) {
            $this->issue('kyc_diverso', 'warning', $r['ref'], $r['nome'], "KYC nel portale «{$now}», in Odoo «{$odoo}»: non modificato.", $r);
        }
        if ($odoo === 'verified' && ($r['documenti'] === '' || $r['documenti'] === '0')) {
            $this->issue('kyc_verificato_senza_documenti', 'info', $r['ref'], $r['nome'], 'KYC verificato in Odoo ma senza documenti caricati: i documenti non si importano, vanno richiesti di nuovo se servono.', $r);
        }
        if ($odoo === 'rejected' && $r['kyc_rifiuto'] !== '') {
            $this->issue('kyc_rifiutato', 'info', $r['ref'], $r['nome'], 'KYC rifiutato in Odoo: può vendere ma non prelevare finché non è verificato.', $r);
        }
    }

    /**
     * @param  array<string,string>  $r
     * @param  array<int,string>  $refById
     */
    private function sponsor(Consultant $c, array $r, array $refById): void
    {
        if ($r['sponsor_ref'] === '') {
            return;
        }
        $mine = $c->sponsor_id !== null ? ($refById[$c->sponsor_id] ?? null) : null;
        if ($mine !== $r['sponsor_ref']) {
            $this->issue('sponsor_diverso', 'warning', $r['ref'], $r['nome'], 'Sponsor in Odoo: '.$r['sponsor_nome'].' ['.$r['sponsor_ref'].']'.($mine === null ? ', nel portale non ha sponsor.' : ", nel portale: {$mine}.").' Non modificato.', $r);
        }
    }

    private function issue(string $code, string $severity, string $ref, ?string $name, string $message, array $data): void
    {
        $this->issues["{$code}|{$ref}"] = [$code, $severity, $ref, $name, $message, $data];
    }

    private function syncIssues(): void
    {
        $now = now();
        foreach ($this->issues as [$code, $severity, $ref, $name, $message, $data]) {
            $row = ['severity' => $severity, 'subject_name' => $name, 'message' => $message, 'odoo' => json_encode($data), 'updated_at' => $now];
            $key = ['source' => 'odoo', 'code' => $code, 'subject_ref' => $ref];
            if (DB::table('import_reconciliations')->where($key)->exists()) {
                // non riapre ciò che l'amministrazione ha già chiuso o corretto
                DB::table('import_reconciliations')->where($key)->where('status', 'open')->update($row);
            } else {
                DB::table('import_reconciliations')->insert($key + $row + ['status' => 'open', 'created_at' => $now]);
            }
        }
    }

    /** @return list<array<string,string>> */
    private function rows(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File mancante: {$path}");
        }
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, 0, ';', '"', '');
        $out = [];
        while (($line = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $out[] = array_combine($head, array_pad($line, count($head), ''));
        }
        fclose($h);

        return $out;
    }
}
