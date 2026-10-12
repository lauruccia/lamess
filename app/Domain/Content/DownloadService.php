<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\Download;
use App\Models\DownloadLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Chi può scaricare cosa, filigrana nominativa e registro dei download. */
final class DownloadService
{
    public const DISK = 'local';

    /** L’incaricato deve essere attivo (per i materiali «incaricati») e avere la qualifica richiesta, se c'è. */
    public function canDownload(Download $d, User $user): bool
    {
        if (! $d->active || ! Audience::allows($d->audience, $user)) {
            return false;
        }
        if ($d->min_rank_code) {
            $c = $user->consultant;

            return $c !== null && $c->status === 'active' && RankLookup::atLeast($c, $d->min_rank_code);
        }

        return true;
    }

    /** @return Collection<int, Download> */
    public function visibleTo(User $user)
    {
        return Download::where('active', true)->orderBy('category')->orderBy('title')->get()->filter(fn (Download $d) => Audience::allows($d->audience, $user))->values();
    }

    public function label(User $user): string
    {
        $c = $user->consultant;

        return trim($user->name.($c ? ' · '.$c->code : '').' · '.now()->setTimezone(config('app.timezone'))->format('d/m/Y'));
    }

    /**
     * @return array{content: string, mime: string, name: string, watermarked: bool}
     *
     * @throws ContentException se non ammesso, se il file manca o se la filigrana è obbligatoria e non riesce
     */
    public function prepare(Download $d, User $user, ?string $ip = null): array
    {
        if (! $this->canDownload($d, $user)) {
            throw new ContentException('Non puoi scaricare questo materiale.');
        }
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($d->file_path)) {
            throw new ContentException('Il file non è più disponibile. Segnalalo all\'assistenza.');
        }
        $mime = (string) ($d->mime ?: 'application/octet-stream');
        $note = null;
        $content = null;
        $marked = false;
        if ($d->watermark) {
            if (! Watermarker::supports($mime)) {
                $note = 'formato senza filigrana';
            } else {
                try {
                    [$content] = (new Watermarker)->apply($disk->path($d->file_path), $mime, $this->label($user));
                    $marked = true;
                } catch (ContentException $e) {
                    if (! config('lamess.content.watermark_fail_open')) {
                        throw new ContentException('Non riesco ad applicare la filigrana a questo file. Segnalalo all\'assistenza.');
                    }
                    $note = mb_substr($e->getMessage(), 0, 240);
                }
            }
        }
        $content ??= $disk->get($d->file_path);
        DownloadLog::create(['download_id' => $d->id, 'user_id' => $user->id, 'consultant_id' => $user->consultant?->id, 'ip' => $ip, 'watermarked' => $marked, 'note' => $note]);

        return ['content' => $content, 'mime' => $mime, 'name' => $d->original_name, 'watermarked' => $marked];
    }
}
