<?php

declare(strict_types=1);

namespace App\Domain\Customers;

/**
 * Controllo di un indirizzo di consegna scritto in un campo solo («Via Roma 5»): serve la via e il numero civico.
 * Nasce da un'etichetta stampata con il solo «Piano 1»: piano, scala e interno da soli non bastano a consegnare.
 */
final class AddressCheck
{
    /** Piano, scala, interno e simili: dettagli dopo la via, che da soli non indicano dove consegnare. */
    private const DETAIL = '/\b(?:piano|scala|interno|int|sc|palazzina|pal)\b\.?\s*[\w°ºª\/-]*/iu';

    /** Numero civico assente per davvero: «snc», «s.n.c.», «sn», «senza numero». */
    private const NO_NUMBER = '/\b(?:s\.?\s?n\.?\s?c\.?|s\.?\s?n\.?|senza\s+numero|km\s*\d+)\b/iu';

    /** True se l'indirizzo ha una via (o contrada, piazza…) e un numero civico, oppure «snc». */
    public static function isComplete(?string $address): bool
    {
        return self::problem($address) === null;
    }

    /** Il motivo per cui l'indirizzo non va bene, scritto per chi lo sta compilando; null se è completo. */
    public static function problem(?string $address): ?string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return 'Scrivi l\'indirizzo: via e numero civico.';
        }
        $street = trim((string) preg_replace(self::DETAIL, ' ', $address), " ,.;-\t\n");
        if (preg_match('/\p{L}{2,}/u', $street) !== 1) {
            return 'Manca la via: «'.$address.'» indica solo piano, scala o interno. Scrivi via e numero civico (es. «Via Roma 5, piano 1»).';
        }
        if (preg_match('/\d/u', $street) !== 1 && preg_match(self::NO_NUMBER, $street) !== 1) {
            return 'Manca il numero civico: aggiungilo (es. «Via Roma 5»). Se la strada non ha numero scrivi «snc».';
        }

        return null;
    }
}
