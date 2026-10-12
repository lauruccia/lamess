<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLog;
use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StatementService;
use App\Mail\ConsultantDocumentsMail;
use App\Models\CommissionReceipt;
use App\Models\CommissionStatement;
use App\Models\Consultant;
use App\Models\IvdContract;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Documenti che l'amministrazione può inviare all'incaricato: lettera firmata, certificato di firma, tesserino,
 * estratti conto e ricevute. Ogni documento ha una chiave (`letter`, `badge`, `statement:12`, `receipt:7`…).
 */
final class ConsultantDocuments
{
    public const MAX_TOTAL_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly BadgeService $badges, private readonly StatementService $statements, private readonly ReceiptService $receipts,
        private readonly SignatureCertificate $certificates,
    ) {}

    /** Documenti inviabili ora per l'incaricato. @return array<string, string> chiave => etichetta */
    public function options(Consultant $c): array
    {
        $out = [];
        $contract = $this->contract($c);
        if ($contract?->pdf_path !== null && Storage::exists($contract->pdf_path)) {
            $out['letter'] = 'Lettera di incarico firmata';
        }
        if ($contract?->signed_at !== null) {
            $out['certificate'] = 'Certificato di completamento della firma';
        }
        if ($this->badges->isIssuable($c)) {
            $out['badge'] = 'Tesserino di riconoscimento';
        }
        foreach (CommissionStatement::where('consultant_id', $c->id)->with('period')->latest('id')->limit(12)->get() as $s) {
            $out["statement:{$s->id}"] = 'Estratto conto '.($s->period->code ?? $s->period_id);
        }
        foreach (CommissionReceipt::where('consultant_id', $c->id)->latest('id')->limit(12)->get() as $r) {
            $out["receipt:{$r->id}"] = "Ricevuta {$r->number}";
        }

        return $out;
    }

    /** @return array{filename:string, mime:string, data:string}|null null se il documento non esiste per questo incaricato */
    public function build(Consultant $c, string $key): ?array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);
        switch ($kind) {
            case 'letter':
                $contract = $this->contract($c);
                if ($contract?->pdf_path === null || ! Storage::exists($contract->pdf_path)) {
                    return null;
                }

                return ['filename' => "lettera-incarico-{$c->code}.pdf", 'mime' => 'application/pdf', 'data' => Storage::get($contract->pdf_path)];
            case 'certificate':
                $contract = $this->contract($c);

                return $contract?->signed_at === null ? null : ['filename' => "certificato-firma-{$c->code}.pdf", 'mime' => 'application/pdf', 'data' => $this->certificates->pdf($contract)];
            case 'badge':
                return $this->badges->isIssuable($c) ? ['filename' => "tesserino-{$c->code}.pdf", 'mime' => 'application/pdf', 'data' => $this->badges->render($c)] : null;
            case 'statement':
                $s = CommissionStatement::where('consultant_id', $c->id)->with('period')->find($id);

                return $s === null ? null : ['filename' => 'estratto-conto-'.($s->period->code ?? $s->period_id)."-{$c->code}.pdf", 'mime' => 'application/pdf', 'data' => $this->statements->pdf($s->period, $c)];
            case 'receipt':
                $r = CommissionReceipt::where('consultant_id', $c->id)->find($id);

                return $r === null ? null : ['filename' => 'ricevuta-'.str_replace('/', '-', (string) $r->number).'.pdf', 'mime' => 'application/pdf', 'data' => $this->receipts->pdf($r)];
        }

        return null;
    }

    /**
     * Invia per email i documenti scelti. Chi non ha email o non ha nessuno dei documenti riceve un errore chiaro;
     * i documenti che mancano a quell'incaricato sono saltati e restituiti in `skipped`.
     *
     * @param  list<string>  $keys
     * @return array{sent: list<string>, skipped: list<string>}
     */
    public function send(Consultant $c, array $keys, string $by, ?string $note = null): array
    {
        $email = $c->user?->email;
        if (blank($email)) {
            throw new OnboardingException('L’incaricato non ha un indirizzo email.');
        }
        $labels = $this->options($c);
        $files = [];
        $sent = [];
        $skipped = [];
        $total = 0;
        foreach (array_unique($keys) as $key) {
            $f = $this->build($c, $key);
            if ($f === null) {
                $skipped[] = $labels[$key] ?? $key;

                continue;
            }
            $total += strlen($f['data']);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new OnboardingException('Gli allegati superano 15 MB: invia meno documenti per volta.');
            }
            $files[] = $f;
            $sent[] = $labels[$key] ?? $key;
        }
        if ($files === []) {
            throw new OnboardingException('Nessuno dei documenti scelti è disponibile per questo incaricato.');
        }
        Mail::to($email)->send(new ConsultantDocumentsMail($c->name, $sent, $files, $note));
        AuditLog::record('documents.sent', "Inviati a {$c->code} per email: ".implode(', ', $sent), $c, ['documents' => $sent, 'skipped' => $skipped, 'by' => $by, 'note' => $note]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function contract(Consultant $c): ?IvdContract
    {
        return IvdContract::where('consultant_id', $c->id)->latest('id')->first();
    }
}
