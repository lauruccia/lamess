<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\Period;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Anagrafica della rete: iscrizione, stati, cessazione con ricollegamento dei diretti (Reg. 2.8),
 * re-iscrizione dopo N giorni (Reg. 2.10.b), closure table. Lo sponsor non si cambia mai (Reg. 2.10.a).
 */
final class NetworkService
{
    /** Transizioni di stato ammesse. */
    private const TRANSITIONS = [
        'pending' => ['active', 'terminated'],
        'active' => ['suspended', 'terminated'],
        'suspended' => ['active', 'terminated'],
        'terminated' => [],
    ];

    /** @param array{code:string,name:string,person_key?:?string,referral_code?:?string,onboarding_complete?:bool} $data */
    public function enroll(array $data, ?Consultant $sponsor, DateTimeInterface $since, string $status = 'active'): Consultant
    {
        $key = $data['person_key'] ?? null;
        if ($key !== null) {
            $this->assertReenrollmentAllowed($key, $since);
        }
        if ($sponsor !== null && ! in_array($sponsor->status, (array) config('lamess.sponsoring_statuses'), true)) {
            throw new NetworkException("Lo sponsor {$sponsor->code} non può sponsorizzare (stato: {$sponsor->status}).");
        }

        return DB::transaction(function () use ($data, $sponsor, $since, $status): Consultant {
            Consultant::$sponsorChangeAllowed = true;
            try {
                $c = Consultant::create($data + [
                    'sponsor_id' => $sponsor?->id, 'original_sponsor_id' => $sponsor?->id,
                    'status' => $status, 'since' => $since,
                ]);
            } finally {
                Consultant::$sponsorChangeAllowed = false;
            }
            $this->logStatus($c, null, $status, 'iscrizione', $since);
            $this->addPaths($c);

            return $c;
        });
    }

    public function changeStatus(Consultant $c, string $to, string $reason = '', ?DateTimeInterface $at = null, ?string $by = null): Consultant
    {
        $at ??= new DateTimeImmutable;
        if ($to === 'terminated') {
            return $this->terminate($c, $reason, $at, $by);
        }
        if (! in_array($to, self::TRANSITIONS[$c->status] ?? [], true)) {
            throw new NetworkException("Transizione non ammessa: {$c->status} → {$to}.");
        }
        $from = $c->status;
        $c->update(['status' => $to]);
        $this->logStatus($c, $from, $to, $reason, $at, $by);
        AuditLog::record('consultant.status', "Stato dell’incaricato {$c->code}: {$from} → {$to}", $c, ['stato' => ['prima' => $from, 'dopo' => $to]], $reason !== '' ? $reason : null);

        return $c;
    }

    /** Cessazione: i diretti passano al primo ascendente con contratto in essere; storico e closure aggiornati. */
    public function terminate(Consultant $c, string $reason, ?DateTimeInterface $at = null, ?string $by = null): Consultant
    {
        $at ??= new DateTimeImmutable;
        if ($c->status === 'terminated') {
            throw new NetworkException("L’incaricato {$c->code} è già cessato.");
        }

        return DB::transaction(function () use ($c, $reason, $at, $by): Consultant {
            $from = $c->status;
            $c->update(['status' => 'terminated', 'terminated_at' => $at, 'termination_reason' => $reason]);
            $this->logStatus($c, $from, 'terminated', $reason, $at, $by);

            $target = $this->effectiveSponsor($c);
            $periodId = Period::where('status', '!=', Period::CLOSED)->orderBy('code')->value('id');
            Consultant::$sponsorChangeAllowed = true;
            try {
                foreach (Consultant::where('sponsor_id', $c->id)->orderBy('id')->get() as $child) {
                    $child->update(['sponsor_id' => $target?->id]);
                    DB::table('sponsor_history')->insert([
                        'consultant_id' => $child->id, 'from_sponsor_id' => $c->id, 'to_sponsor_id' => $target?->id,
                        'reason' => 'compression', 'period_id' => $periodId, 'approved_by' => $by,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } finally {
                Consultant::$sponsorChangeAllowed = false;
            }
            $this->rebuildClosure();
            AuditLog::record('consultant.terminated', "Incaricato cessato: {$c->code}", $c, ['stato' => ['prima' => $from, 'dopo' => 'terminated'], 'diretti_ricollegati_a' => $target?->code], $reason !== '' ? $reason : null);

            return $c->refresh();
        });
    }

    /** Primo ascendente non cessato (null se la catena finisce). */
    public function effectiveSponsor(Consultant $c): ?Consultant
    {
        $seen = [$c->id => true];
        $p = $c->sponsor_id;
        while ($p !== null) {
            if (isset($seen[$p])) {
                throw new NetworkException('Ciclo nella rete.');
            }
            $seen[$p] = true;
            $parent = Consultant::find($p);
            if ($parent === null) {
                return null;
            }
            if ($parent->status !== 'terminated') {
                return $parent;
            }
            $p = $parent->sponsor_id;
        }

        return null;
    }

    public function assertReenrollmentAllowed(string $personKey, DateTimeInterface $at): void
    {
        $days = (int) config('lamess.reenrollment_days');
        $active = Consultant::where('person_key', $personKey)->where('status', '!=', 'terminated')->exists();
        if ($active) {
            throw new NetworkException('Esiste già un incarico in essere per questa persona.');
        }
        $last = Consultant::where('person_key', $personKey)->where('status', 'terminated')->max('terminated_at');
        if ($last !== null) {
            $eligible = (new DateTimeImmutable($last))->modify("+{$days} days");
            if ($at < $eligible) {
                throw new NetworkException("Re-iscrizione possibile solo dopo {$days} giorni dalla cessazione (dal {$eligible->format('d/m/Y')}).");
            }
        }
    }

    /** Percorsi del nuovo incaricato: se stesso (depth 0) + tutti gli ascendenti. */
    private function addPaths(Consultant $c): void
    {
        $rows = [['ancestor_id' => $c->id, 'descendant_id' => $c->id, 'depth' => 0, 'leg_root_id' => null]];
        foreach (DB::table('network_paths')->where('descendant_id', $c->sponsor_id ?? 0)->get() as $p) {
            $rows[] = [
                'ancestor_id' => $p->ancestor_id, 'descendant_id' => $c->id, 'depth' => $p->depth + 1,
                'leg_root_id' => $p->depth === 0 ? $c->id : $p->leg_root_id,
            ];
        }
        DB::table('network_paths')->insert($rows);
    }

    /** Ricostruisce l'intera closure table dalla colonna sponsor_id. */
    public function rebuildClosure(): void
    {
        $sponsor = Consultant::query()->pluck('sponsor_id', 'id')->all();
        $rows = [];
        foreach ($sponsor as $id => $_) {
            $rows[] = ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0, 'leg_root_id' => null];
            $child = $id;
            $parent = $sponsor[$id];
            $depth = 1;
            while ($parent !== null) {
                $rows[] = ['ancestor_id' => $parent, 'descendant_id' => $id, 'depth' => $depth, 'leg_root_id' => $child];
                $child = $parent;
                $parent = $sponsor[$parent] ?? null;
                $depth++;
            }
        }
        DB::transaction(function () use ($rows): void {
            DB::table('network_paths')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('network_paths')->insert($chunk);
            }
        });
    }

    private function logStatus(Consultant $c, ?string $from, string $to, string $reason, DateTimeInterface $at, ?string $by = null): void
    {
        DB::table('consultant_status_changes')->insert([
            'consultant_id' => $c->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
            'changed_by' => $by, 'changed_at' => $at, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
