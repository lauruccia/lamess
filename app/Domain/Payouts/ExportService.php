<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Plan\LegacyPlan;
use App\Models\Payout;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/** Esportazioni CSV per la contabilità (separatore «;», UTF-8 con BOM: si aprono direttamente in Excel). */
final class ExportService
{
    /** Tutte le righe di compenso bloccate di un periodo chiuso. */
    public function commissions(Period $period): string
    {
        if (! $period->isClosed() || $period->official_run_id === null) {
            throw new PayoutException("L'esportazione di {$period->code} è disponibile dopo la chiusura del mese.");
        }
        $rows = DB::table('commission_lines as l')
            ->join('consultants as b', 'b.id', '=', 'l.beneficiary_id')
            ->leftJoin('consultants as s', 's.id', '=', 'l.source_consultant_id')
            ->where('l.run_id', $period->official_run_id)->where('l.status', 'locked')
            ->orderBy('b.code')->orderBy('l.bonus_type')->orderBy('l.id')
            ->get(['b.code as bcode', 'b.name as bname', 'l.bonus_type', 'l.engine_status', 'l.base_amount', 'l.pct', 'l.amount', 'l.level', 's.code as scode', 'l.reason']);

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $period->code, $r->bcode, $r->bname, LegacyPlan::bonusLabel((string) $r->bonus_type, StatementService::LABELS[$r->bonus_type] ?? $r->bonus_type),
                ['accrued' => 'maturato', 'held' => 'trattenuto', 'excluded' => 'escluso'][$r->engine_status] ?? $r->engine_status,
                $this->num($r->base_amount), $r->pct === null ? '' : $this->num((string) ((float) $r->pct * 100)), $this->num($r->amount),
                $r->level ?? '', $r->scode ?? '', LegacyPlan::hidden() && LegacyPlan::isLegacyBonus((string) $r->bonus_type) ? '' : $r->reason,
            ];
        }

        return $this->csv(['Periodo', 'Codice incaricato', 'Incaricato', 'Tipo', 'Stato', 'Base €', 'Aliquota %', 'Importo €', 'Livello', 'Origine', 'Nota'], $data);
    }

    /** Elenco dei bonifici da fare. @param iterable<Payout> $payouts */
    public function payouts(iterable $payouts): string
    {
        $data = [];
        foreach ($payouts as $p) {
            $data[] = [
                $p->period->code, $p->consultant->code, $p->iban_holder ?: $p->consultant->name, $p->iban, $this->num((string) $p->amount),
                "Provvigioni Lamess {$p->period->code}", ['to_pay' => 'da pagare', 'paid' => 'pagato', 'cancelled' => 'annullato'][$p->status] ?? $p->status, $p->reference ?? '',
                $this->num($p->gross()), $this->num((string) $p->withholding_amount), $this->num((string) $p->inps_amount), $this->num((string) $p->fee_amount),
            ];
        }

        return $this->csv(['Periodo', 'Codice incaricato', 'Intestatario', 'IBAN', 'Importo €', 'Causale', 'Stato', 'Riferimento', 'Lordo €', 'Ritenuta €', 'INPS incaricato €', 'Commissione €'], $data);
    }

    private function num(string|int|float $v): string
    {
        return number_format((float) $v, 2, ',', '');
    }

    /** @param list<string> $header @param list<list<mixed>> $rows */
    private function csv(array $header, array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $header, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ';', '"', '');
        }
        rewind($h);

        return "\xEF\xBB\xBF".stream_get_contents($h);
    }
}
