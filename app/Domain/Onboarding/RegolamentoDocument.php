<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLog;
use App\Models\LegalDocument;
use App\Models\LegalDocumentEdit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * «Principi generali e Regolamento» (Allegato 1), modificabile dal back-office.
 * Finché nessuno lo salva vale il file di base resources/legal/regolamento-v2.0.md. Chi firma la Lettera di Incarico lo accetta
 * con una casella dedicata; nelle prove di firma restano versione e impronta del testo accettato.
 */
final class RegolamentoDocument
{
    public const SLUG = 'regolamento';

    public function row(): ?LegalDocument
    {
        if (! Schema::hasTable('legal_documents')) {
            return null;
        }

        return LegalDocument::where('slug', self::SLUG)->first();
    }

    public function version(): string
    {
        return $this->row()?->version ?? IvdAcceptances::REGOLAMENTO_VERSION;
    }

    public function html(): string
    {
        $row = $this->row();
        if ($row !== null) {
            return $row->body;
        }
        $md = (string) file_get_contents(resource_path('legal/regolamento-v2.0.md'));
        $md = (string) preg_replace('/^# .*\R+/u', '', $md, 1);

        return (string) Str::markdown($md);
    }

    public function articles1341(): string
    {
        return filled($this->row()?->articles_1341) ? (string) $this->row()->articles_1341 : IvdAcceptances::REGOLAMENTO_1341;
    }

    /** Impronta del testo in vigore, conservata nelle prove di firma. */
    public function hash(): string
    {
        return hash('sha256', $this->html());
    }

    /** Correzione di refuso: stessa versione, nessuna nuova accettazione. @throws OnboardingException */
    public function saveTypoFix(string $html, string $summary, string $actor): LegalDocument
    {
        $html = $this->clean($html);

        return DB::transaction(function () use ($html, $summary, $actor): LegalDocument {
            $doc = $this->baseline($actor);
            if ($this->norm($doc->body) === $this->norm($html)) {
                throw new OnboardingException('Il testo è identico a quello in vigore: non c\'è niente da salvare.');
            }
            $d = app(IvdLetterTemplate::class)->diff($doc->body, $html);
            if ($d['numbers_changed'] || $d['added'] + $d['removed'] > 3 * IvdLetterTemplate::MAX_TYPO_WORDS) {
                throw new OnboardingException('La correzione cambia numeri, importi o molte parole: non è un refuso. Pubblicala come nuova versione.');
            }
            LegalDocumentEdit::create(['legal_document_id' => $doc->id, 'kind' => 'typo', 'version' => $doc->version, 'summary' => $summary ?: null, 'body_before' => $doc->body, 'actor' => $actor, 'created_at' => now()]);
            $doc->update(['body' => $html, 'revision' => $doc->revision + 1, 'updated_by' => $actor]);
            AuditLog::record('regolamento.typo_fix', "Regolamento v{$doc->version}: corretto un refuso", $doc);

            return $doc->refresh();
        });
    }

    /** Nuova versione: da qui in poi chi firma accetta questa. @throws OnboardingException */
    public function saveNewVersion(string $html, string $version, string $summary, ?string $articles1341, string $actor): LegalDocument
    {
        $html = $this->clean($html);
        $version = trim($version);
        if ($version === '' || mb_strlen($version) > 30) {
            throw new OnboardingException('Scrivi il numero della nuova versione (es. 2.1).');
        }
        if (trim($summary) === '') {
            throw new OnboardingException('Scrivi cosa cambia: chi firma lo legge.');
        }

        return DB::transaction(function () use ($html, $version, $summary, $articles1341, $actor): LegalDocument {
            $doc = $this->baseline($actor);
            if ($version === $doc->version) {
                throw new OnboardingException("La versione {$version} è già in vigore: scegli un numero nuovo.");
            }
            LegalDocumentEdit::create(['legal_document_id' => $doc->id, 'kind' => 'new_version', 'version' => $version, 'summary' => $summary, 'body_before' => $doc->body, 'actor' => $actor, 'created_at' => now()]);
            $doc->update(['body' => $html, 'version' => $version, 'revision' => 0, 'change_summary' => $summary, 'articles_1341' => filled($articles1341) ? trim((string) $articles1341) : null, 'updated_by' => $actor]);
            AuditLog::record('regolamento.new_version', "Regolamento: nuova versione {$version}", $doc);

            return $doc->refresh();
        });
    }

    private function baseline(string $actor): LegalDocument
    {
        $row = LegalDocument::where('slug', self::SLUG)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }
        $row = LegalDocument::create(['slug' => self::SLUG, 'version' => IvdAcceptances::REGOLAMENTO_VERSION, 'body' => $this->html(), 'updated_by' => $actor]);
        LegalDocumentEdit::create(['legal_document_id' => $row->id, 'kind' => 'initial', 'version' => $row->version, 'summary' => 'Testo di partenza', 'actor' => $actor, 'created_at' => now()]);

        return $row;
    }

    private function clean(string $html): string
    {
        $html = app(IvdLetterTemplate::class)->sanitize($html);
        if (mb_strlen(trim(strip_tags($html))) < 500) {
            throw new OnboardingException('Il testo del Regolamento è troppo corto: controlla di non aver cancellato quasi tutto.');
        }

        return $html;
    }

    private function norm(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['&nbsp;', "\u{00A0}"], ' ', preg_replace('/<\/(p|li|h\d)>/i', ' ', $html)))));
    }
}
