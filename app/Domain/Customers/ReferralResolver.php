<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Consultant;

/**
 * Assegnazione del FAN a un incaricato (RF-002), nell'ordine: link referral → cookie → codice inserito nel form
 * → incaricato "casa" configurabile (OPEN-20) → nessuno.
 */
final class ReferralResolver
{
    /** @return array{0:?Consultant,1:string} [incaricato, fonte: referral|cookie|form_code|default|manual] */
    public function resolve(?string $link, ?string $cookie, ?string $form): array
    {
        foreach ([['referral', $link], ['cookie', $cookie], ['form_code', $form]] as [$source, $code]) {
            $c = $this->find($code);
            if ($c !== null) {
                return [$c, $source];
            }
        }
        $default = $this->find(config('lamess.default_consultant_code'));

        return $default !== null ? [$default, 'default'] : [null, 'manual'];
    }

    /** Cerca per codice referral o codice attività (senza distinguere maiuscole); solo incaricati che possono sponsorizzare. */
    public function find(?string $code): ?Consultant
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        $upper = mb_strtoupper($code);

        return Consultant::query()
            ->where(fn ($q) => $q->where('referral_code', $upper)->orWhere('code', $upper))
            ->whereIn('status', (array) config('lamess.sponsoring_statuses'))
            ->first();
    }
}
