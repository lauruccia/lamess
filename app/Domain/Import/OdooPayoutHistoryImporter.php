<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Payouts\FiscalCalculator;
use App\Models\CommissionReceipt;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Importa i prelievi storici di Odoo (`prelievi.csv`, prodotto da database/import/odoo_prelievi_etl.py):
 * richieste di prelievo, pagamenti già eseguiti e ricevute (numerate come in Odoo, `COMM…/AAAA/MM/NNNN`: serie a parte, mai rinumerate).
 *
 *  - «Pagato» = prelievo liquidato in Odoo OPPURE autofattura pagata (in Odoo lo stato «Liquidato» non è usato con coerenza);
 *  - lo storico NON tocca il wallet: gli importi non coperti da settlement («non tracciati») finiscono in riconciliazione,
 *    dove l'amministratore decide se registrare un saldo di apertura;
 *  - il netto di Odoo (ritenuta 23% sul 78% e commissione 2,50 €) viene riverificato con il calcolatore del portale;
 *  - idempotente (`external_ref`), nessuna email.
 */
final class OdooPayoutHistoryImporter
{
    private const TZ = 'Europe/Rome';

    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<string,array{0:string,1:string,2:string,3:?string,4:string,5:array<string,mixed>}> */
    private array $issues = [];

    /** @return array{counts: array<string,int>, issues: array<string,int>} */
    public function import(string $dir, bool $dryRun = false): array
    {
        $rows = $this->rows($dir.DIRECTORY_SEPARATOR.'prelievi.csv');
        $this->counts = array_fill_keys(['prelievi_creati', 'prelievi_gia_presenti', 'pagamenti_creati', 'ricevute_create', 'prelievi_rifiutati', 'prelievi_aperti'], 0);
        $this->issues = [];
        $refToId = DB::table('consultants')->whereNotNull('referral_code')->pluck('id', 'referral_code')->all();

        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $this->importRow($r, $refToId);
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

    /** @param array<string,string> $r @param array<string,int> $refToId */
    private function importRow(array $r, array $refToId): void
    {
        $key = 'odoo:'.substr(sha1($r['ref'].'|'.$r['richiesta'].'|'.$r['lordo']), 0, 16);
        $subject = $r['autofattura'] !== '' ? $r['autofattura'] : $key;
        if (WithdrawalRequest::where('external_ref', $key)->exists()) {
            $this->counts['prelievi_gia_presenti']++;

            return;
        }
        $consultant = isset($refToId[$r['ref']]) ? Consultant::find($refToId[$r['ref']]) : null;
        if ($consultant === null) {
            $this->issue('prelievo_senza_incaricato', 'error', $subject, $r['nome'], "Prelievo di {$r['lordo']} € del {$r['richiesta']}: incaricato {$r['ref']} non presente nel portale.", $r);

            return;
        }

        $gross = $r['lordo'];
        $fee = $r['costo_amm'] !== '' ? $r['costo_amm'] : '0.00';
        $calc = FiscalCalculator::compute($consultant->fiscal_regime ?? 'occasional', $gross, '0', $fee, FiscalCalculator::ODOO_PARAMS);
        $net = $r['netto'];
        $withholding = $calc['withholding'];
        if (abs((float) $calc['net'] - (float) $net) > 0.011) {
            $withholding = number_format((float) $gross - (float) $net - (float) $fee, 2, '.', '');
            $this->issue('prelievo_netto_diverso', 'warning', $subject, $r['nome'], "Netto Odoo {$net} € ≠ calcolato {$calc['net']} € su lordo {$gross} €.", ['odoo' => $net, 'portale' => $calc['net'], 'lordo' => $gross]);
        }
        if ((float) $r['non_tracciato'] > 0.005) {
            $this->issue('prelievo_importo_non_tracciato', 'warning', $subject, $r['nome'], "{$r['non_tracciato']} € del prelievo di {$gross} € non sono coperti da settlement: valutare un saldo di apertura.", ['tracciato' => $r['tracciato'], 'non_tracciato' => $r['non_tracciato']]);
        }

        $requestedAt = $this->utc($r['richiesta']) ?? CarbonImmutable::now();
        $paid = $r['stato'] !== 'Rifiutato' && ($r['stato'] === 'Liquidato' || $r['fattura_stato'] === 'Pagata');
        $status = match (true) {
            $r['stato'] === 'Rifiutato' => WithdrawalRequest::REJECTED,
            $paid => WithdrawalRequest::PAID,
            $r['stato'] === 'Approvato' => WithdrawalRequest::APPROVED,
            default => WithdrawalRequest::REQUESTED,
        };
        if ($paid && $r['stato'] === 'Approvato') {
            $this->issue('prelievo_stato_da_fattura', 'info', $subject, $r['nome'], 'In Odoo è «Approvato» ma l’autofattura risulta pagata: importato come pagato.', $r);
        }
        $paidAt = $paid ? ($this->utc($r['liquidazione']) ?? $this->utc($r['fattura_data']) ?? $requestedAt) : null;

        $payout = null;
        if ($paid) {
            $period = Period::where('code', $paidAt->setTimezone(self::TZ)->format('Y-m'))->first();
            if ($period === null) {
                $this->issue('prelievo_periodo_mancante', 'warning', $subject, $r['nome'], 'Periodo '.$paidAt->setTimezone(self::TZ)->format('Y-m').' inesistente: importata la richiesta, senza pagamento né ricevuta.', $r);
            } else {
                $payout = Payout::create([
                    'consultant_id' => $consultant->id, 'period_id' => $period->id, 'amount' => $net, 'gross_amount' => $gross, 'withholding_amount' => $withholding,
                    'inps_amount' => '0.00', 'fee_amount' => $fee, 'fiscal_year' => (int) $paidAt->setTimezone(self::TZ)->format('Y'),
                    'iban' => $consultant->iban, 'iban_holder' => $consultant->iban_holder, 'status' => Payout::PAID, 'method' => 'bonifico', 'reference' => 'odoo',
                    'paid_at' => $paidAt, 'prepared_by' => $r['revisionato_da'] ?: 'odoo', 'paid_by' => $r['revisionato_da'] ?: 'odoo', 'source' => 'odoo', 'external_ref' => $key,
                ]);
                $this->counts['pagamenti_creati']++;
                if ($r['autofattura'] !== '' && ! CommissionReceipt::where('number', $r['autofattura'])->exists()) {
                    $issued = $this->utc($r['fattura_data']) ?? $paidAt;
                    $deadline = $issued->setTimezone(self::TZ)->addDays(10)->endOfDay()->setTimezone(config('app.timezone'));
                    CommissionReceipt::create([
                        'number' => $r['autofattura'], 'consultant_id' => $consultant->id, 'payout_id' => $payout->id, 'regime' => $consultant->fiscal_regime ?? 'occasional',
                        'issued_at' => $issued, 'gross' => $gross, 'taxable_base' => $calc['taxable_base'], 'withholding' => $withholding, 'inps_base' => '0.00',
                        'inps_total' => '0.00', 'inps_worker' => '0.00', 'fee' => $fee, 'net' => $net, 'params' => FiscalCalculator::ODOO_PARAMS,
                        'dispute_deadline_at' => $deadline, 'status' => 'accepted', 'accepted_at' => $deadline, 'source' => 'odoo',
                    ]);
                    $this->counts['ricevute_create']++;
                }
            }
        }

        WithdrawalRequest::create([
            'consultant_id' => $consultant->id, 'gross' => $gross, 'withholding' => $withholding, 'inps' => '0.00', 'fee' => $fee, 'net' => $net,
            'iban' => (string) $consultant->iban, 'iban_holder' => $consultant->iban_holder, 'status' => $status, 'requested_at' => $requestedAt,
            'due_by' => $requestedAt->setTimezone(self::TZ)->addWeekdays(5)->format('Y-m-d'), 'reviewed_by' => $r['revisionato_da'] ?: null,
            'paid_at' => $paidAt, 'payout_id' => $payout?->id, 'rejection_reason' => $status === WithdrawalRequest::REJECTED ? 'Rifiutato in Odoo' : null,
            'source' => 'odoo', 'external_ref' => $key,
        ]);
        $this->counts['prelievi_creati']++;
        if ($status === WithdrawalRequest::REJECTED) {
            $this->counts['prelievi_rifiutati']++;
        } elseif (! $paid) {
            $this->counts['prelievi_aperti']++;
        }
    }

    private function utc(string $v): ?CarbonImmutable
    {
        return $v === '' ? null : CarbonImmutable::parse($v, self::TZ)->utc();
    }

    /** @param array<string,mixed> $data */
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
                DB::table('import_reconciliations')->where($key)->update($row);
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
