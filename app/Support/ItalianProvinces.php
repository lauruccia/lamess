<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Provincia: nel database c'è la sigla (max 3 caratteri). Chi scrive il nome intero («Macerata») o «Macerata (MC)»
 * viene riportato alla sigla; per l'Italia ciò che non si riconosce è un errore chiaro, mai un errore del database.
 */
final class ItalianProvinces
{
    /** @var array<string, string> nome normalizzato => sigla */
    private const NAMES = [
        'agrigento' => 'AG', 'alessandria' => 'AL', 'ancona' => 'AN', 'aosta' => 'AO', 'valle d aosta' => 'AO', 'ascoli piceno' => 'AP', 'l aquila' => 'AQ', 'aquila' => 'AQ',
        'arezzo' => 'AR', 'asti' => 'AT', 'avellino' => 'AV', 'bari' => 'BA', 'bergamo' => 'BG', 'biella' => 'BI', 'belluno' => 'BL', 'benevento' => 'BN',
        'bologna' => 'BO', 'brindisi' => 'BR', 'brescia' => 'BS', 'barletta andria trani' => 'BT', 'bolzano' => 'BZ', 'cagliari' => 'CA', 'campobasso' => 'CB',
        'caserta' => 'CE', 'chieti' => 'CH', 'caltanissetta' => 'CL', 'cuneo' => 'CN', 'como' => 'CO', 'cremona' => 'CR', 'cosenza' => 'CS', 'catania' => 'CT',
        'catanzaro' => 'CZ', 'enna' => 'EN', 'forli cesena' => 'FC', 'forli' => 'FC', 'cesena' => 'FC', 'ferrara' => 'FE', 'foggia' => 'FG', 'firenze' => 'FI', 'fermo' => 'FM',
        'frosinone' => 'FR', 'genova' => 'GE', 'gorizia' => 'GO', 'grosseto' => 'GR', 'imperia' => 'IM', 'isernia' => 'IS', 'crotone' => 'KR', 'lecco' => 'LC',
        'lecce' => 'LE', 'livorno' => 'LI', 'lodi' => 'LO', 'latina' => 'LT', 'lucca' => 'LU', 'monza e brianza' => 'MB', 'monza' => 'MB', 'monza e della brianza' => 'MB',
        'macerata' => 'MC', 'messina' => 'ME', 'milano' => 'MI', 'mantova' => 'MN', 'modena' => 'MO', 'massa carrara' => 'MS', 'matera' => 'MT', 'napoli' => 'NA',
        'novara' => 'NO', 'nuoro' => 'NU', 'oristano' => 'OR', 'palermo' => 'PA', 'piacenza' => 'PC', 'padova' => 'PD', 'pescara' => 'PE', 'perugia' => 'PG',
        'pisa' => 'PI', 'pordenone' => 'PN', 'prato' => 'PO', 'parma' => 'PR', 'pistoia' => 'PT', 'pesaro e urbino' => 'PU', 'pesaro' => 'PU', 'pavia' => 'PV',
        'potenza' => 'PZ', 'ravenna' => 'RA', 'reggio calabria' => 'RC', 'reggio emilia' => 'RE', 'reggio nell emilia' => 'RE', 'ragusa' => 'RG', 'rieti' => 'RI',
        'roma' => 'RM', 'rimini' => 'RN', 'rovigo' => 'RO', 'salerno' => 'SA', 'siena' => 'SI', 'sondrio' => 'SO', 'la spezia' => 'SP', 'siracusa' => 'SR',
        'sassari' => 'SS', 'sud sardegna' => 'SU', 'savona' => 'SV', 'taranto' => 'TA', 'teramo' => 'TE', 'trento' => 'TN', 'torino' => 'TO', 'trapani' => 'TP',
        'terni' => 'TR', 'trieste' => 'TS', 'treviso' => 'TV', 'udine' => 'UD', 'varese' => 'VA', 'verbano cusio ossola' => 'VB', 'vercelli' => 'VC', 'venezia' => 'VE',
        'vicenza' => 'VI', 'verona' => 'VR', 'viterbo' => 'VT', 'vibo valentia' => 'VV',
    ];

    /** Sigla pronta per il database, oppure null se non si riconosce (o, fuori dall'Italia, se è troppo lunga). */
    public static function normalize(?string $input, ?string $country = 'IT'): ?string
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return null;
        }
        if (strtoupper((string) ($country ?: 'IT')) !== 'IT') {
            return mb_strlen($raw) <= 3 ? mb_strtoupper($raw) : null;
        }
        if (preg_match('/\(\s*([A-Za-z]{2})\s*\)\s*$/', $raw, $m) === 1 || preg_match('/^([A-Za-z]{2})$/', $raw, $m) === 1) {
            return strtoupper($m[1]);
        }
        $name = mb_strtolower($raw);
        $name = strtr($name, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        $name = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z ]+/', ' ', (string) preg_replace('/^(citta metropolitana|provincia) di /', '', trim($name)))));

        return self::NAMES[$name] ?? null;
    }

    /**
     * Sistema il campo provincia di una richiesta prima della validazione: sigla se si riconosce; per l'Italia un errore chiaro,
     * per l'estero (dove la provincia è facoltativa) un nome troppo lungo viene lasciato vuoto.
     */
    public static function applyTo(Request $r, string $field = 'province', string $countryField = 'country'): void
    {
        if (trim((string) $r->input($field, '')) === '') {
            return;
        }
        $country = (string) ($r->input($countryField) ?: 'IT');
        $sigla = self::normalize((string) $r->input($field), $country);
        if ($sigla === null && strtoupper($country) === 'IT') {
            throw ValidationException::withMessages([$field => 'Indica la provincia con la sigla di due lettere (per esempio MC per Macerata).']);
        }
        $r->merge([$field => $sigla]);
    }
}
