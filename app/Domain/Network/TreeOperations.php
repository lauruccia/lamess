<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\RankAchievement;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Operazioni sull'albero (fase 3). Ognuna chiede un motivo e scrive il log di audit con valori prima/dopo.
 *
 * Spostare un ramo o cambiare sponsor è un'eccezione al Reg. 2.10.a (lo sponsor non cambia): serve un permesso apposito
 * (`network.restructure`, concesso solo dal super_admin). Lo sponsor cambia subito nell'albero; i periodi APERTI usano il nuovo albero
 * al prossimo calcolo, i periodi CHIUSI restano com'erano. Scegliendo «ricalcola i mesi chiusi» l'elenco dei mesi interessati
 * viene registrato (la riapertura dei mesi arriva con la fase 6) e l'operazione la può fare solo il super_admin.
 */
final class TreeOperations
{
    /**
     * Anteprima di uno spostamento: chi si muove, da dove a dove, quali mesi toccano. Non scrive nulla.
     *
     * @return array{
     *   ok: bool, errors: list<string>, moved_count: int, moved_ids: list<int>,
     *   from_chain: list<string>, to_chain: list<string>, depth_shift: int,
     *   open_periods: array<string, array{movements: int, pv: string}>, closed_periods: array<string, array{movements: int, pv: string}>
     * }
     */
    public function previewMove(Consultant $c, ?Consultant $newSponsor, bool $withBranch = true, ?DateTimeInterface $effectiveFrom = null): array
    {
        $errors = [];
        $movedIds = $this->subtreeIds($c);
        $moved = $withBranch ? $movedIds : [$c->id];

        if ($c->status === 'terminated') {
            $errors[] = "L’incaricato {$c->code} è cessato: non si sposta.";
        }
        if ($newSponsor === null) {
            $errors[] = 'Indica il nuovo sponsor.';
        } else {
            if ($newSponsor->id === $c->sponsor_id) {
                $errors[] = 'Il nuovo sponsor coincide con quello attuale.';
            }
            if (in_array($newSponsor->id, $movedIds, true)) {
                $errors[] = 'Lo spostamento creerebbe un ciclo: il nuovo sponsor è lo stesso incaricato o un suo discendente.';
            }
            if (! in_array($newSponsor->status, (array) config('lamess.sponsoring_statuses'), true)) {
                $errors[] = "Il nuovo sponsor {$newSponsor->code} non può sponsorizzare (stato: {$newSponsor->status}).";
            }
        }
        if ($effectiveFrom !== null && $effectiveFrom->format('Y-m-d') > now()->format('Y-m-d')) {
            $errors[] = 'La data di efficacia non può essere nel futuro: lo spostamento è immediato.';
        }

        $from = $effectiveFrom?->format('Y-m-d') ?? now()->format('Y-m-d');
        $periods = $this->periodsTouched($moved, $from);

        $depthNew = $newSponsor === null ? 0 : $this->depthOf($newSponsor) + 1;

        return [
            'ok' => $errors === [], 'errors' => $errors, 'moved_count' => count($moved), 'moved_ids' => array_values($moved),
            'from_chain' => $this->chain($c->sponsor_id), 'to_chain' => $newSponsor === null ? [] : $this->chain($newSponsor->id),
            'depth_shift' => $depthNew - $this->depthOf($c),
            'open_periods' => $periods['open'], 'closed_periods' => $periods['closed'],
        ];
    }

    /**
     * Sposta un incaricato sotto un nuovo sponsor. Con $withBranch il ramo lo segue; senza, si sposta solo lui
     * e i suoi diretti passano al suo sponsor precedente.
     *
     * @return array<string, mixed> anteprima dell'operazione eseguita
     */
    public function moveBranch(Consultant $c, Consultant $newSponsor, string $reason, bool $withBranch = true, ?DateTimeInterface $effectiveFrom = null, bool $recalcClosed = false): array
    {
        $this->requireReason($reason);
        $effectiveFrom ??= new DateTimeImmutable('today');
        if ($recalcClosed && ! (Auth::user()?->isSuperAdmin() ?? false)) {
            throw new NetworkException('Solo il super_admin può chiedere il ricalcolo dei mesi chiusi.');
        }
        $impact = $this->previewMove($c, $newSponsor, $withBranch, $effectiveFrom);
        if (! $impact['ok']) {
            throw new NetworkException(implode(' ', $impact['errors']));
        }
        $oldSponsorId = $c->sponsor_id;
        $by = Actor::label();

        DB::transaction(function () use ($c, $newSponsor, $withBranch, $effectiveFrom, $recalcClosed, $reason, $oldSponsorId, $impact, $by): void {
            Consultant::$sponsorChangeAllowed = true;
            try {
                if (! $withBranch) {
                    foreach (Consultant::where('sponsor_id', $c->id)->orderBy('id')->get() as $child) {
                        $child->update(['sponsor_id' => $oldSponsorId]);
                        $this->history($child, $c->id, $oldSponsorId, 'technical_fix', $effectiveFrom, false, $recalcClosed, "Segue lo sponsor di {$c->code}: {$reason}", null, $by);
                    }
                }
                $c->update(['sponsor_id' => $newSponsor->id]);
            } finally {
                Consultant::$sponsorChangeAllowed = false;
            }
            $this->history($c, $oldSponsorId, $newSponsor->id, 'transfer', $effectiveFrom, $withBranch, $recalcClosed, $reason, $impact, $by);
            app(NetworkService::class)->rebuildClosure();
        });

        AuditLog::record(
            'network.moved',
            ($withBranch ? 'Ramo spostato: ' : 'Incaricato spostato (senza ramo): ')."{$c->code} → sotto {$newSponsor->code}",
            $c,
            [
                'sponsor' => ['prima' => $oldSponsorId === null ? null : Consultant::find($oldSponsorId)?->code, 'dopo' => $newSponsor->code],
                'persone_spostate' => $impact['moved_count'], 'efficace_dal' => $effectiveFrom->format('Y-m-d'),
                'ricalcola_mesi_chiusi' => $recalcClosed, 'mesi_chiusi_interessati' => array_keys($impact['closed_periods']),
            ],
            $reason,
        );

        return $impact;
    }

    /**
     * Riassegna clienti a un incaricato (o a nessuno). Singolo o in blocco; ogni cliente ha il suo storico.
     *
     * @param  iterable<Customer|int>  $customers
     * @return int clienti effettivamente spostati
     */
    public function reassignCustomers(iterable $customers, ?Consultant $to, string $reason): int
    {
        $this->requireReason($reason);
        if ($to !== null && $to->status !== 'active') {
            throw new NetworkException("L’incaricato {$to->code} non è attivo: non può ricevere clienti.");
        }
        $by = Actor::label();
        $n = 0;
        $changes = [];
        DB::transaction(function () use ($customers, $to, $reason, $by, &$n, &$changes): void {
            foreach ($customers as $c) {
                $customer = $c instanceof Customer ? $c : Customer::findOrFail($c);
                if ($customer->assigned_consultant_id === $to?->id) {
                    continue;
                }
                $from = $customer->assigned_consultant_id;
                $customer->update(['assigned_consultant_id' => $to?->id]);
                DB::table('customer_assignment_history')->insert([
                    'customer_id' => $customer->id, 'from_consultant_id' => $from, 'to_consultant_id' => $to?->id,
                    'reason' => $reason, 'changed_by' => $by, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $changes[] = $customer->id;
                $n++;
            }
        });
        if ($n > 0) {
            AuditLog::record(
                'customers.reassigned', "Clienti riassegnati: {$n}".($to ? " → {$to->code}" : ' → nessuno'), ['Customer', $to?->code ?? 'nessuno'],
                ['clienti' => array_slice($changes, 0, 200), 'consulente' => $to?->code], $reason,
            );
        }

        return $n;
    }

    /** Cambio di qualifica manuale: segnalato «forzato» (fonte `forced`) e sempre motivato. */
    public function forceRank(Consultant $c, string $rankCode, DateTimeInterface $effectiveFrom, string $reason): RankAchievement
    {
        $this->requireReason($reason);
        $plan = CompPlanVersion::inForce()?->plan() ?? throw new NetworkException('Nessun piano in vigore.');
        try {
            $plan->rank($rankCode);
        } catch (\OutOfBoundsException) {
            throw new NetworkException("Qualifica sconosciuta: {$rankCode}.");
        }
        if ($c->status === 'terminated') {
            throw new NetworkException("L’incaricato {$c->code} è cessato.");
        }
        $existing = RankAchievement::where('consultant_id', $c->id)->where('rank_code', $rankCode)->first();
        if ($existing !== null) {
            throw new NetworkException("{$c->code} ha già la qualifica {$rankCode} (fonte: {$existing->source}).");
        }
        $a = RankAchievement::create([
            'consultant_id' => $c->id, 'rank_code' => $rankCode, 'achieved_at' => $effectiveFrom,
            'effective_from' => $effectiveFrom->format('Y-m-d'), 'source' => 'forced', 'detail' => ['motivo' => $reason, 'da' => Actor::label()],
        ]);
        AuditLog::record('rank.forced', "Qualifica forzata: {$c->code} → {$rankCode}", $c, ['qualifica' => ['prima' => $this->highestRank($c, $a->id), 'dopo' => $rankCode], 'efficace_dal' => $effectiveFrom->format('Y-m-d')], $reason);

        return $a;
    }

    // ———— interni ————

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new NetworkException('Indica il motivo: ogni operazione sull\'albero lo richiede.');
        }
    }

    private function highestRank(Consultant $c, int $exceptId): ?string
    {
        $plan = CompPlanVersion::inForce()?->plan();
        $best = null;
        foreach (RankAchievement::where('consultant_id', $c->id)->where('id', '!=', $exceptId)->get() as $r) {
            if ($plan !== null && ($best === null || $plan->rank($r->rank_code)->sequence > $plan->rank($best)->sequence)) {
                $best = $r->rank_code;
            }
        }

        return $best;
    }

    /** @return list<int> l’incaricato e tutti i suoi discendenti */
    public function subtreeIds(Consultant $c): array
    {
        return DB::table('network_paths')->where('ancestor_id', $c->id)->pluck('descendant_id')->map(fn ($v) => (int) $v)->all();
    }

    private function depthOf(Consultant $c): int
    {
        return (int) DB::table('network_paths')->where('descendant_id', $c->id)->max('depth');
    }

    /** @return list<string> codici dal diretto sponsor fino alla radice */
    private function chain(?int $sponsorId): array
    {
        $out = [];
        $seen = [];
        while ($sponsorId !== null && ! isset($seen[$sponsorId])) {
            $seen[$sponsorId] = true;
            $s = Consultant::find($sponsorId);
            if ($s === null) {
                break;
            }
            $out[] = $s->code;
            $sponsorId = $s->sponsor_id;
        }

        return $out;
    }

    /**
     * Periodi con movimenti degli incaricati spostati a partire dalla data di efficacia.
     *
     * @param  list<int>  $ids
     * @return array{open: array<string, array{movements: int, pv: string}>, closed: array<string, array{movements: int, pv: string}>}
     */
    private function periodsTouched(array $ids, string $from): array
    {
        $out = ['open' => [], 'closed' => []];
        $rows = DB::table('pv_movements as m')->join('periods as p', 'p.id', '=', 'm.period_id')
            ->whereIn('m.consultant_id', $ids)->where('m.status', 'confirmed')->where('m.event_at', '>=', $from)
            ->groupBy('p.code', 'p.status')->orderBy('p.code')
            ->selectRaw('p.code as code, p.status as status, count(*) as n, sum(m.pv) as pv')->get();
        foreach ($rows as $r) {
            $out[$r->status === 'closed' ? 'closed' : 'open'][$r->code] = ['movements' => (int) $r->n, 'pv' => number_format((float) $r->pv, 2, '.', '')];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $impact */
    private function history(Consultant $c, ?int $from, ?int $to, string $type, DateTimeInterface $effective, bool $withBranch, bool $recalc, string $note, ?array $impact, string $by): void
    {
        $periodId = DB::table('periods')->where('status', '!=', 'closed')->orderBy('code')->value('id');
        DB::table('sponsor_history')->insert([
            'consultant_id' => $c->id, 'from_sponsor_id' => $from, 'to_sponsor_id' => $to, 'reason' => $type, 'period_id' => $periodId,
            'approved_by' => $by, 'effective_from' => $effective->format('Y-m-d'), 'with_branch' => $withBranch, 'recalc_closed' => $recalc,
            'note' => $note, 'impact' => $impact === null ? null : json_encode($impact, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
