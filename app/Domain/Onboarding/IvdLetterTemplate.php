<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLog;
use App\Models\IvdLetterEdit;
use App\Models\IvdLetterSignature;
use App\Models\IvdLetterVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Testo della Lettera di Incarico IVD, modificabile dal back-office.
 *
 * Due tipi di modifica, che l'amministratore sceglie al salvataggio:
 *  - CORREZIONE (refuso): corregge il testo della versione in vigore, non cambia numero di versione e NON si fa firmare.
 *    Il sistema rifiuta la correzione se tocca numeri o troppe parole: in quel caso è una modifica contrattuale.
 *  - CONTRATTUALE: crea una nuova versione (da far firmare). I nuovi incaricati firmano subito la nuova; quelli già registrati
 *    la firmano quando l'amministratore preme «Invia da firmare» ({@see IvdLetterSigning}).
 *
 * Finché nessuno ha salvato nulla vale il testo di base (resources/legal/ivd-letter-default.html) con la versione di config.
 * Nel testo ci sono segnaposto tra [[doppie quadre]] che il sistema sostituisce: canone annuale e caselle dell'art. 12.
 */
final class IvdLetterTemplate
{
    /** Parole che una correzione di refuso può toccare al massimo (aggiunte + tolte). */
    public const MAX_TYPO_WORDS = 30;

    /** Segnaposto obbligatori: le caselle dell'art. 12 servono alle prove di firma. */
    public const REQUIRED_TOKENS = ['CASELLE_REDDITO', 'CASELLE_PREVIDENZA', 'CASELLA_PENSIONATO', 'CASELLE_PLURIMANDATARIO'];

    public const OPTIONAL_TOKENS = ['SE_CANONE', 'ALTRIMENTI', 'FINE_SE', 'IMPORTO_CANONE'];

    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><a><span><div><blockquote><table><thead><tbody><tr><td><th><hr>';

    public function current(): ?IvdLetterVersion
    {
        if (! Schema::hasTable('ivd_letter_versions')) {
            return null;
        }

        return IvdLetterVersion::where('is_current', true)->latest('id')->first();
    }

    /** Etichetta della versione in vigore (quella che finisce nei contratti firmati). */
    public function versionLabel(): string
    {
        return $this->current()?->version ?? (string) config('lamess.ivd_template_version');
    }

    public function defaultBody(): string
    {
        return (string) file_get_contents(resource_path('legal/ivd-letter-default.html'));
    }

    public function body(?IvdLetterVersion $v = null): string
    {
        $v ??= $this->current();

        return $v?->body ?? $this->defaultBody();
    }

    public function articles1341(?IvdLetterVersion $v = null): string
    {
        $v ??= $this->current();

        return filled($v?->articles_1341) ? (string) $v->articles_1341 : IvdAcceptances::LETTER_1341;
    }

    /**
     * Testo pronto da mostrare o stampare nel PDF.
     *
     * @param  array<string,mixed>  $decl  dichiarazioni dell'art. 12 (vuote = caselle non spuntate)
     */
    public function render(array $decl = [], ?bool $feeEnabled = null, mixed $feeAmount = null, ?IvdLetterVersion $v = null): string
    {
        return $this->renderBody($this->body($v), $decl, $feeEnabled, $feeAmount);
    }

    /** @param  array<string,mixed>  $decl */
    public function renderBody(string $body, array $decl = [], ?bool $feeEnabled = null, mixed $feeAmount = null): string
    {
        $feeEnabled ??= (bool) config('lamess.annual_fee_enabled', true);
        $amount = number_format((float) ($feeAmount ?? config('lamess.annual_fee_amount')), 2, ',', '.');

        $body = (string) preg_replace_callback('/\[\[SE_CANONE\]\](.*?)(?:\[\[ALTRIMENTI\]\](.*?))?\[\[FINE_SE\]\]/s', static fn (array $m): string => $feeEnabled ? $m[1] : ($m[2] ?? ''), $body);
        $body = str_replace('[[IMPORTO_CANONE]]', $amount, $body);

        $box = static fn (mixed $value, mixed $expected): string => $value !== null && $value === $expected ? '☒' : '☐';
        $blocks = [
            'CASELLE_REDDITO' => '<p class="dich">'.$box($decl['income_over_threshold'] ?? null, 'yes').' avere &nbsp; '.$box($decl['income_over_threshold'] ?? null, 'no').' non avere superato un reddito complessivo netto di € 5.000,00 come descritto al precedente art. 7.</p>',
            'CASELLE_PREVIDENZA' => '<p class="dich">'.$box($decl['other_social_security'] ?? null, 'yes').' di avere altra posizione previdenziale obbligatoria aperta &nbsp; '.$box($decl['other_social_security'] ?? null, 'no').' di non avere altra posizione previdenziale obbligatoria aperta</p>',
            'CASELLA_PENSIONATO' => '<p class="dich">'.$box(! empty($decl['is_pensioner']), true).' di essere in possesso di pensione previdenziale e s’impegna a comunicare al Consorzio, mediante l’utilizzo del servizio di assistenza dedicato www.kadeogroup.it/support, ogni variazione della propria posizione previdenziale.</p>',
            'CASELLE_PLURIMANDATARIO' => '<p class="dich">'.$box($decl['multi_principal'] ?? null, 'no').' NO &nbsp; '.$box($decl['multi_principal'] ?? null, 'yes').' SI &nbsp; Quali? <u>'.e(($decl['multi_principal'] ?? null) === 'yes' ? (string) ($decl['multi_principal_names'] ?? '') : '').'</u></p>',
        ];
        if (! empty($decl['_form_below'])) {
            // Nella schermata di firma le caselle dell'art. 12 si compilano nel modulo sotto: qui non si ripetono (sarebbero caselle morte).
            $note = '<p class="dich"><em>Le dichiarazioni dell\'art. 12.1 e 12.2 si compilano nella sezione «2 · Dichiarazioni» qui sotto.</em></p>';
            $blocks = ['CASELLE_REDDITO' => $note, 'CASELLE_PREVIDENZA' => '', 'CASELLA_PENSIONATO' => '', 'CASELLE_PLURIMANDATARIO' => ''];
        }
        foreach ($blocks as $token => $html) {
            $body = (string) preg_replace('/<p[^>]*>\s*\[\['.$token.'\]\]\s*<\/p>/', $html, $body);
            $body = str_replace('[['.$token.']]', $html, $body);
        }

        return '<div class="ivd-letter">'.$body.'</div>';
    }

    /** Pulisce l'HTML dell'editor: solo i tag di un testo normale, niente script né attributi di evento. */
    public function sanitize(string $html): string
    {
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $html);

        return trim($html);
    }

    /** Controlla che i segnaposto non siano stati rovinati dalla modifica. @throws OnboardingException */
    public function assertValid(string $body): void
    {
        if (mb_strlen(trim(strip_tags($body))) < 500) {
            throw new OnboardingException('Il testo della lettera è troppo corto: controlla di non aver cancellato quasi tutto.');
        }
        preg_match_all('/\[\[([A-Za-z_0-9 ]+)\]\]/', $body, $m);
        $found = array_count_values($m[1]);
        foreach (array_keys($found) as $token) {
            if (! in_array($token, [...self::REQUIRED_TOKENS, ...self::OPTIONAL_TOKENS], true)) {
                throw new OnboardingException("Segnaposto sconosciuto: [[{$token}]]. Controlla di non averlo modificato per errore.");
            }
        }
        foreach (self::REQUIRED_TOKENS as $token) {
            if (($found[$token] ?? 0) !== 1) {
                throw new OnboardingException("Manca (o è doppio) il segnaposto [[{$token}]]: è la casella da spuntare dell'art. 12 e deve comparire una volta sola.");
            }
        }
        if (($found['SE_CANONE'] ?? 0) !== ($found['FINE_SE'] ?? 0) || ($found['SE_CANONE'] ?? 0) > 1 || ($found['ALTRIMENTI'] ?? 0) > ($found['SE_CANONE'] ?? 0)) {
            throw new OnboardingException('I segnaposto del canone annuale [[SE_CANONE]] … [[ALTRIMENTI]] … [[FINE_SE]] sono incompleti.');
        }
        $fee = preg_match('/\[\[SE_CANONE\]\](.*?)(?:\[\[ALTRIMENTI\]\].*?)?\[\[FINE_SE\]\]/s', $body, $inner) === 1 ? $inner[1] : '';
        if (preg_match('/<(p|ul|ol|li|h[1-4]|table)[ >]/i', $fee) === 1) {
            throw new OnboardingException('Tra [[SE_CANONE]] e [[FINE_SE]] deve esserci solo una frase, nello stesso paragrafo.');
        }
    }

    /**
     * Parole aggiunte e tolte tra due testi (confronto per parole, ignora l'HTML).
     *
     * @return array{added:int,removed:int,numbers_changed:bool,changes:list<string>}
     */
    public function diff(string $old, string $new): array
    {
        $a = $this->words($old);
        $b = $this->words($new);
        $pre = 0;
        $n = min(count($a), count($b));
        while ($pre < $n && $a[$pre] === $b[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) {
            $suf++;
        }
        $mid_a = array_slice($a, $pre, count($a) - $pre - $suf);
        $mid_b = array_slice($b, $pre, count($b) - $pre - $suf);

        $ops = count($mid_a) * count($mid_b) > 4_000_000 ? null : $this->lcsOps($mid_a, $mid_b);
        $added = $removed = 0;
        $changes = [];
        $numA = $numB = [];
        if ($ops === null) { // modifica enorme: conteggio grossolano
            $added = count($mid_b);
            $removed = count($mid_a);
            $numA = array_filter($mid_a, fn (string $w): bool => preg_match('/\d/', $w) === 1);
            $numB = array_filter($mid_b, fn (string $w): bool => preg_match('/\d/', $w) === 1);
        } else {
            $ctxBefore = array_slice($a, max(0, $pre - 4), min(4, $pre));
            $run = ['-' => [], '+' => []];
            $flush = function () use (&$run, &$changes, &$ctxBefore): void {
                if ($run['-'] !== [] || $run['+'] !== []) {
                    $changes[] = '… '.implode(' ', $ctxBefore).' [tolto: '.(implode(' ', $run['-']) ?: '—').'] [messo: '.(implode(' ', $run['+']) ?: '—').']';
                }
                $run = ['-' => [], '+' => []];
            };
            foreach ($ops as [$op, $w]) {
                if ($op === '=') {
                    $flush();
                    $ctxBefore = array_slice([...$ctxBefore, $w], -4);

                    continue;
                }
                $run[$op][] = $w;
                if ($op === '-') {
                    $removed++;
                    if (preg_match('/\d/', $w) === 1) {
                        $numA[] = $w;
                    }
                } else {
                    $added++;
                    if (preg_match('/\d/', $w) === 1) {
                        $numB[] = $w;
                    }
                }
            }
            $flush();
        }
        sort($numA);
        sort($numB);

        return ['added' => $added, 'removed' => $removed, 'numbers_changed' => $numA !== $numB, 'changes' => array_slice($changes, 0, 25)];
    }

    /**
     * Salva una correzione di refuso nella versione in vigore. Non crea firme da raccogliere.
     *
     * @throws OnboardingException
     */
    public function saveTypoFix(string $html, string $summary, string $actor): IvdLetterVersion
    {
        $html = $this->sanitize($html);
        $this->assertValid($html);

        return DB::transaction(function () use ($html, $summary, $actor): IvdLetterVersion {
            $v = $this->baseline($actor);
            $d = $this->diff($v->body, $html);
            $words = $d['added'] + $d['removed'];
            if ($words === 0 && $this->normalized($v->body) === $this->normalized($html)) {
                throw new OnboardingException('Il testo è identico a quello in vigore: non c\'è niente da salvare.');
            }
            if ($d['numbers_changed']) {
                throw new OnboardingException('La correzione tocca numeri, importi o date: non è un refuso. Salvala come modifica contrattuale (da far firmare).');
            }
            if ($words > self::MAX_TYPO_WORDS) {
                throw new OnboardingException("Hai cambiato {$words} parole (limite per un refuso: ".self::MAX_TYPO_WORDS.'): non sembra una correzione di battitura. Salvala come modifica contrattuale (da far firmare).');
            }
            IvdLetterEdit::create([
                'ivd_letter_version_id' => $v->id, 'kind' => 'typo', 'summary' => $summary !== '' ? $summary : null,
                'body_before' => $v->body, 'words_changed' => $words, 'actor' => $actor, 'created_at' => now(),
            ]);
            $v->update(['body' => $html, 'revision' => $v->revision + 1]);
            AuditLog::record('ivd_letter.typo_fix', "Lettera di incarico v{$v->version}: corretto un refuso ({$words} parole)", $v, ['parole' => $words], $summary ?: null);

            return $v->refresh();
        });
    }

    /**
     * Salva una modifica contrattuale: nasce una nuova versione, che da subito è quella dei nuovi incaricati.
     * Le richieste di firma ancora aperte della versione precedente vengono annullate (vanno rinviate con la nuova).
     *
     * @throws OnboardingException
     */
    public function saveContractualChange(string $html, string $summary, ?string $articles1341, string $actor): IvdLetterVersion
    {
        $html = $this->sanitize($html);
        $this->assertValid($html);
        if (trim($summary) === '') {
            throw new OnboardingException('Scrivi cosa cambia: gli incaricati lo leggono prima di firmare.');
        }

        return DB::transaction(function () use ($html, $summary, $articles1341, $actor): IvdLetterVersion {
            $old = $this->baseline($actor);
            if ($this->normalized($old->body) === $this->normalized($html) && trim((string) $articles1341) === trim((string) $old->articles_1341)) {
                throw new OnboardingException('Il testo è identico a quello in vigore: non c\'è nessuna modifica da far firmare.');
            }
            $d = $this->diff($old->body, $html);
            $old->update(['is_current' => false]);
            IvdLetterSignature::where('status', 'pending')->update(['status' => 'cancelled']);
            $new = IvdLetterVersion::create([
                'version' => $this->nextLabel(), 'body' => $html, 'change_summary' => trim($summary),
                'articles_1341' => filled($articles1341) ? trim((string) $articles1341) : null,
                'is_current' => true, 'published_at' => now(), 'published_by' => $actor,
            ]);
            IvdLetterEdit::create([
                'ivd_letter_version_id' => $new->id, 'kind' => 'contractual', 'summary' => trim($summary),
                'body_before' => $old->body, 'words_changed' => $d['added'] + $d['removed'], 'actor' => $actor, 'created_at' => now(),
            ]);
            AuditLog::record('ivd_letter.contractual_change', "Lettera di incarico: nuova versione {$new->version} (da far firmare, sostituisce {$old->version})", $new, ['da' => $old->version, 'a' => $new->version], trim($summary));

            return $new;
        });
    }

    /** Versione in vigore; se non c'è ancora nel database la crea dal testo di base (versione di config). */
    public function ensureCurrent(string $actor): IvdLetterVersion
    {
        return DB::transaction(fn (): IvdLetterVersion => $this->baseline($actor));
    }

    private function baseline(string $actor): IvdLetterVersion
    {
        $v = IvdLetterVersion::where('is_current', true)->lockForUpdate()->latest('id')->first();
        if ($v !== null) {
            return $v;
        }
        $v = IvdLetterVersion::firstOrCreate(['version' => (string) config('lamess.ivd_template_version')], [
            'body' => $this->sanitize($this->defaultBody()), 'is_current' => true, 'published_at' => now(), 'published_by' => $actor,
        ]);
        $v->update(['is_current' => true]);
        IvdLetterEdit::create(['ivd_letter_version_id' => $v->id, 'kind' => 'initial', 'summary' => 'Testo di partenza', 'actor' => $actor, 'created_at' => now()]);

        return $v;
    }

    private function nextLabel(): string
    {
        $year = now()->format('Y');
        $max = 0;
        foreach (IvdLetterVersion::where('version', 'like', $year.'-%')->pluck('version') as $label) {
            $max = max($max, (int) substr($label, 5));
        }
        $max = max($max, $year === '2026' ? 2 : 0); // '2026-2' è la versione già in uso prima di questa funzione

        return $year.'-'.($max + 1);
    }

    private function normalized(string $html): string
    {
        $text = (string) preg_replace('/<\/(p|li|h[1-6]|td|th|tr|div|blockquote)>|<br\s*\/?>/i', ' ', $html);
        $text = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private function words(string $html): array
    {
        $text = $this->normalized($html);

        return $text === '' ? [] : explode(' ', $text);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0:string,1:string}> operazioni '=' | '-' | '+'
     */
    private function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $t = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $t[$i][$j] = $a[$i] === $b[$j] ? $t[$i + 1][$j + 1] + 1 : max($t[$i + 1][$j], $t[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($t[$i + 1][$j] >= $t[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }

        return $ops;
    }
}
