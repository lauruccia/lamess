<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Models\Payout;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use DOMDocument;

/**
 * File SEPA di bonifici multipli (ISO 20022 pain.001.001.03) da caricare nell'home banking.
 * I pagamenti con IBAN mancante o non valido vengono saltati e restituiti in «skipped».
 */
final class SepaExport
{
    /**
     * @param  iterable<Payout>  $payouts
     * @param  array{name: string, iban: string, bic?: string}  $debtor
     * @return array{xml: string, count: int, total: string, skipped: list<string>}
     */
    public function build(iterable $payouts, array $debtor, DateTimeInterface $now, ?DateTimeInterface $execution = null): array
    {
        $name = self::text($debtor['name'] ?? '', 70);
        $iban = self::normalizeIban($debtor['iban'] ?? '');
        if ($name === '' || ! self::validIban($iban)) {
            throw new PayoutException('Imposta in Impostazioni → Bonifici SEPA l\'intestatario e un IBAN valido del conto da cui si paga.');
        }
        $bic = strtoupper(trim($debtor['bic'] ?? ''));

        $rows = [];
        $skipped = [];
        $total = BigDecimal::zero();
        foreach ($payouts as $p) {
            $cIban = self::normalizeIban((string) $p->iban);
            if (! self::validIban($cIban)) {
                $skipped[] = "{$p->consultant->code} ({$p->consultant->name}): IBAN mancante o non valido";

                continue;
            }
            $amount = number_format((float) $p->amount, 2, '.', '');
            if ((float) $amount <= 0) {
                continue;
            }
            $rows[] = ['payout' => $p, 'iban' => $cIban, 'amount' => $amount];
            $total = $total->plus($amount);
        }
        $total = (string) $total->toScale(2);
        if ($rows === []) {
            throw new PayoutException('Nessun pagamento esportabile'.($skipped ? ': '.implode('; ', $skipped) : '.'));
        }

        $d = new DOMDocument('1.0', 'UTF-8');
        $d->formatOutput = true;
        $doc = $d->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', 'Document');
        $d->appendChild($doc);
        $root = $this->el($d, $doc, 'CstmrCdtTrfInitn');

        $msgId = 'LAMESS'.$now->format('YmdHis');
        $grp = $this->el($d, $root, 'GrpHdr');
        $this->el($d, $grp, 'MsgId', $msgId);
        $this->el($d, $grp, 'CreDtTm', $now->format('Y-m-d\TH:i:s'));
        $this->el($d, $grp, 'NbOfTxs', (string) count($rows));
        $this->el($d, $grp, 'CtrlSum', $total);
        $this->el($d, $this->el($d, $grp, 'InitgPty'), 'Nm', $name);

        $pmt = $this->el($d, $root, 'PmtInf');
        $this->el($d, $pmt, 'PmtInfId', $msgId.'-1');
        $this->el($d, $pmt, 'PmtMtd', 'TRF');
        $this->el($d, $pmt, 'BtchBookg', 'true');
        $this->el($d, $pmt, 'NbOfTxs', (string) count($rows));
        $this->el($d, $pmt, 'CtrlSum', $total);
        $this->el($d, $this->el($d, $this->el($d, $pmt, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
        $this->el($d, $pmt, 'ReqdExctnDt', ($execution ?? $now)->format('Y-m-d'));
        $this->el($d, $this->el($d, $pmt, 'Dbtr'), 'Nm', $name);
        $this->el($d, $this->el($d, $this->el($d, $pmt, 'DbtrAcct'), 'Id'), 'IBAN', $iban);
        $agt = $this->el($d, $this->el($d, $pmt, 'DbtrAgt'), 'FinInstnId');
        if ($bic !== '') {
            $this->el($d, $agt, 'BIC', $bic);
        } else {
            $this->el($d, $this->el($d, $agt, 'Othr'), 'Id', 'NOTPROVIDED');
        }
        $this->el($d, $pmt, 'ChrgBr', 'SLEV');

        foreach ($rows as $r) {
            /** @var Payout $p */
            $p = $r['payout'];
            $tx = $this->el($d, $pmt, 'CdtTrfTxInf');
            $pid = $this->el($d, $tx, 'PmtId');
            $this->el($d, $pid, 'InstrId', 'P'.$p->id);
            $this->el($d, $pid, 'EndToEndId', 'LAMESS-P'.$p->id);
            $this->el($d, $this->el($d, $tx, 'Amt'), 'InstdAmt', $r['amount'])->setAttribute('Ccy', 'EUR');
            $this->el($d, $this->el($d, $tx, 'Cdtr'), 'Nm', self::text($p->iban_holder ?: $p->consultant->name, 70));
            $this->el($d, $this->el($d, $this->el($d, $tx, 'CdtrAcct'), 'Id'), 'IBAN', $r['iban']);
            $this->el($d, $this->el($d, $tx, 'RmtInf'), 'Ustrd', self::text("Provvigioni Lamess {$p->period->code}", 140));
        }

        return ['xml' => (string) $d->saveXML(), 'count' => count($rows), 'total' => $total, 'skipped' => $skipped];
    }

    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function validIban(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $moved = substr($iban, 4).substr($iban, 0, 4);
        $num = '';
        foreach (str_split($moved) as $c) {
            $num .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }
        $rem = 0;
        foreach (str_split($num, 7) as $chunk) {
            $rem = (int) ($rem.$chunk) % 97;
        }

        return $rem === 1;
    }

    /** Solo caratteri ammessi dal tracciato SEPA (lettere latine non accentate, cifre, segni comuni). */
    public static function text(string $s, int $max): string
    {
        $s = trim($s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = $t === false ? $s : $t;
        $s = (string) preg_replace('/[^A-Za-z0-9 \/\-?:().,\'+]/', '', $s);

        return trim(mb_substr($s, 0, $max));
    }

    private function el(DOMDocument $d, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $e = $d->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', $name);
        if ($value !== null) {
            $e->appendChild($d->createTextNode($value));
        }
        $parent->appendChild($e);

        return $e;
    }
}
