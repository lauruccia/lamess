<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\IvdLetterSignature;
use App\Models\IvdLetterVersion;
use App\Models\User;
use App\Notifications\IvdLetterToSign;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Firma di una versione della Lettera di Incarico da parte di chi è già incaricato.
 * L'amministratore preme «Invia da firmare»: ogni incaricato registrato che non ha ancora firmato la versione in vigore
 * riceve una richiesta (email + avviso nell'area). Il suo contratto IVD originale (durata, canone, tesserino) non viene toccato.
 */
final class IvdLetterSigning
{
    public function __construct(private readonly SignatureProvider $signature, private readonly IvdLetterTemplate $template) {}

    /**
     * Incaricati registrati che dovrebbero firmare la versione e non l'hanno ancora firmata né hanno una richiesta aperta.
     *
     * @return Collection<int, Consultant>
     */
    public function eligible(IvdLetterVersion $v): Collection
    {
        $alreadyAsked = IvdLetterSignature::where('ivd_letter_version_id', $v->id)->whereIn('status', ['pending', 'signed'])->pluck('consultant_id');
        $signedByContract = IvdContract::whereIn('status', ['signed', 'accepted'])->where('template_version', $v->version)->whereNotNull('consultant_id')->pluck('consultant_id');
        $openContract = IvdContract::where('status', 'awaiting_signature')->whereNotNull('consultant_id')->pluck('consultant_id'); // firmerà già la versione in vigore

        return Consultant::query()->with('user')->whereNotNull('user_id')->where('status', '!=', 'terminated')
            ->whereNotIn('id', $alreadyAsked->merge($signedByContract)->merge($openContract)->unique()->all())
            ->orderBy('name')->get();
    }

    /**
     * Crea le richieste di firma e avvisa gli incaricati.
     *
     * @param  list<int>|null  $consultantIds  null = tutti quelli idonei
     * @return array{created:int, emailed:int}
     */
    public function send(IvdLetterVersion $v, string $actor, ?array $consultantIds = null): array
    {
        if (! $v->is_current) {
            throw new OnboardingException('Si può inviare da firmare solo la versione in vigore.');
        }
        $targets = $this->eligible($v);
        if ($consultantIds !== null) {
            $targets = $targets->whereIn('id', $consultantIds);
        }
        $created = $emailed = 0;
        foreach ($targets as $c) {
            $sig = IvdLetterSignature::updateOrCreate(
                ['ivd_letter_version_id' => $v->id, 'consultant_id' => $c->id],
                ['user_id' => $c->user_id, 'status' => 'pending', 'sent_by' => $actor, 'sent_at' => now(), 'last_reminder_at' => null, 'signature_ref' => null],
            );
            $created++;
            $emailed += $this->notify($sig, false) ? 1 : 0;
        }
        if ($created > 0) {
            AuditLog::record('ivd_letter.sent', "Lettera di incarico v{$v->version} inviata da firmare a {$created} incaricati", $v, ['incaricati' => $created, 'email' => $emailed]);
        }

        return ['created' => $created, 'emailed' => $emailed];
    }

    /** Nuovo avviso email a chi ha ancora la firma in sospeso. @return int email inviate */
    public function remind(IvdLetterVersion $v): int
    {
        $n = 0;
        foreach (IvdLetterSignature::with('user')->where('ivd_letter_version_id', $v->id)->where('status', 'pending')->get() as $sig) {
            $n += $this->notify($sig, true) ? 1 : 0;
        }
        if ($n > 0) {
            AuditLog::record('ivd_letter.reminded', "Promemoria di firma della lettera v{$v->version} a {$n} incaricati", $v);
        }

        return $n;
    }

    /** Annulla una richiesta ancora aperta. */
    public function cancel(IvdLetterSignature $sig): void
    {
        if ($sig->status === 'pending') {
            $sig->update(['status' => 'cancelled']);
        }
    }

    /** La richiesta aperta di questo utente, se c'è, per la versione in vigore. */
    public function pendingFor(User $user): ?IvdLetterSignature
    {
        $current = $this->template->current();
        if ($current === null) {
            return null;
        }

        return IvdLetterSignature::with('version')->where('user_id', $user->id)->where('ivd_letter_version_id', $current->id)->where('status', 'pending')->latest('id')->first();
    }

    /**
     * Caselle da spuntare prima della firma (tutte obbligatorie).
     *
     * @return array<string,array{label:string,detail:?string}>
     */
    public function items(IvdLetterVersion $v): array
    {
        $reg = app(RegolamentoDocument::class);

        return [
            'letter' => ['label' => "Dichiaro di aver letto ed accettato la LETTERA DI INCARICO IVD, versione {$v->version}.", 'detail' => null],
            'letter_1341' => ['label' => 'Dichiaro di approvare espressamente, ai sensi e per gli effetti degli Artt. 1341 e 1342 del codice civile, i seguenti articoli della LETTERA DI INCARICO KADEO:', 'detail' => $this->template->articles1341($v)],
            'regolamento' => ['label' => 'Dichiaro di aver letto ed accettato i «PRINCIPI GENERALI E REGOLAMENTO» (Allegato 1), versione '.$reg->version().'.', 'detail' => null],
            'regolamento_1341' => ['label' => 'Dichiaro di approvare espressamente, ai sensi e per gli effetti degli Artt. 1341 e 1342 del codice civile, i seguenti articoli dei «PRINCIPI GENERALI E REGOLAMENTO»:', 'detail' => $reg->articles1341()],
        ];
    }

    public function requestCode(IvdLetterSignature $sig): SignatureRequest
    {
        $this->assertPending($sig);
        $req = $this->signature->request($sig);
        $sig->update(['signature_provider' => $this->signature->name(), 'signature_ref' => $req->reference]);

        return $req;
    }

    /**
     * @param  array<string,mixed>  $declarations  dichiarazioni dell'art. 12
     * @param  array<string,bool>  $accepted  caselle spuntate
     */
    public function sign(IvdLetterSignature $sig, string $otp, ?string $ip, ?string $userAgent, array $declarations, array $accepted): IvdLetterSignature
    {
        $this->assertPending($sig);
        foreach (array_keys($this->items($sig->version)) as $key) {
            if (empty($accepted[$key])) {
                throw new OnboardingException('Per firmare devi spuntare tutte le accettazioni.');
            }
        }
        if ($sig->signature_ref === null || ! $this->signature->verify($sig->signature_ref, $otp)) {
            throw new OnboardingException('Codice OTP non valido o scaduto.');
        }

        return DB::transaction(function () use ($sig, $ip, $userAgent, $declarations, $accepted): IvdLetterSignature {
            $now = new DateTimeImmutable;
            $v = $sig->version;
            $user = $sig->user()->with('profile')->firstOrFail();
            $profile = $user->profile;
            $consultant = $sig->consultant;
            $pdf = Pdf::loadView('pdf.ivd-letter-version', [
                'sig' => $sig, 'version' => $v, 'profile' => $profile, 'consultant' => $consultant, 'signedAt' => $now,
                'decl' => $declarations, 'accepted' => $accepted, 'items' => $this->items($v), 'html' => $this->template->render($declarations, null, null, $v),
                'regolamentoHtml' => app(RegolamentoDocument::class)->html(), 'regolamentoVersion' => app(RegolamentoDocument::class)->version(),
            ])->output();
            $path = "contracts/letter-{$sig->id}.pdf";
            Storage::put($path, $pdf);
            $hash = hash('sha256', $pdf);
            $sig->update([
                'status' => 'signed', 'signed_at' => $now, 'pdf_path' => $path, 'pdf_hash' => $hash,
                'evidence' => [
                    'provider' => $sig->signature_provider, 'reference' => $sig->signature_ref, 'ip' => $ip, 'user_agent' => $userAgent, 'signed_at' => $now->format(DATE_ATOM),
                    'document_sha256' => $hash, 'signer_email' => $user->email, 'version' => $v->version, 'revision' => $v->revision,
                    'declarations' => $declarations, 'accepted' => $accepted,
                    'regolamento_version' => app(RegolamentoDocument::class)->version(), 'regolamento_sha256' => app(RegolamentoDocument::class)->hash(),
                ],
            ]);
            AuditLog::record('ivd_letter.signed', "Lettera di incarico v{$v->version} firmata da {$consultant->code}", $sig);

            return $sig->refresh();
        });
    }

    /**
     * @return array{signed:int,pending:int,eligible:int}
     */
    public function counts(IvdLetterVersion $v): array
    {
        $by = IvdLetterSignature::where('ivd_letter_version_id', $v->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['signed' => (int) ($by['signed'] ?? 0), 'pending' => (int) ($by['pending'] ?? 0), 'eligible' => $this->eligible($v)->count()];
    }

    private function notify(IvdLetterSignature $sig, bool $reminder): bool
    {
        $sig->update($reminder ? ['last_reminder_at' => now()] : ['last_reminder_at' => null]);
        if (! config('lamess.mail_enabled') || blank($sig->user?->email)) {
            return false;
        }
        $sig->user->notify(new IvdLetterToSign($sig->version, $reminder));

        return true;
    }

    private function assertPending(IvdLetterSignature $sig): void
    {
        if ($sig->refresh()->status !== 'pending' || ! $sig->version->is_current) {
            throw new OnboardingException('Questa richiesta di firma non è più valida.');
        }
    }
}
