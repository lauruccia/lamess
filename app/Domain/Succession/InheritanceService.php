<?php

declare(strict_types=1);

namespace App\Domain\Succession;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Domain\Onboarding\Iban;
use App\Domain\Payouts\PayoutService;
use App\Domain\Wallet\WalletService;
use App\Models\Consultant;
use App\Models\InheritanceCase;
use App\Models\InheritanceDocument;
use App\Models\InheritanceHeir;
use App\Models\IvdContract;
use App\Models\Payout;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Decesso di un incaricato (Reg. 9.2).
 *  1. segnalazione: la Lettera IVD cessa subito, il codice resta «sospeso» e i pagamenti sono bloccati;
 *  2. documentazione: certificato di morte + testamento o provvedimento dell'Autorità, verificati dal Consorzio, ed elenco degli eredi con le quote;
 *  3. liquidazione: i compensi maturati si congelano e si dividono tra gli eredi (pagamento a mano, come i bonifici agli incaricati);
 *  4. destino del codice: un erede subentra firmando una nuova Lettera IVD, oppure l'account cessa (con i diretti ricollegati allo sponsor).
 * Senza decisione entro `lamess.inheritance_limbo_days` il codice cessa da solo, così la rete non resta appesa.
 */
final class InheritanceService
{
    public function __construct(
        private readonly NetworkService $network, private readonly AccountHolderService $holders, private readonly PayoutService $payouts, private readonly WalletService $wallet,
    ) {}

    public function report(Consultant $c, DateTimeInterface $deathDate, string $note = ''): InheritanceCase
    {
        if ($c->status === 'terminated') {
            throw new SuccessionException('L’incaricato risulta già cessato.');
        }
        if (InheritanceCase::where('consultant_id', $c->id)->where('status', '!=', 'closed')->exists()) {
            throw new SuccessionException('Esiste già una pratica di successione aperta per questo codice.');
        }
        if ($deathDate > now()) {
            throw new SuccessionException('La data del decesso non può essere nel futuro.');
        }

        return DB::transaction(function () use ($c, $deathDate, $note): InheritanceCase {
            $by = Actor::label();
            $case = InheritanceCase::create(['consultant_id' => $c->id, 'death_date' => $deathDate, 'reported_by' => $by, 'reported_at' => now(), 'note' => $note ?: null]);
            $case->update(['number' => 'SU-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT)]);
            IvdContract::where('consultant_id', $c->id)->whereIn('status', ['awaiting_signature', 'signed', 'accepted'])->update(['status' => 'ceased']);
            $c->update(['bonus_hold' => true]);
            if ($c->status === 'active') {
                $this->network->changeStatus($c, 'suspended', 'Decesso dell’incaricato', null, $by);
            }
            AuditLog::record('inheritance.reported', "Decesso segnalato per {$c->code} ({$case->number})", $case, ['data' => $deathDate->format('Y-m-d')], $note ?: null);

            return $case;
        });
    }

    public function addDocument(InheritanceCase $case, string $type, UploadedFile|string $file, ?string $name = null): InheritanceDocument
    {
        $this->assertNotClosed($case);
        if (! array_key_exists($type, InheritanceCase::DOCUMENT_TYPES)) {
            throw new SuccessionException('Tipo di documento non valido.');
        }
        $path = $file instanceof UploadedFile ? $file->store("inheritance/{$case->id}") : $file; // già salvato (caricamento Filament)
        if (! Storage::exists($path)) {
            throw new SuccessionException('File non trovato.');
        }
        $doc = InheritanceDocument::create([
            'inheritance_case_id' => $case->id, 'type' => $type, 'path' => $path,
            'original_name' => $file instanceof UploadedFile ? $file->getClientOriginalName() : ($name ?: basename($path)), 'uploaded_by' => Actor::label(),
        ]);
        AuditLog::record('inheritance.document', "Documento «{$type}» caricato per {$case->number}", $case);

        return $doc;
    }

    public function reviewDocument(InheritanceDocument $d, bool $approve, string $notes = ''): void
    {
        if ($d->status !== 'pending') {
            throw new SuccessionException('Documento già esaminato.');
        }
        if (! $approve && mb_strlen(trim($notes)) < 5) {
            throw new SuccessionException('Indica perché il documento non è valido.');
        }
        $d->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => Actor::label(), 'reviewed_at' => now(), 'notes' => $notes ?: null]);
        AuditLog::record('inheritance.document_reviewed', 'Documento '.($approve ? 'approvato' : 'rifiutato').": {$d->original_name}", $d->inheritanceCase, [], $notes ?: null);
    }

    /**
     * Sostituisce l'elenco degli eredi. Le quote sono in percentuale e devono dare 100.
     *
     * @param  list<array{full_name:string,tax_code:string,share_percent:string|float,iban?:?string,iban_holder?:?string}>  $heirs
     */
    public function setHeirs(InheritanceCase $case, array $heirs): void
    {
        if (in_array($case->status, ['liquidated', 'closed'], true)) {
            throw new SuccessionException('I compensi sono già stati liquidati: l\'elenco degli eredi non si cambia più.');
        }
        if ($heirs === []) {
            throw new SuccessionException('Indica almeno un erede.');
        }
        $sum = Num::zero();
        foreach ($heirs as $h) {
            if (trim((string) ($h['full_name'] ?? '')) === '' || strlen(trim((string) ($h['tax_code'] ?? ''))) !== 16) {
                throw new SuccessionException('Ogni erede ha bisogno di nome e codice fiscale (16 caratteri).');
            }
            if (! empty($h['iban']) && ! Iban::isValid((string) $h['iban'])) {
                throw new SuccessionException("IBAN non valido per {$h['full_name']}.");
            }
            $share = Num::of((string) $h['share_percent']);
            if ($share->isLessThanOrEqualTo(Num::zero())) {
                throw new SuccessionException('Le quote devono essere maggiori di zero.');
            }
            $sum = $sum->plus($share);
        }
        if (! $sum->isEqualTo(Num::of('100'))) {
            throw new SuccessionException("Le quote devono dare 100% (ora {$sum}%).");
        }
        DB::transaction(function () use ($case, $heirs): void {
            $case->heirs()->delete();
            foreach ($heirs as $h) {
                InheritanceHeir::create([
                    'inheritance_case_id' => $case->id, 'full_name' => trim($h['full_name']), 'tax_code' => mb_strtoupper(trim($h['tax_code'])), 'share_percent' => (string) $h['share_percent'],
                    'iban' => ! empty($h['iban']) ? Iban::normalize($h['iban']) : null, 'iban_holder' => $h['iban_holder'] ?? null,
                ]);
            }
            if ($case->status === 'verified') {
                $case->update(['status' => 'reported', 'verified_by' => null, 'verified_at' => null]); // eredi cambiati: la verifica va rifatta
            }
            AuditLog::record('inheritance.heirs', 'Eredi definiti per '.$case->number.': '.count($heirs), $case);
        });
    }

    /** Verifica la pratica: certificato di morte e testamento/provvedimento approvati, eredi definiti. */
    public function verify(InheritanceCase $case): void
    {
        $this->assertNotClosed($case);
        if ($case->status !== 'reported') {
            throw new SuccessionException('La pratica è già stata verificata.');
        }
        $ok = fn (string $t) => $case->documents()->where('type', $t)->where('status', 'approved')->exists();
        if (! $ok('death_certificate')) {
            throw new SuccessionException('Manca il certificato di morte approvato.');
        }
        if (! $ok('will') && ! $ok('court_order')) {
            throw new SuccessionException('Serve il testamento (copia autentica) o il provvedimento dell\'Autorità giudiziaria, approvato.');
        }
        if ($case->heirs()->count() === 0) {
            throw new SuccessionException('Indica gli eredi e le quote.');
        }
        $case->update(['status' => 'verified', 'verified_by' => Actor::label(), 'verified_at' => now()]);
        AuditLog::record('inheritance.verified', "Pratica {$case->number} verificata", $case);
    }

    /** Importo che sarebbe liquidato agli eredi oggi. */
    public function estate(Consultant $c): string
    {
        return (string) Num::money($this->payouts->openBalance($c->id)->plus($this->wallet->balance($c->id)));
    }

    /** Congela i compensi maturati e li ripartisce tra gli eredi. */
    public function liquidate(InheritanceCase $case): InheritanceCase
    {
        if ($case->status !== 'verified') {
            throw new SuccessionException('La pratica va prima verificata.');
        }
        $c = $case->consultant()->firstOrFail();
        if (Payout::where('consultant_id', $c->id)->where('status', Payout::TO_PAY)->exists()) {
            throw new SuccessionException('Ci sono pagamenti «da pagare» intestati al defunto: annullali o segnali come pagati prima di liquidare agli eredi.');
        }
        if (WithdrawalRequest::where('consultant_id', $c->id)->whereIn('status', [WithdrawalRequest::REQUESTED, WithdrawalRequest::APPROVED])->exists()) {
            throw new SuccessionException('Ci sono richieste di prelievo del defunto ancora aperte: respingile o chiudile prima.');
        }

        return DB::transaction(function () use ($case, $c): InheritanceCase {
            $open = Settlement::where('consultant_id', $c->id)->where('status', 'open')->whereNull('payout_id');
            $accrued = $this->payouts->openBalance($c->id);
            $wallet = $this->wallet->balance($c->id);
            $estate = Num::money($accrued->plus($wallet));
            $open->update(['status' => 'inherited']);
            if ($wallet->isGreaterThan(Num::zero())) {
                $this->wallet->post($c, 'heir_liquidation', $wallet->negated(), "Liquidazione agli eredi ({$case->number})");
            }
            $heirs = $case->heirs()->orderBy('id')->get();
            if ($estate->isGreaterThan(Num::zero())) {
                $left = $estate;
                $biggest = $heirs->sortByDesc('share_percent')->first();
                foreach ($heirs as $h) {
                    $amt = Num::money($estate->multipliedBy(Num::of((string) $h->share_percent))->dividedBy('100', 2, RoundingMode::Down));
                    $left = $left->minus($amt);
                    $h->update(['amount' => (string) $amt, 'status' => 'to_pay']);
                }
                if ($left->isGreaterThan(Num::zero())) { // i centesimi persi dall'arrotondamento vanno alla quota più grande
                    $biggest->refresh()->update(['amount' => (string) Num::money(Num::of((string) $biggest->amount)->plus($left))]);
                }
            } else {
                $heirs->each->update(['amount' => '0.00', 'status' => 'paid', 'reference' => 'nessun importo', 'paid_at' => now(), 'paid_by' => 'sistema']);
            }
            $case->update(['status' => 'liquidated', 'estate_amount' => (string) $estate, 'liquidated_at' => now()]);
            AuditLog::record('inheritance.liquidated', "Compensi di {$c->code} liquidati agli eredi: {$estate} €", $case, ['importo' => (string) $estate]);
            $this->closeIfDone($case);

            return $case->refresh();
        });
    }

    public function markHeirPaid(InheritanceHeir $h, string $reference, DateTimeInterface $paidAt): void
    {
        if ($h->status !== 'to_pay') {
            throw new SuccessionException('Solo un pagamento «da pagare» si segna come pagato.');
        }
        if (trim($reference) === '') {
            throw new SuccessionException('Indica il riferimento del bonifico.');
        }
        if (blank($h->iban)) {
            throw new SuccessionException("Manca l'IBAN di {$h->full_name}: aggiungilo prima di pagare.");
        }
        DB::transaction(function () use ($h, $reference, $paidAt): void {
            $h->update(['status' => 'paid', 'reference' => $reference, 'paid_at' => $paidAt, 'paid_by' => Actor::label()]);
            AuditLog::record('inheritance.paid', "Pagato a {$h->full_name}: {$h->amount} €", $h->inheritanceCase, ['riferimento' => $reference]);
            $this->closeIfDone($h->inheritanceCase()->first());
        });
    }

    /** Un erede subentra nel codice: nuova Lettera IVD e KYC a suo nome. */
    public function succeed(InheritanceCase $case, User $heir): void
    {
        $this->assertNotClosed($case);
        if ($case->outcome !== null) {
            throw new SuccessionException('Il destino del codice è già stato deciso.');
        }
        if ($case->status === 'reported') {
            throw new SuccessionException('La pratica va prima verificata.');
        }
        $c = $case->consultant()->firstOrFail();
        DB::transaction(function () use ($case, $c, $heir): void {
            $c->update(['bonus_hold' => false]);
            $this->holders->swap($c, $heir, 'succession', "Successione {$case->number}", Actor::label());
            $case->update(['outcome' => 'succession']);
            AuditLog::record('inheritance.succession', "Il codice {$c->code} passa a un erede ({$case->number})", $case);
            $this->closeIfDone($case);
        });
    }

    /** L'account cessa: i diretti passano allo sponsor (Reg. 2.8). */
    public function endAccount(InheritanceCase $case, string $by = ''): void
    {
        $this->assertNotClosed($case);
        if ($case->outcome !== null) {
            throw new SuccessionException('Il destino del codice è già stato deciso.');
        }
        $c = $case->consultant()->firstOrFail();
        DB::transaction(function () use ($case, $c, $by): void {
            try {
                $this->network->terminate($c, 'Decesso dell’incaricato', $case->death_date, $by ?: Actor::label());
            } catch (NetworkException $e) {
                throw new SuccessionException($e->getMessage(), 0, $e);
            }
            $case->update(['outcome' => 'termination']);
            AuditLog::record('inheritance.terminated', "Account {$c->code} cessato per decesso ({$case->number})", $case);
            $this->closeIfDone($case);
        });
    }

    /** Comando giornaliero: chiude le pratiche rimaste senza decisione oltre il limite. @return int pratiche chiuse */
    public function endStale(): int
    {
        $n = 0;
        $limit = now()->subDays((int) config('lamess.inheritance_limbo_days'));
        foreach (InheritanceCase::whereNull('outcome')->where('status', '!=', 'closed')->where('reported_at', '<=', $limit)->get() as $case) {
            $this->endAccount($case, 'sistema');
            $n++;
        }

        return $n;
    }

    private function closeIfDone(InheritanceCase $case): void
    {
        $case->refresh();
        if ($case->status === 'liquidated' && $case->outcome !== null && ! $case->heirs()->where('status', 'to_pay')->exists()) {
            $case->update(['status' => 'closed', 'closed_at' => now()]);
            AuditLog::record('inheritance.closed', "Pratica {$case->number} chiusa", $case);
        }
    }

    private function assertNotClosed(InheritanceCase $case): void
    {
        if ($case->status === 'closed') {
            throw new SuccessionException('La pratica è chiusa.');
        }
    }
}
