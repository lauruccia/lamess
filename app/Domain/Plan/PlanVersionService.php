<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Policy\PolicySpecs;
use App\Domain\Engine\Support\Canonical;
use App\Models\CompPlanVersion;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioni del piano compensi (fase 2): bozza → attiva/programmata → archiviata.
 *
 * Regole:
 *  - solo le bozze si modificano; una versione attiva o archiviata non si tocca (RF-060) — salvo il super_admin, che non ha limiti;
 *  - ogni creazione, modifica, attivazione e duplicazione scrive il log di audit con valore precedente e nuovo;
 *  - l'attivazione ha una data di efficacia (non nel passato): da quel giorno la versione vale per i periodi che si aprono.
 *    I periodi già aperti mantengono il loro piano.
 */
final class PlanVersionService
{
    /** @param array<string, mixed> $data */
    public function create(string $name, array $data, ?string $notes = null, ?string $reason = null): CompPlanVersion
    {
        $v = CompPlanVersion::createFromArray($name, $data);
        $v->update(['notes' => $notes]);
        AuditLog::record('plan.created', "Bozza del piano creata: {$name}", $v, ['versione' => $v->id], $reason);

        return $v;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{prima: mixed, dopo: mixed}> cosa è cambiato
     */
    public function updateDraft(CompPlanVersion $v, string $name, array $data, ?string $notes, ?string $reason): array
    {
        if ($v->status !== CompPlanVersion::DRAFT && ! self::isSuper()) {
            throw ValidationException::withMessages(['data' => 'Solo una bozza si può modificare: duplica la versione e modifica la copia.']);
        }
        PlanConfig::fromArray($data); // lancia se i valori non sono validi

        $changes = self::diff($v->data, $data);
        if ($name !== $v->name) {
            $changes['nome'] = ['prima' => $v->name, 'dopo' => $name];
        }
        if (($notes ?? '') !== ($v->notes ?? '')) {
            $changes['note'] = ['prima' => $v->notes, 'dopo' => $notes];
        }
        if ($changes === []) {
            return [];
        }
        $v->update(['name' => $name, 'data' => $data, 'notes' => $notes, 'content_hash' => Canonical::hash($data)]);
        AuditLog::record('plan.updated', ($v->status === CompPlanVersion::DRAFT ? 'Bozza del piano modificata: ' : "Versione del piano ({$v->status}) modificata dal super_admin: ").$name.' ('.count($changes).' valori)', $v, $changes, $reason);

        return $changes;
    }

    /** Duplica una versione come nuova bozza. */
    public function duplicate(CompPlanVersion $source, ?string $reason = null): CompPlanVersion
    {
        $copy = CompPlanVersion::createFromArray($source->name.' (copia)', $source->data);
        AuditLog::record('plan.duplicated', "Versione del piano duplicata: {$source->name}", $copy, ['da_versione' => $source->id], $reason);

        return $copy;
    }

    /** Il super_admin è libero: modifica ogni versione, pubblica senza secondo amministratore e senza vincoli di data. */
    public static function isSuper(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /** True se la pubblicazione del piano richiede l'approvazione di un secondo amministratore (impostazione «Piano compensi»). */
    public static function fourEyes(): bool
    {
        return (bool) config('lamess.plan_four_eyes');
    }

    /**
     * Punto d'ingresso dell'interfaccia: con i quattro occhi attivi chiede l'approvazione, altrimenti attiva subito.
     * La versione resta una bozza non modificabile finché il secondo amministratore non decide.
     */
    public function publish(CompPlanVersion $v, DateTimeImmutable $effectiveFrom, string $reason): CompPlanVersion
    {
        return self::fourEyes() && ! self::isSuper() ? $this->requestApproval($v, $effectiveFrom, $reason) : $this->activate($v, $effectiveFrom, $reason);
    }

    /** Chiede l'approvazione: la bozza passa «in approvazione» e non si può più modificare. */
    public function requestApproval(CompPlanVersion $v, DateTimeImmutable $effectiveFrom, string $reason): CompPlanVersion
    {
        $this->assertCanBePublished($v, $effectiveFrom, $reason);
        $user = Auth::user();
        $v->update([
            'status' => CompPlanVersion::PENDING_APPROVAL, 'requested_by_id' => $user?->getAuthIdentifier(), 'requested_by_label' => Actor::label(),
            'requested_at' => now(), 'requested_effective_from' => $effectiveFrom->format('Y-m-d'), 'request_reason' => $reason,
        ]);
        AuditLog::record('plan.approval_requested', "Pubblicazione del piano richiesta: {$v->name} (efficacia dal ".$effectiveFrom->format('d/m/Y').')', $v,
            ['efficace_dal' => $effectiveFrom->format('Y-m-d')], $reason);

        return $v;
    }

    /** Il secondo amministratore approva: chi ha chiesto non può approvare la propria richiesta, nemmeno il super_admin. */
    public function approve(CompPlanVersion $v, ?string $note = null): CompPlanVersion
    {
        $this->assertPending($v);
        $userId = Auth::id();
        if ($userId === null || ((int) $userId === (int) $v->requested_by_id && ! self::isSuper())) {
            throw ValidationException::withMessages(['status' => 'Chi ha richiesto la pubblicazione non può approvarla: serve un secondo amministratore.']);
        }
        // Se la data richiesta è passata nel frattempo, vale da oggi.
        $from = max($v->requested_effective_from?->format('Y-m-d') ?? '', now()->format('Y-m-d'));
        $reason = $v->request_reason ?: 'Approvata';
        $v->update(['status' => CompPlanVersion::DRAFT]);
        $v = $this->activate($v, new DateTimeImmutable($from), $reason, true);
        AuditLog::record('plan.approved', "Pubblicazione del piano approvata: {$v->name}", $v, ['richiesta_da' => $v->requested_by_label], $note);

        return $v;
    }

    /** Il secondo amministratore rifiuta (motivo obbligatorio): la versione torna bozza e si può correggere. */
    public function reject(CompPlanVersion $v, string $reason): CompPlanVersion
    {
        $this->assertPending($v);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Indica il motivo del rifiuto.']);
        }
        $v->update(['status' => CompPlanVersion::DRAFT]);
        AuditLog::record('plan.rejected', "Pubblicazione del piano rifiutata: {$v->name}", $v, ['richiesta_da' => $v->requested_by_label], $reason);

        return $v;
    }

    /** Chi ha chiesto può ritirare la richiesta prima della decisione. */
    public function withdraw(CompPlanVersion $v): CompPlanVersion
    {
        $this->assertPending($v);
        if ((int) Auth::id() !== (int) $v->requested_by_id && ! (Auth::user()?->isSuperAdmin() ?? false)) {
            throw ValidationException::withMessages(['status' => 'Solo chi ha fatto la richiesta può ritirarla.']);
        }
        $v->update(['status' => CompPlanVersion::DRAFT]);
        AuditLog::record('plan.approval_withdrawn', "Richiesta di pubblicazione ritirata: {$v->name}", $v);

        return $v;
    }

    /**
     * Attiva una bozza con data di efficacia. Efficacia oggi (o prima) = subito; futura = programmata.
     * Con i quattro occhi attivi non si chiama direttamente: si passa da publish() e approve().
     */
    public function activate(CompPlanVersion $v, DateTimeImmutable $effectiveFrom, string $reason, bool $approved = false): CompPlanVersion
    {
        if (self::fourEyes() && ! $approved && ! self::isSuper()) {
            throw ValidationException::withMessages(['status' => 'La pubblicazione del piano richiede l\'approvazione di un secondo amministratore.']);
        }
        $this->assertCanBePublished($v, $effectiveFrom, $reason);
        $before = $v->status;
        $today = now()->startOfDay();
        $immediate = $effectiveFrom->format('Y-m-d') <= $today->format('Y-m-d');

        DB::transaction(function () use ($v, $effectiveFrom, $immediate): void {
            // Una nuova versione sostituisce quelle con efficacia uguale o successiva già programmate.
            CompPlanVersion::where('status', CompPlanVersion::SCHEDULED)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '>=', $effectiveFrom->format('Y-m-d')))
                ->update(['status' => CompPlanVersion::ARCHIVED]);
            if ($immediate) {
                CompPlanVersion::where('status', CompPlanVersion::ACTIVE)->update(['status' => CompPlanVersion::ARCHIVED]);
            }
            $v->update([
                'status' => $immediate ? CompPlanVersion::ACTIVE : CompPlanVersion::SCHEDULED,
                'effective_from' => $effectiveFrom->format('Y-m-d'), 'activated_at' => now(), 'approved_by' => Actor::label(),
            ]);
        });
        AuditLog::record(
            'plan.activated',
            ($immediate ? 'Versione del piano attivata: ' : 'Versione del piano programmata: ').$v->name.' (efficacia dal '.$effectiveFrom->format('d/m/Y').')',
            $v, ['stato' => ['prima' => $before, 'dopo' => $v->status], 'efficace_dal' => $effectiveFrom->format('Y-m-d')], $reason,
        );

        return $v;
    }

    private function assertCanBePublished(CompPlanVersion $v, DateTimeImmutable $effectiveFrom, string $reason): void
    {
        // Il super_admin può anche rimettere in vigore una versione archiviata o programmata.
        $publishable = self::isSuper() ? [CompPlanVersion::DRAFT, CompPlanVersion::ARCHIVED, CompPlanVersion::SCHEDULED, CompPlanVersion::PENDING_APPROVAL] : [CompPlanVersion::DRAFT];
        if (! in_array($v->status, $publishable, true)) {
            throw ValidationException::withMessages(['status' => 'Si pubblica solo una bozza.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Indica il motivo.']);
        }
        if ($effectiveFrom->format('Y-m-d') < now()->startOfDay()->format('Y-m-d') && ! self::isSuper()) {
            throw ValidationException::withMessages(['effective_from' => 'La data di efficacia non può essere nel passato.']);
        }
    }

    private function assertPending(CompPlanVersion $v): void
    {
        if ($v->status !== CompPlanVersion::PENDING_APPROVAL) {
            throw ValidationException::withMessages(['status' => 'La versione non è in attesa di approvazione.']);
        }
    }

    /** Le versioni programmate la cui data è arrivata diventano attive; la precedente si archivia. Si lancia ogni giorno. */
    public function promoteDue(): int
    {
        $n = 0;
        $due = CompPlanVersion::where('status', CompPlanVersion::SCHEDULED)->where('effective_from', '<=', now()->format('Y-m-d'))
            ->orderBy('effective_from')->orderBy('id')->get();
        foreach ($due as $v) {
            DB::transaction(function () use ($v): void {
                CompPlanVersion::where('status', CompPlanVersion::ACTIVE)->update(['status' => CompPlanVersion::ARCHIVED]);
                $v->update(['status' => CompPlanVersion::ACTIVE]);
            });
            AuditLog::record('plan.activated', "Versione del piano entrata in vigore: {$v->name}", $v, ['stato' => ['prima' => CompPlanVersion::SCHEDULED, 'dopo' => CompPlanVersion::ACTIVE]]);
            $n++;
        }

        return $n;
    }

    /**
     * Differenze tra due piani, una riga per valore: «ranks.PEARL.direct_pct», «settings.min_payout», «policies.…», «matching_pct.RUBY».
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{prima: mixed, dopo: mixed}>
     */
    public static function diff(array $old, array $new): array
    {
        $a = self::flatten($old);
        $b = self::flatten($new);
        $out = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            $x = $a[$k] ?? null;
            $y = $b[$k] ?? null;
            if (self::norm($x) !== self::norm($y)) {
                $out[$k] = ['prima' => $x, 'dopo' => $y];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function flatten(array $d): array
    {
        $flat = [];
        $flat['label'] = $d['label'] ?? null;
        // I valori mancanti valgono il default: «assente» e «uguale al default» non sono una differenza.
        foreach (PlanConfig::SETTINGS as $k => $def) {
            $flat["settings.$k"] = $d['settings'][$k] ?? $def[1];
        }
        foreach (array_replace(PolicySpecs::defaults(), $d['policies'] ?? []) as $k => $v) {
            $flat["policies.$k"] = $v;
        }
        foreach (($d['ranks'] ?? []) as $r) {
            $c = $r['code'] ?? '?';
            foreach (['name', 'sequence', 'pr_required', 'fifty_percent_rule', 'direct_pct', 'rank_bonus'] as $f) {
                $flat["ranks.$c.$f"] = $r[$f] ?? null;
            }
            $flat["ranks.$c.min_clients"] = $r['min_clients'] ?? 0;
            $flat["ranks.$c.min_pearl_first_level"] = $r['min_pearl_first_level'] ?? 0;
            $flat["ranks.$c.min_columns"] = $r['min_columns'] ?? 0;
            $flat["ranks.$c.min_column_clients"] = $r['min_column_clients'] ?? 0;
            $flat["ranks.$c.requisiti_disattivati"] = implode(', ', $r['disabled_requirements'] ?? []);
            $flat["ranks.$c.structure"] = collect($r['structure'] ?? [])->map(fn ($s) => ($s['count'] ?? '?').'× '.($s['rank'] ?? '?'))->implode(', ');
        }
        foreach (($d['matching_pct'] ?? []) as $c => $levels) {
            $flat["matching_pct.$c"] = implode(' / ', array_map('strval', $levels));
        }
        foreach (($d['kosmos'] ?? []) as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $i => $row) {
                    $flat["kosmos.$k.$i"] = is_array($row) ? json_encode($row, JSON_UNESCAPED_UNICODE) : $row;
                }
            } else {
                $flat["kosmos.$k"] = $v;
            }
        }
        foreach (($d['fsb_steps'] ?? []) as $s) {
            $flat['fsb_steps.'.($s['step'] ?? '?')] = json_encode($s, JSON_UNESCAPED_UNICODE);
        }

        return $flat;
    }

    private static function norm(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 8, '.', ''), '0'), '.');
        }

        return (string) $v;
    }
}
