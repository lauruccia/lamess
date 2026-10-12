<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\IdentityVerification;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Notifications\KycDocumentsRequested;
use App\Notifications\KycReviewed;
use Illuminate\Http\UploadedFile;

final class KycService
{
    public function __construct(private readonly OnboardingService $onboarding, private readonly BadgeService $badges) {}

    public function upload(Consultant $c, string $type, UploadedFile $file): KycDocument
    {
        if (! array_key_exists($type, (array) config('lamess.kyc_document_types'))) {
            throw new OnboardingException('Tipo di documento non valido.');
        }
        $path = $file->store("kyc/{$c->id}");
        $doc = KycDocument::create([
            'consultant_id' => $c->id, 'type' => $type, 'path' => $path, 'original_name' => $file->getClientOriginalName(),
        ]);
        $this->onboarding->recompute($c);
        $this->fulfilIfComplete($c);

        return $doc->refresh();
    }

    /** Richiesta documenti ancora aperta per l'incaricato, se c'è. */
    public function openRequest(Consultant $c): ?KycRequest
    {
        return KycRequest::where('consultant_id', $c->id)->whereNull('fulfilled_at')->whereNull('canceled_at')->latest('id')->first();
    }

    /** Documenti da chiedere di default: quelli obbligatori non ancora approvati (o rifiutati). @return list<string> */
    public function missingTypes(Consultant $c): array
    {
        $docs = $this->onboarding->latestDocuments($c);
        $online = IdentityVerification::where('consultant_id', $c->id)->where('status', 'verified')->exists();

        return array_values(array_filter(
            (array) config('lamess.kyc_required_documents'),
            fn ($t) => ! (($docs[$t] ?? null)?->status === 'approved' || ($t === 'id_document' && $online)),
        ));
    }

    /**
     * L'admin chiede all'incaricato di mandare i documenti. Una nuova richiesta sostituisce quella aperta.
     *
     * @param  list<string>  $types
     */
    public function request(Consultant $c, array $types, string $by, ?string $message = null, ?int $dueDays = null): KycRequest
    {
        $valid = array_keys((array) config('lamess.kyc_document_types'));
        $types = array_values(array_unique(array_filter($types, fn ($t) => in_array($t, $valid, true))));
        if ($types === []) {
            throw new OnboardingException('Scegli almeno un documento da richiedere.');
        }
        if ($c->status === 'terminated') {
            throw new OnboardingException('L’incarico è cessato: non si possono richiedere documenti.');
        }
        KycRequest::where('consultant_id', $c->id)->whereNull('fulfilled_at')->whereNull('canceled_at')->update(['canceled_at' => now()]);
        $dueDays ??= (int) config('lamess.kyc_request_due_days', 7);
        $req = KycRequest::create([
            'consultant_id' => $c->id, 'types' => $types, 'message' => $message, 'requested_by' => $by,
            'due_at' => $dueDays > 0 ? now()->addDays($dueDays) : null,
        ]);
        $c->user?->notify(new KycDocumentsRequested($req));
        AuditLog::record('kyc.requested', "Richiesti documenti KYC a {$c->code}: ".implode(', ', $req->labels()), $c, ['types' => $types, 'message' => $message]);

        return $req;
    }

    public function cancelRequest(KycRequest $req): void
    {
        if ($req->isOpen()) {
            $req->update(['canceled_at' => now()]);
            AuditLog::record('kyc.request_canceled', "Annullata la richiesta di documenti KYC a {$req->consultant->code}", $req->consultant);
        }
    }

    /** Chiude la richiesta quando per ogni documento chiesto c'è un caricamento valido (in verifica o approvato). */
    public function fulfilIfComplete(Consultant $c): void
    {
        $req = $this->openRequest($c);
        if ($req === null) {
            return;
        }
        $docs = $this->onboarding->latestDocuments($c);
        $online = IdentityVerification::where('consultant_id', $c->id)->where('status', 'verified')->exists();
        foreach ((array) $req->types as $t) {
            $d = $docs[$t] ?? null;
            $ok = ($d !== null && $d->status !== 'rejected' && $d->created_at >= $req->created_at) || ($t === 'id_document' && $online);
            if (! $ok) {
                return;
            }
        }
        $req->update(['fulfilled_at' => now()]);
        AuditLog::record('kyc.request_fulfilled', "{$c->code} ha caricato i documenti richiesti", $c);
    }

    /** Promemoria per le richieste aperte e senza risposta. @return int promemoria inviati */
    public function sendReminders(): int
    {
        $days = (array) config('lamess.kyc_request_reminder_days', [3, 6]);
        $sent = 0;
        foreach (KycRequest::whereNull('fulfilled_at')->whereNull('canceled_at')->with('consultant.user')->get() as $req) {
            $n = (int) $req->reminders_sent;
            if (! isset($days[$n]) || $req->created_at->copy()->addDays((int) $days[$n])->isFuture()) {
                continue;
            }
            $this->fulfilIfComplete($req->consultant);
            if (! $req->refresh()->isOpen() || $req->consultant->status === 'terminated') {
                continue;
            }
            $req->consultant->user?->notify(new KycDocumentsRequested($req, true));
            $req->update(['reminders_sent' => $n + 1, 'last_reminder_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    /** Avvia la verifica online e ne tiene traccia. */
    public function startOnline(Consultant $c, IdentityVerifier $verifier, string $returnUrl): IdentityStart
    {
        if (! $verifier->available()) {
            throw new OnboardingException('La verifica online non è attiva: carica i documenti.');
        }
        $start = $verifier->start($c, $returnUrl);
        IdentityVerification::create(['consultant_id' => $c->id, 'provider' => $verifier->name(), 'ref' => $start->ref]);
        $this->onboarding->recompute($c);

        return $start;
    }

    /** Esito dal provider (webhook). Idempotente; una verifica sostituita o già conclusa non si riapre. */
    public function applyOnlineResult(string $ref, string $status, ?string $reason = null): string
    {
        $v = IdentityVerification::where('ref', $ref)->first();
        if ($v === null) {
            return 'unknown';
        }
        if (in_array($v->status, ['superseded', 'verified'], true) || $v->status === $status) {
            return 'ignored';
        }
        $v->update(['status' => $status, 'failure_reason' => $reason, 'completed_at' => in_array($status, ['verified', 'canceled'], true) ? now() : null]);
        $c = $v->consultant;
        $this->onboarding->recompute($c);
        $this->fulfilIfComplete($c);
        if ($status === 'verified') {
            AuditLog::record('kyc.online_verified', "Identità di {$c->code} verificata online", $c, ['provider' => $v->provider]);
        }

        return $status;
    }

    public function review(KycDocument $doc, bool $approve, string $by, string $notes = ''): KycDocument
    {
        if ($doc->status !== 'pending') {
            throw new OnboardingException('Documento già esaminato.');
        }
        $doc->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $by, 'reviewed_at' => now(), 'notes' => $notes]);
        $c = $doc->consultant;
        $this->onboarding->recompute($c);
        $c->user?->notify(new KycReviewed($doc));
        if ($approve && $doc->type === 'photo' && $c->badge_path !== null) {
            $this->badges->generate($c->refresh()); // il tesserino ora ha la foto
        }

        return $doc->refresh();
    }
}
