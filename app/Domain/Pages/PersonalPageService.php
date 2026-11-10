<?php

declare(strict_types=1);

namespace App\Domain\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Features\Feature;
use App\Models\Consultant;
use App\Models\ConsultantPage;
use App\Models\User;
use App\Notifications\PersonalPageActivation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pagina personale pubblica dell'incaricato (/i/{slug}).
 *
 * Il contenuto «in uso» sta nei campi della riga; con l'approvazione attiva le modifiche dell'incaricato vanno in
 * `proposal` e il pubblico continua a vedere la versione precedente finché un admin non approva. Tutto il resto della
 * pagina (prodotto, FAQ, avvertenze) è del Consorzio e viene da config/personal-page.php: l'incaricato non lo tocca.
 */
final class PersonalPageService
{
    /** Campi di testo/link modificabili dall'incaricato (la foto è gestita a parte). */
    public const FIELDS = ['display_name', 'headline', 'bio', 'story', 'whatsapp', 'email_public', 'instagram', 'facebook', 'tiktok', 'linkedin', 'media'];

    public const SOCIALS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'];

    public const MEDIA_KINDS = ['video' => 'Video', 'article' => 'Articolo'];

    public const MEDIA_SLOTS = 3;

    private const RESERVED = ['admin', 'area', 'api', 'negozio', 'carrello', 'accedi', 'registrazione', 'privacy', 'termini', 'lamess', 'lames', 'test'];

    public function enabled(): bool
    {
        return (bool) config('lamess.personal_pages.enabled') && Feature::enabled('area.personal_page');
    }

    /** Fase di prova: le pagine sono solo del Consorzio, nessuna è pubblica e gli incaricati non vedono «La mia pagina». */
    public function staffOnly(): bool
    {
        return (bool) config('lamess.personal_pages.staff_only');
    }

    /** Chi può aprire «La mia pagina» in area: con la fase di prova solo chi ha accesso al back-office. */
    public function areaAvailable(?User $user): bool
    {
        return $this->enabled() && (! $this->staffOnly() || ($user !== null && $user->can('compliance.view')));
    }

    public function approvalRequired(): bool
    {
        return (bool) config('lamess.personal_pages.approval');
    }

    /** Solo un incaricato attivo e con account non sospeso ha una pagina. */
    public function eligible(Consultant $c): bool
    {
        return $c->status === 'active' && ($c->user_id === null || ! User::query()->whereKey($c->user_id)->whereNotNull('suspended_at')->exists());
    }

    /** La pagina dell'incaricato se esiste, senza crearla: aprire «La mia pagina» non deve far nascere nulla. */
    public function find(Consultant $c): ?ConsultantPage
    {
        return ConsultantPage::where('consultant_id', $c->id)->first();
    }

    /** La pagina dell'incaricato; se non esiste la crea in bozza con un indirizzo ricavato dal nome. */
    public function forConsultant(Consultant $c): ConsultantPage
    {
        $page = ConsultantPage::where('consultant_id', $c->id)->first();
        if ($page !== null) {
            return $page;
        }
        $name = $this->defaultName($c);

        return ConsultantPage::create([
            'consultant_id' => $c->id, 'slug' => $this->uniqueSlug($name !== '' ? $name : (string) $c->code),
            'display_name' => $name, 'headline' => 'Incaricato Lamèss', 'whatsapp' => null,
        ]);
    }

    public function defaultName(Consultant $c): string
    {
        $profile = $c->user?->profile;

        return trim((string) ($profile?->fullName() ?: $c->name));
    }

    // ------------------------------------------------------------ attivazione (su richiesta, a pagamento, annuale)

    public function activationRequired(): bool
    {
        return (bool) config('lamess.personal_pages.activation_required');
    }

    /** Costo dell'attivazione, in euro con due decimali (stringa: mai float per gli importi). */
    public function price(): string
    {
        return (string) Num::money((string) config('lamess.personal_pages.price', '0'));
    }

    public function reminderDays(): int
    {
        return max(1, (int) config('lamess.personal_pages.reminder_days', 30));
    }

    /** La pagina può essere usata (modificata, messa online)? Senza obbligo di attivazione, sempre sì. */
    public function isActivated(ConsultantPage $p): bool
    {
        if (! $this->activationRequired()) {
            return true;
        }

        return $p->activation === 'active' && ($p->activation_expires_at === null || $p->activation_expires_at->isFuture());
    }

    public function activationRequested(ConsultantPage $p): bool
    {
        return $p->activation_requested_at !== null;
    }

    /** Giorni alla scadenza (0 = scade oggi); null se attiva senza scadenza o non attiva. */
    public function daysLeft(ConsultantPage $p): ?int
    {
        if ($p->activation !== 'active' || $p->activation_expires_at === null || ! $p->activation_expires_at->isFuture()) {
            return null;
        }

        return (int) floor(now()->diffInDays($p->activation_expires_at, false));
    }

    /** Attiva ma vicina alla scadenza: l'incaricato può già chiedere il rinnovo. */
    public function renewalDue(ConsultantPage $p): bool
    {
        $left = $this->daysLeft($p);

        return $left !== null && $left <= $this->reminderDays();
    }

    /** Stato per l'elenco del Consorzio e per l'incaricato: none | requested | active | expiring | expired. */
    public function activationState(ConsultantPage $p): string
    {
        $expired = $p->activation === 'active' && $p->activation_expires_at !== null && ! $p->activation_expires_at->isFuture();
        if ($p->activation === 'active' && ! $expired) {
            return $this->renewalDue($p) ? ($this->activationRequested($p) ? 'requested' : 'expiring') : 'active';
        }

        return $this->activationRequested($p) ? 'requested' : ($expired ? 'expired' : 'none');
    }

    /** L'incaricato chiede l'attivazione (o il rinnovo) dopo aver pagato su kmoney.it. */
    public function requestActivation(ConsultantPage $p, ?string $kmoneyRef = null): ConsultantPage
    {
        if (! $this->activationRequired()) {
            throw new PageException('La pagina è già disponibile: non serve attivarla.');
        }
        if ($this->isActivated($p) && ! $this->renewalDue($p)) {
            throw new PageException('La pagina è già attiva.');
        }
        if ($this->activationRequested($p)) {
            throw new PageException('La richiesta è già stata inviata: il Consorzio la sta controllando.');
        }
        $renewal = $p->activation === 'active';
        $p->forceFill(['activation_requested_at' => now(), 'activation_note' => filled($kmoneyRef) ? 'Pagato da: '.trim((string) $kmoneyRef) : $p->activation_note])->save();
        AuditLog::record('page.activation_requested', "Pagina personale di {$p->consultant->code}: ".($renewal ? 'richiesto il rinnovo' : 'richiesta l\'attivazione'), $p);

        return $p;
    }

    /** Il Consorzio scarta la richiesta (per esempio il pagamento non risulta). */
    public function cancelRequest(ConsultantPage $p, string $note = ''): ConsultantPage
    {
        if (! $this->activationRequested($p)) {
            throw new PageException('Non ci sono richieste da annullare.');
        }
        $p->forceFill(['activation_requested_at' => null, 'activation_note' => trim($note) ?: $p->activation_note])->save();
        AuditLog::record('page.activation_declined', "Pagina personale di {$p->consultant->code}: richiesta di attivazione non accolta", $p, [], trim($note) ?: null);

        return $p;
    }

    /**
     * Attiva o rinnova la pagina. `paid`: pagamento ricevuto su kmoney.it (riferimento in $note); `free`: la regala il Consorzio.
     * Un rinnovo ancora in corso somma i mesi alla scadenza attuale; con $noExpiry (solo gratis) la pagina non scade.
     */
    public function activate(ConsultantPage $p, string $source, ?string $note = null, bool $noExpiry = false): ConsultantPage
    {
        if (! in_array($source, ['paid', 'free'], true)) {
            throw new PageException('Tipo di attivazione non valido.');
        }
        if ($noExpiry && $source !== 'free') {
            throw new PageException('Solo un\'attivazione gratuita può non avere scadenza.');
        }
        $base = $p->activation === 'active' && $p->activation_expires_at !== null && $p->activation_expires_at->isFuture() ? Carbon::instance($p->activation_expires_at) : now();
        $expires = $noExpiry ? null : $base->copy()->addMonthsNoOverflow(max(1, (int) config('lamess.personal_pages.validity_months', 12)));
        $p->forceFill([
            'activation' => 'active', 'activation_source' => $source, 'activation_requested_at' => null, 'activated_at' => now(),
            'activation_expires_at' => $expires, 'activation_reminded_at' => null, 'activation_note' => filled($note) ? trim((string) $note) : null,
        ])->save();
        AuditLog::record('page.activated', "Pagina personale di {$p->consultant->code}: ".($source === 'free' ? 'attivata gratuitamente' : 'attivata dopo il pagamento').($expires ? ' fino al '.$expires->timezone('Europe/Rome')->format('d/m/Y') : ' senza scadenza'), $p, [], filled($note) ? trim((string) $note) : null);
        $p->consultant->user?->notify(new PersonalPageActivation($p->refresh(), 'activated'));

        return $p;
    }

    /** Promemoria di scadenza agli incaricati: uno solo per ogni periodo di attivazione. */
    public function sendExpiryReminders(): int
    {
        if (! $this->activationRequired()) {
            return 0;
        }
        $sent = 0;
        $pages = ConsultantPage::with('consultant.user')->where('activation', 'active')->whereNull('activation_reminded_at')
            ->whereNotNull('activation_expires_at')->where('activation_expires_at', '>', now())->where('activation_expires_at', '<=', now()->addDays($this->reminderDays()))->get();
        foreach ($pages as $p) {
            if ($p->consultant === null || ! $this->eligible($p->consultant) || $p->consultant->user === null) {
                continue;
            }
            $p->consultant->user->notify(new PersonalPageActivation($p, 'expiring'));
            $p->forceFill(['activation_reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /** Visibile al pubblico adesso? */
    public function isPublic(ConsultantPage $p): bool
    {
        if (! $this->enabled() || $this->staffOnly() || ! $p->enabled || $p->blocked || $p->approved_at === null || ! $this->isActivated($p)) {
            return false;
        }
        $c = $p->consultant;

        return $c !== null && $this->eligible($c);
    }

    public function findPublic(string $slug): ?ConsultantPage
    {
        $p = ConsultantPage::with('consultant.user.profile')->where('slug', Str::lower($slug))->first();

        return $p !== null && $this->isPublic($p) ? $p : null;
    }

    /**
     * Pagina personale da cui il cliente è arrivato (ricordata in sessione): solo se è ancora online.
     *
     * @return array{url: string, name: string}|null
     */
    public function backLink(Request $request): ?array
    {
        $slug = $request->session()->get('pp_back');
        if (! is_string($slug) || $slug === '') {
            return null;
        }
        $page = $this->findPublic($slug);
        if ($page === null) {
            $request->session()->forget('pp_back');

            return null;
        }

        return ['url' => '/i/'.$page->slug, 'name' => (string) $this->content($page)['display_name']];
    }

    /**
     * Contenuto da mostrare. In anteprima (l'incaricato o l'admin) la proposta in revisione ha la precedenza sul contenuto in uso.
     *
     * @return array<string, mixed>
     */
    public function content(ConsultantPage $p, bool $preview = false): array
    {
        $live = [];
        foreach ([...self::FIELDS, 'photo_path'] as $f) {
            $live[$f] = $p->{$f};
        }
        $data = $preview && is_array($p->proposal) ? array_replace($live, $p->proposal) : $live;
        $data['media'] = array_values(array_filter((array) ($data['media'] ?? []), fn ($m) => is_array($m) && filled($m['url'] ?? null) && filled($m['title'] ?? null)));

        return $data;
    }

    /**
     * Salva le modifiche dell'incaricato (o dell'admin). Con l'approvazione attiva e senza $direct vanno in revisione.
     *
     * @param  array<string, mixed>  $data  chiavi di FIELDS (già validate)
     */
    public function save(ConsultantPage $p, array $data, ?UploadedFile $photo = null, bool $removePhoto = false, bool $direct = false): ConsultantPage
    {
        $clean = $this->normalize($data);
        $toReview = ! $direct && $this->approvalRequired();

        return DB::transaction(function () use ($p, $clean, $photo, $removePhoto, $toReview): ConsultantPage {
            $current = $this->content($p, true);
            $photoPath = $current['photo_path'];
            $newPhoto = null;
            if ($photo !== null) {
                $newPhoto = $photo->store("pages/{$p->id}");
                $photoPath = $newPhoto;
            } elseif ($removePhoto) {
                $photoPath = null;
            }
            $values = $clean + ['photo_path' => $photoPath];

            if ($toReview) {
                $old = $p->proposal['photo_path'] ?? null;
                $p->forceFill(['proposal' => $values, 'review' => 'pending', 'review_note' => null])->save();
                // la foto proposta in precedenza e mai approvata non serve più
                if ($old !== null && $old !== $photoPath && $old !== $p->photo_path) {
                    Storage::delete($old);
                }
                AuditLog::record('page.submitted', "Pagina personale di {$p->consultant->code}: modifiche inviate in revisione", $p);
            } else {
                $oldPhoto = $p->photo_path;
                $stale = $p->proposal['photo_path'] ?? null;
                if ($stale !== null && $stale !== $photoPath && $stale !== $oldPhoto) {
                    Storage::delete($stale);
                }
                $p->forceFill($values + ['proposal' => null, 'review' => 'none', 'review_note' => null, 'approved_at' => $p->approved_at ?? now()])->save();
                if ($oldPhoto !== null && $oldPhoto !== $photoPath) {
                    Storage::delete($oldPhoto);
                }
                AuditLog::record('page.updated', "Pagina personale di {$p->consultant->code}: contenuti aggiornati", $p);
            }

            return $p->refresh();
        });
    }

    public function setSlug(ConsultantPage $p, string $slug): ConsultantPage
    {
        $slug = Str::lower(trim($slug));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/', $slug) !== 1 || str_contains($slug, '--')) {
            throw new PageException('L\'indirizzo può avere da 3 a 40 caratteri: lettere minuscole, numeri e trattini (non doppi, non all\'inizio né alla fine).');
        }
        if (in_array($slug, self::RESERVED, true) || ConsultantPage::where('slug', $slug)->where('id', '!=', $p->id)->exists()) {
            throw new PageException('Questo indirizzo non è disponibile: scegline un altro.');
        }
        $old = $p->slug;
        if ($old !== $slug) {
            $p->forceFill(['slug' => $slug])->save();
            AuditLog::record('page.slug_changed', "Pagina personale di {$p->consultant->code}: indirizzo cambiato", $p, ['indirizzo' => ['prima' => $old, 'dopo' => $slug]]);
        }

        return $p;
    }

    /** L'incaricato mette online o toglie dal pubblico la propria pagina. */
    public function setOnline(ConsultantPage $p, bool $online): ConsultantPage
    {
        if ($online && $p->blocked) {
            throw new PageException('La pagina è stata nascosta dal Consorzio: contatta l\'assistenza.');
        }
        if ($online && $this->approvalRequired() && $p->approved_at === null && $p->review !== 'pending') {
            // prima pubblicazione: se il contenuto non è ancora stato inviato, lo si manda in revisione così com'è
            $this->save($p, $this->content($p, true) + [], null, false);
            $p->refresh();
        }
        $p->forceFill(['enabled' => $online])->save();
        AuditLog::record($online ? 'page.online' : 'page.offline', "Pagina personale di {$p->consultant->code}: ".($online ? 'messa online' : 'tolta dal pubblico'), $p);

        return $p;
    }

    public function approve(ConsultantPage $p): ConsultantPage
    {
        if (! is_array($p->proposal)) {
            throw new PageException('Non ci sono modifiche da approvare.');
        }

        return DB::transaction(function () use ($p): ConsultantPage {
            $old = $p->photo_path;
            $new = $p->proposal;
            $p->forceFill($new + ['proposal' => null, 'review' => 'none', 'review_note' => null, 'approved_at' => now(), 'decided_at' => now()])->save();
            if ($old !== null && $old !== $p->photo_path) {
                Storage::delete($old);
            }
            AuditLog::record('page.approved', "Pagina personale di {$p->consultant->code}: modifiche approvate", $p);

            return $p->refresh();
        });
    }

    public function reject(ConsultantPage $p, string $note): ConsultantPage
    {
        if (! is_array($p->proposal)) {
            throw new PageException('Non ci sono modifiche da respingere.');
        }
        if (trim($note) === '') {
            throw new PageException('Scrivi il motivo: lo legge l\'incaricato.');
        }
        $p->forceFill(['review' => 'rejected', 'review_note' => trim($note), 'decided_at' => now()])->save();
        AuditLog::record('page.rejected', "Pagina personale di {$p->consultant->code}: modifiche non approvate", $p, [], trim($note));

        return $p;
    }

    public function block(ConsultantPage $p, string $note): ConsultantPage
    {
        $p->forceFill(['blocked' => true, 'blocked_note' => trim($note) ?: null])->save();
        AuditLog::record('page.blocked', "Pagina personale di {$p->consultant->code}: nascosta dal Consorzio", $p, [], trim($note) ?: null);

        return $p;
    }

    public function unblock(ConsultantPage $p): ConsultantPage
    {
        $p->forceFill(['blocked' => false, 'blocked_note' => null])->save();
        AuditLog::record('page.unblocked', "Pagina personale di {$p->consultant->code}: di nuovo consentita", $p);

        return $p;
    }

    /** Numero WhatsApp in sole cifre con prefisso (default Italia), oppure null. */
    public static function whatsappDigits(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (strlen($d) <= 11 && str_starts_with($d, '3')) {
            $d = '39'.$d;
        }

        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }

    /** @param array<string, mixed> $data */
    private function normalize(array $data): array
    {
        $out = [];
        foreach (['display_name', 'headline', 'bio', 'story', 'whatsapp', 'email_public', 'instagram', 'facebook', 'tiktok', 'linkedin'] as $f) {
            $v = trim((string) ($data[$f] ?? ''));
            $out[$f] = $v === '' ? null : $v;
        }
        $media = [];
        foreach (array_slice((array) ($data['media'] ?? []), 0, self::MEDIA_SLOTS) as $m) {
            $m = (array) $m;
            $url = trim((string) ($m['url'] ?? ''));
            $title = trim((string) ($m['title'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }
            $media[] = ['kind' => array_key_exists($m['kind'] ?? '', self::MEDIA_KINDS) ? $m['kind'] : 'article', 'title' => $title, 'url' => $url, 'description' => trim((string) ($m['description'] ?? '')) ?: null];
        }
        $out['media'] = $media;

        return $out;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'incaricato';
        $slug = Str::limit($slug, 34, '');
        if (strlen($slug) < 3) {
            $slug .= '-lm';
        }
        $candidate = $slug;
        for ($i = 2; in_array($candidate, self::RESERVED, true) || ConsultantPage::where('slug', $candidate)->exists(); $i++) {
            $candidate = $slug.'-'.$i;
        }

        return $candidate;
    }
}
