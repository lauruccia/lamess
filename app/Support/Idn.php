<?php

declare(strict_types=1);

namespace App\Support;

/** Mostra un indirizzo con il dominio «leggibile» (lamèss.it invece di xn--lamss-6ra.it). Il link vero resta quello ASCII. */
final class Idn
{
    public static function pretty(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! str_contains($parts['host'], 'xn--')) {
            return $url;
        }
        $host = implode('.', array_map(static fn (string $l) => str_starts_with(strtolower($l), 'xn--') ? self::decode(substr($l, 4)) : $l, explode('.', $parts['host'])));

        return str_replace($parts['host'], $host, $url);
    }

    /** Decodifica Punycode (RFC 3492) di una etichetta senza il prefisso «xn--». */
    private static function decode(string $input): string
    {
        $base = 36; $tMin = 1; $tMax = 26; $skew = 38; $damp = 700; $n = 128; $i = 0; $bias = 72;
        $out = [];
        $pos = strrpos($input, '-');
        if ($pos !== false) {
            foreach (str_split(substr($input, 0, $pos)) as $ch) {
                $out[] = ord($ch);
            }
            $pos++;
        } else {
            $pos = 0;
        }
        $len = strlen($input);
        while ($pos < $len) {
            $old = $i;
            for ($w = 1, $k = $base; ; $k += $base) {
                if ($pos >= $len) {
                    return $input;
                }
                $c = ord($input[$pos++]);
                $digit = $c - 48 < 10 ? $c - 22 : ($c - 65 < 26 ? $c - 65 : ($c - 97 < 26 ? $c - 97 : $base));
                if ($digit >= $base) {
                    return $input;
                }
                $i += $digit * $w;
                $t = $k <= $bias ? $tMin : ($k >= $bias + $tMax ? $tMax : $k - $bias);
                if ($digit < $t) {
                    break;
                }
                $w *= $base - $t;
            }
            $count = count($out) + 1;
            $delta = $old === 0 ? intdiv($i - $old, $damp) : intdiv($i - $old, 2);
            $delta += intdiv($delta, $count);
            for ($k = 0; $delta > (($base - $tMin) * $tMax) >> 1; $k += $base) {
                $delta = intdiv($delta, $base - $tMin);
            }
            $bias = $k + intdiv(($base - $tMin + 1) * $delta, $delta + $skew);
            $n += intdiv($i, $count);
            $i %= $count;
            array_splice($out, $i++, 0, [$n]);
        }

        return implode('', array_map(static fn (int $cp) => mb_chr($cp, 'UTF-8'), $out));
    }
}
