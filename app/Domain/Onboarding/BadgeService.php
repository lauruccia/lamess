<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Consultant;
use App\Models\IvdContract;
use Barryvdh\DomPDF\Facade\Pdf;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/** Tesserino di riconoscimento in PDF (RF-016): foto, codice, validità annuale. */
final class BadgeService
{
    /**
     * Il tesserino si stampa solo per chi ha un incarico in essere: attivo, non sospeso né cessato,
     * e senza una Lettera IVD ancora in attesa di firma o di accettazione.
     * Gli incaricati importati, senza lettera in archivio, restano stampabili.
     */
    public function isIssuable(Consultant $c): bool
    {
        if ($c->status !== 'active') {
            return false;
        }
        $contract = $this->latestContract($c);

        return $contract === null || ! in_array($contract->status, ['awaiting_signature', 'signed'], true);
    }

    /** PDF del tesserino sempre aggiornato (nome, codice, validità, foto), pronto da stampare: non tocca il database. */
    public function render(Consultant $c): string
    {
        $contract = $this->latestContract($c);
        $photo = $c->kycDocuments()->where('type', 'photo')->where('status', '!=', 'rejected')->latest('id')->first();
        $dataUri = null;
        if ($photo !== null && Storage::exists($photo->path)) {
            $mime = Storage::mimeType($photo->path) ?: 'image/jpeg';
            $dataUri = 'data:'.$mime.';base64,'.base64_encode(Storage::get($photo->path));
        }

        return Pdf::loadView('pdf.badge', [
            'consultant' => $c, 'validUntil' => $this->validUntil($c), 'photo' => $dataUri, 'sponsorName' => $contract?->sponsor?->name,
            'company' => config('lamess.company'), 'qr' => $this->qrDataUri($c),
            'issuedAt' => CarbonImmutable::instance($contract?->accepted_at ?? $contract?->starts_at ?? now())->setTimezone('Europe/Rome'),
        ])->setPaper([0, 0, 243, 153], 'portrait')->output(); // carta di credito 85,6 × 54 mm: le misure sono già in orizzontale, 'landscape' le invertirebbe
    }

    /** Risposta HTTP con il PDF aperto nel browser, così si stampa subito. */
    public function response(Consultant $c): Response
    {
        return response($this->render($c), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="tesserino-'.$c->code.'.pdf"',
        ]);
    }

    public function generate(Consultant $c): string
    {
        $path = "badges/{$c->id}.pdf";
        Storage::put($path, $this->render($c));
        $c->update(['badge_path' => $path, 'badge_number' => $c->code, 'badge_valid_until' => $this->validUntil($c)->format('Y-m-d')]);

        return $path;
    }

    /** Indirizzo pubblico di verifica: firmato, così i codici (progressivi) non si possono provare a tentativi. */
    public function verifyUrl(Consultant $c): string
    {
        return url('/verifica-tesserino/'.$c->code.'/'.$this->signature($c->code));
    }

    public function hasValidSignature(string $code, string $signature): bool
    {
        return hash_equals($this->signature($code), $signature);
    }

    /** Data di fine validità se il tesserino è valido oggi (incarico attivo e non scaduto), altrimenti null. */
    public function validity(Consultant $c): ?CarbonImmutable
    {
        $until = $this->validUntil($c);

        return $this->isIssuable($c) && $until->endOfDay()->isFuture() ? $until : null;
    }

    private function signature(string $code): string
    {
        return substr(hash_hmac('sha256', 'badge:'.$code, (string) config('app.key')), 0, 20);
    }

    private function qrDataUri(Consultant $c): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(300, 1), new SvgImageBackEnd)))->writeString($this->verifyUrl($c));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function latestContract(Consultant $c): ?IvdContract
    {
        return IvdContract::where('consultant_id', $c->id)->latest('id')->first();
    }

    private function validUntil(Consultant $c): CarbonImmutable
    {
        return CarbonImmutable::instance($this->latestContract($c)?->expires_at ?? now()->addYear())->setTimezone('Europe/Rome');
    }
}
