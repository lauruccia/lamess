<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Engine\Support\Num;
use App\Models\Consultant;
use App\Models\Payout;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Trattenute su un pagamento di provvigioni (ritenuta d'acconto e quota INPS dell'incaricato) e commissione bonifico.
 *
 * VALORI PROVVISORI, tutti configurabili (`lamess.fiscal.*`) e da validare col commercialista: con le trattenute spente
 * (default) il netto è pari al lordo meno l'eventuale commissione bonifico.
 *
 *  - ritenuta = lordo × quota imponibile (78%) × aliquota (23%);
 *  - INPS Gestione Separata: solo sulla parte di provvigioni dell'anno che supera la soglia (5.000 €), base = quota imponibile,
 *    contributo = base × aliquota; l'incaricato ne sopporta la sua quota, il resto resta al Consorzio (informativo);
 *  - incaricati con partita IVA (`vat_holder`): nessuna trattenuta, emettono fattura propria.
 */
final class FiscalCalculator
{
    /** Parametri applicati da Odoo ai prelievi storici: ritenuta 23% sul 78% e nessuna INPS. Servono a riverificare lo storico senza toccare la configurazione. */
    public const ODOO_PARAMS = ['withholding_enabled' => true, 'withholding_pct' => '23', 'taxable_pct' => '78', 'inps_enabled' => false, 'inps_threshold' => '5000', 'inps_rate' => '0', 'inps_worker_share' => '0'];

    /**
     * @return array{gross:string, taxable_base:string, withholding:string, inps_base:string, inps_total:string, inps_worker:string, inps_company:string, fee:string, net:string, regime:string}
     */
    public static function compute(string $regime, BigDecimal|string $gross, BigDecimal|string $ytdGross = '0', BigDecimal|string $fee = '0', ?array $params = null): array
    {
        $p = $params ?? config('lamess.fiscal');
        $gross = Num::money($gross);
        $fee = Num::money($fee);
        $zero = Num::money('0');
        $taxablePct = Num::of((string) $p['taxable_pct'])->dividedBy(100, 12, RoundingMode::HalfUp);

        $taxable = $zero;
        $withholding = $zero;
        $inpsBase = $zero;
        $inpsTotal = $zero;
        $inpsWorker = $zero;

        if ($regime !== 'vat_holder' && $gross->isPositive()) {
            $taxable = Num::money($gross->multipliedBy($taxablePct));
            if ($p['withholding_enabled']) {
                $withholding = Num::money($taxable->multipliedBy(Num::of((string) $p['withholding_pct']))->dividedBy(100, 12, RoundingMode::HalfUp));
            }
            if ($p['inps_enabled']) {
                $thr = Num::of((string) $p['inps_threshold']);
                $ytd = Num::of($ytdGross);
                $excess = Num::max(Num::zero(), $ytd->plus($gross)->minus($thr))->minus(Num::max(Num::zero(), $ytd->minus($thr)));
                $inpsBase = Num::money($excess->multipliedBy($taxablePct));
                $inpsTotal = Num::money($inpsBase->multipliedBy(Num::of((string) $p['inps_rate']))->dividedBy(100, 12, RoundingMode::HalfUp));
                $inpsWorker = Num::money($inpsTotal->multipliedBy(Num::of((string) $p['inps_worker_share']))->dividedBy(100, 12, RoundingMode::HalfUp));
            }
        }
        $net = Num::money($gross->minus($withholding)->minus($inpsWorker)->minus($fee));

        return [
            'gross' => (string) $gross, 'taxable_base' => (string) $taxable, 'withholding' => (string) $withholding, 'inps_base' => (string) $inpsBase,
            'inps_total' => (string) $inpsTotal, 'inps_worker' => (string) $inpsWorker, 'inps_company' => (string) Num::money($inpsTotal->minus($inpsWorker)),
            'fee' => (string) $fee, 'net' => (string) $net, 'regime' => $regime,
        ];
    }

    /** Provvigioni lorde già pagate o in pagamento nell'anno (base per la soglia INPS). */
    public static function ytdGross(Consultant $c, int $year, ?int $excludePayoutId = null): BigDecimal
    {
        $sum = BigDecimal::zero();
        $q = Payout::where('consultant_id', $c->id)->whereIn('status', [Payout::TO_PAY, Payout::PAID])->where('fiscal_year', $year);
        if ($excludePayoutId !== null) {
            $q->where('id', '!=', $excludePayoutId);
        }
        foreach ($q->get(['gross_amount', 'amount']) as $p) {
            $sum = $sum->plus((string) ($p->gross_amount ?? $p->amount));
        }

        return $sum;
    }

    /** Parametri in vigore, per conservarli sulla ricevuta. @return array<string, mixed> */
    public static function params(): array
    {
        return config('lamess.fiscal');
    }
}
