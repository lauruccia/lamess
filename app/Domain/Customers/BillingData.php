<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Profile;

/**
 * Dati di fatturazione del cliente nel checkout: si inseriscono e si salvano nel profilo prima di confermare l'ordine;
 * l'indirizzo di fatturazione, una volta salvato, non si cambia dal checkout ma solo da «I miei dati».
 */
final class BillingData
{
    /** Campi anagrafici/fiscali che il cliente può correggere nel checkout (e salvare). */
    public const EDITABLE = ['type', 'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec'];

    /** Indirizzo di fatturazione: bloccato nel checkout dopo il primo salvataggio. */
    public const ADDRESS = ['address', 'city', 'zip', 'province'];

    /** L'indirizzo è già salvato nel profilo, quindi nel checkout non si modifica. */
    public static function addressLocked(?Profile $profile): bool
    {
        return $profile !== null && filled($profile->address) && filled($profile->city) && filled($profile->zip);
    }

    /**
     * Cosa manca nel profilo salvato per poter fatturare.
     *
     * @return array<string,string> campo => messaggio
     */
    public static function missing(?Profile $profile): array
    {
        $missing = [];
        $profile ??= new Profile(['type' => 'private']);
        $company = $profile->type === 'company';
        $italy = ($profile->country_code ?: 'IT') === 'IT';
        $need = $company ? ['company_name' => 'ragione sociale', 'vat_number' => 'partita IVA'] : ['first_name' => 'nome', 'last_name' => 'cognome'];
        $need += ['address' => 'indirizzo di fatturazione', 'city' => 'città', 'zip' => 'CAP'];
        if (! $company && $italy) {
            $need['tax_code'] = 'codice fiscale';
        }
        foreach ($need as $field => $label) {
            if (blank($profile->{$field})) {
                $missing[$field] = "Il campo «{$label}» è obbligatorio.";
            }
        }
        if ($company && $italy && blank($profile->sdi_code) && blank($profile->pec)) {
            $missing['sdi_code'] = 'Per le aziende italiane serve il codice SDI o la PEC.';
        }

        return $missing;
    }

    /**
     * I dati inviati dal modulo coincidono con quelli salvati? (L'indirizzo non conta: si prende sempre quello salvato.)
     *
     * @param  array<string,mixed>  $posted
     */
    public static function matchesSaved(?Profile $profile, array $posted): bool
    {
        if ($profile === null) {
            return false;
        }
        foreach (self::EDITABLE as $field) {
            $saved = trim((string) ($profile->{$field} ?? ($field === 'type' ? 'private' : '')));
            if ($saved !== trim((string) ($posted[$field] ?? ($field === 'type' ? 'private' : '')))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indirizzo e nazione salvati, da usare al posto di quanto arriva dal modulo.
     *
     * @return array{address:?string,city:?string,zip:?string,province:?string,country:string}
     */
    public static function lockedAddress(Profile $profile): array
    {
        return [
            'address' => $profile->address, 'city' => $profile->city, 'zip' => $profile->zip, 'province' => $profile->province,
            'country' => (string) ($profile->country_code ?: 'IT'),
        ];
    }
}
