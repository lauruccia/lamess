<?php

declare(strict_types=1);

namespace App\Domain\Pages;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\ConsultantPage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
        return (bool) config('lamess.personal_pages.enabled');
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

    /** Visibile al pubblico adesso? */
    public function isPublic(ConsultantPage $p): bool
    {
        if (! $this->enabled() || ! $p->enabled || $p->blocked || $p->approved_at === null) {
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
            $current = $toReview ? $this->content($p, true) : $this->content($p);
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
