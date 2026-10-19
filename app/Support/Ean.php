<?php

declare(strict_types=1);

namespace App\Support;

/** Codici a barre EAN-13 / EAN-8: controllo della cifra di controllo e disegno in SVG (stampabile e scansionabile). */
final class Ean
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Schema L/G della prima metà in base alla prima cifra dell'EAN-13. */
    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    public static function normalize(?string $code): string
    {
        return preg_replace('/\D+/', '', (string) $code) ?? '';
    }

    /** Vero se è un EAN-13 o EAN-8 con cifra di controllo corretta. */
    public static function isValid(?string $code): bool
    {
        $c = self::normalize($code);
        if (! in_array(strlen($c), [8, 13], true)) {
            return false;
        }

        return (int) $c[-1] === self::checkDigit(substr($c, 0, -1));
    }

    /** Cifra di controllo per i primi 7 o 12 numeri. */
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            // Da destra: la prima cifra del corpo pesa 3, la seconda 1, e così via.
            $sum += (int) $body[$len - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /** Modulo di barre (stringa di 0/1) per l'EAN indicato. */
    private static function modules(string $c): string
    {
        if (strlen($c) === 8) {
            $out = '101';
            for ($i = 0; $i < 4; $i++) {
                $out .= self::L[(int) $c[$i]];
            }
            $out .= '01010';
            for ($i = 4; $i < 8; $i++) {
                $out .= self::R[(int) $c[$i]];
            }

            return $out.'101';
        }
        $parity = self::PARITY[(int) $c[0]];
        $out = '101';
        for ($i = 1; $i <= 6; $i++) {
            $out .= $parity[$i - 1] === 'L' ? self::L[(int) $c[$i]] : self::G[(int) $c[$i]];
        }
        $out .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $out .= self::R[(int) $c[$i]];
        }

        return $out.'101';
    }

    /** SVG del codice a barre con i numeri sotto. Stringa vuota se il codice non è valido. */
    public static function svg(?string $code, int $moduleWidth = 2, int $height = 70): string
    {
        $c = self::normalize($code);
        if (! self::isValid($c)) {
            return '';
        }
        $m = self::modules($c);
        $quiet = 10 * $moduleWidth;
        $w = strlen($m) * $moduleWidth + 2 * $quiet;
        $h = $height + 18;
        $bars = '';
        $len = strlen($m);
        for ($i = 0; $i < $len; $i++) {
            if ($m[$i] === '1') {
                $run = 1;
                while ($i + $run < $len && $m[$i + $run] === '1') {
                    $run++;
                }
                $bars .= '<rect x="'.($quiet + $i * $moduleWidth).'" y="0" width="'.($run * $moduleWidth).'" height="'.$height.'"/>';
                $i += $run - 1;
            }
        }
        $text = '<text x="'.($w / 2).'" y="'.($height + 14).'" text-anchor="middle" font-family="monospace" font-size="14" letter-spacing="2">'.$c.'</text>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'" height="'.$h.'" role="img" aria-label="Codice a barre '.$c.'"><rect width="'.$w.'" height="'.$h.'" fill="#fff"/><g fill="#000">'.$bars.'</g>'.$text.'</svg>';
    }
}
