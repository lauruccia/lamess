<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Audit\AuditLog;
use App\Domain\Customers\CustomerService;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Passaggio cliente ⇄ incaricato deciso a mano dall'amministrazione.
 *
 * - «Declassa»: chi non ha una qualifica è solo cliente (regola dei dati Odoo). L'incaricato sparisce dall'elenco e dall'albero:
 *   la riga viene eliminata, i suoi diretti salgono al suo sponsor, i suoi clienti passano allo sponsor e la persona resta cliente.
 *   Si rifiuta se esistono già movimenti, commissioni, prelievi o altro storico economico: in quel caso si usa «Cessa».
 * - «Promuovi»: un cliente diventa incaricato attivo, con codice e referral nuovi, da subito nell'albero. Lettera e KYC si completano dopo.
 */
final class ConsultantConversion
{
    /** Tabelle con storico che non si cancella: [tabella => colonne che puntano all'incaricato]. */
    private const HISTORY = [
        'pv_movements' => ['consultant_id'], 'commission_lines' => ['beneficiary_id', 'source_consultant_id'],
        'consultant_period_stats' => ['consultant_id'], 'rank_achievements' => ['consultant_id'], 'rank_awards' => ['consultant_id'],
        'settlements' => ['consultant_id'], 'payouts' => ['consultant_id'], 'withdrawal_requests' => ['consultant_id'],
        'commission_receipts' => ['consultant_id'], 'commission_statements' => ['consultant_id'], 'wallet_transactions' => ['consultant_id'],
        'structure_bonuses' => ['beneficiary_id', 'source_consultant_id'], 'orders' => ['beneficiary_consultant_id'],
        'order_items' => ['beneficiary_consultant_id'], 'annual_fees' => ['consultant_id'], 'kyc_documents' => ['consultant_id'],
        'consultant_notes' => ['consultant_id'], 'sanctions' => ['consultant_id'], 'compliance_cases' => ['consultant_id'],
        'account_transfers' => ['consultant_id'], 'inheritance_cases' => ['consultant_id'], 'sponsor_history' => ['consultant_id'],
        'identity_verifications' => ['consultant_id'], 'kyc_requests' => ['consultant_id'],
    ];

    public function __construct(private readonly NetworkService $network, private readonly CustomerService $customers) {}

    /** Tabelle in cui l'incaricato ha già storico (vuoto = si può declassare). @return list<string> */
    public function history(Consultant $c): array
    {
        $found = [];
        foreach (self::HISTORY as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $c->id)->exists()) {
                    $found[] = $table;
                    break;
                }
            }
        }

        return $found;
    }

    /** Elimina l'incaricato senza storico e lo lascia cliente. Restituisce il cliente (id utente). */
    public function demoteToCustomer(Consultant $c, string $reason): int
    {
        $history = $this->history($c);
        if ($history !== []) {
            throw new NetworkException("«{$c->name}» ha già storico economico (".implode(', ', $history).'): non si declassa, usa «Cessa».');
        }

        return DB::transaction(function () use ($c, $reason): int {
            $user = $c->user_id !== null ? User::find($c->user_id) : null;
            $sponsor = $c->sponsor_id !== null ? Consultant::find($c->sponsor_id) : null;
            $code = $c->code;
            $name = $c->name;

            Consultant::$sponsorChangeAllowed = true;
            try {
                Consultant::where('sponsor_id', $c->id)->update(['sponsor_id' => $sponsor?->id]);
                Consultant::where('original_sponsor_id', $c->id)->update(['original_sponsor_id' => $sponsor?->id]);
            } finally {
                Consultant::$sponsorChangeAllowed = false;
            }
            DB::table('customers')->where('assigned_consultant_id', $c->id)->update(['assigned_consultant_id' => $sponsor?->id]);

            // la lettera firmata resta come prova, ma non è più legata a un incaricato
            IvdContract::where('consultant_id', $c->id)->update(['consultant_id' => null, 'status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => 'Declassato a cliente: '.$reason]);
            IvdContract::where('sponsor_id', $c->id)->update(['sponsor_id' => $sponsor?->id]);
            DB::table('consultant_status_changes')->where('consultant_id', $c->id)->delete();
            DB::table('network_paths')->where('ancestor_id', $c->id)->orWhere('descendant_id', $c->id)->orWhere('leg_root_id', $c->id)->delete();
            $c->delete();
            $this->network->rebuildClosure();

            $userId = 0;
            if ($user !== null) {
                $user->removeRole('consultant');
                if (! $user->hasRole('customer')) {
                    $user->assignRole('customer');
                }
                $this->customers->ensureCustomer($user, $sponsor);
                $userId = $user->id;
            }
            AuditLog::record('consultant.demoted', "Incaricato declassato a cliente: {$code} {$name}", $user, ['codice' => $code, 'sponsor' => $sponsor?->code], $reason !== '' ? $reason : null);

            return $userId;
        });
    }

    /** Il cliente diventa incaricato attivo. Senza sponsor esplicito usa l'incaricato a cui è assegnato. */
    public function promoteToConsultant(User $user, ?Consultant $sponsor, string $reason): Consultant
    {
        if (Consultant::where('user_id', $user->id)->where('status', '!=', 'terminated')->exists()) {
            throw new NetworkException('Questa persona è già un incaricato.');
        }
        $profile = $user->profile;
        if ($profile !== null && $profile->type === 'company') {
            throw new NetworkException('Solo le persone fisiche possono diventare incaricati.');
        }
        $sponsor ??= $user->customer?->consultant;
        $taxCode = $profile !== null && filled($profile->tax_code) ? mb_strtoupper((string) $profile->tax_code) : null;

        return DB::transaction(function () use ($user, $sponsor, $reason, $taxCode, $profile): Consultant {
            $consultant = $this->network->enroll([
                'code' => $this->nextCode(), 'referral_code' => $this->newReferralCode(),
                'name' => $profile !== null ? ($profile->fullName() ?: $user->name) : $user->name,
                'person_key' => $taxCode, 'user_id' => $user->id, 'onboarding_complete' => false, 'kyc_status' => 'none',
            ], $sponsor, new DateTimeImmutable);
            if (! $user->hasRole('consultant')) {
                $user->assignRole('consultant');
            }
            $user->customer?->update(['assigned_consultant_id' => $sponsor?->id]);
            AuditLog::record('consultant.promoted', "Cliente promosso a incaricato: {$consultant->code} {$consultant->name}", $consultant, ['sponsor' => $sponsor?->code], $reason !== '' ? $reason : null);

            return $consultant;
        });
    }

    private function nextCode(): string
    {
        $prefix = (string) config('lamess.consultant_code_prefix');
        $max = 0;
        foreach (Consultant::where('code', 'like', $prefix.'%')->pluck('code') as $code) {
            $max = max($max, (int) substr((string) $code, strlen($prefix)));
        }

        return $prefix.str_pad((string) ($max + 1), (int) config('lamess.consultant_code_width'), '0', STR_PAD_LEFT);
    }

    private function newReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Consultant::where('referral_code', $code)->exists());

        return $code;
    }
}
