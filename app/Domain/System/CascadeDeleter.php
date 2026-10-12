<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\Audit\AuditLog;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Eliminazione definitiva di un record con i dati che ne dipendono (solo super_admin, vedi App\Domain\Access\Superadmin).
 *
 * Segue le chiavi esterne del database: i record collegati con colonna obbligatoria vengono eliminati, quelli con
 * colonna facoltativa vengono solo scollegati (la colonna torna vuota). Il piano è calcolabile prima (plan()), così la
 * conferma mostra cosa succederà; tutto avviene in una transazione e finisce nel log di audit con i dati cancellati.
 * Il log di audit non ha chiavi esterne e non viene mai toccato.
 */
final class CascadeDeleter
{
    /** Tabelle che non si toccano mai. */
    private const PROTECTED_TABLES = ['audit_logs', 'migrations'];

    /** @var array<string, list<array{table: string, column: string, nullable: bool, on_delete: string}>>|null */
    private static ?array $referrers = null;

    /**
     * @return array{delete: array<string, int>, detach: array<string, int>, steps: list<array{op: string, table: string, column: string, ids: list<int|string>}>}
     */
    public static function plan(Model $record): array
    {
        self::guard($record);
        $steps = [];
        $delete = [];
        $detach = [];
        $seen = [];
        self::walk($record->getTable(), [$record->getKey()], $steps, $delete, $detach, $seen);

        return ['delete' => $delete, 'detach' => $detach, 'steps' => $steps];
    }

    /** Frase per la finestra di conferma. */
    public static function describe(Model $record): string
    {
        try {
            $p = self::plan($record);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $parts = [];
        foreach ($p['delete'] as $table => $n) {
            $parts[] = "{$n} in «".Str::headline($table).'»';
        }
        $text = 'L\'eliminazione è definitiva e non si può annullare.';
        if ($parts !== []) {
            $text .= ' Verranno eliminati anche i dati collegati: '.implode(', ', $parts).'.';
        }
        if ($p['detach'] !== []) {
            $text .= ' Verranno scollegati (non eliminati): '.implode(', ', array_map(fn ($t, $n) => "{$n} in «".Str::headline($t).'»', array_keys($p['detach']), $p['detach'])).'.';
        }

        return $text;
    }

    public static function hasDependents(Model $record): bool
    {
        try {
            $p = self::plan($record);
        } catch (DomainException) {
            return false;
        }

        return $p['delete'] !== [] || $p['detach'] !== [];
    }

    /** @return array{delete: array<string, int>, detach: array<string, int>} cosa è stato eliminato/scollegato oltre al record */
    public static function delete(Model $record, ?string $reason = null): array
    {
        $plan = self::plan($record);
        $snapshot = $record->attributesToArray();
        $label = class_basename($record).' #'.$record->getKey();

        DB::transaction(function () use ($record, $plan, $snapshot, $label, $reason): void {
            // dal più lontano al più vicino al record: nessuna chiave esterna resta pendente
            foreach (array_reverse($plan['steps']) as $s) {
                foreach (array_chunk($s['ids'], 500) as $chunk) {
                    $q = DB::table($s['table'])->whereIn($s['column'], $chunk);
                    $s['op'] === 'delete' ? $q->delete() : $q->update([$s['column'] => null]);
                }
            }
            $record->delete();
            AuditLog::record('superadmin.deleted', "Eliminazione definitiva (super_admin): {$label}", $record, [
                'dati_eliminati' => $snapshot,
                'collegati_eliminati' => $plan['delete'],
                'collegati_scollegati' => $plan['detach'],
            ], $reason);
        });

        return ['delete' => $plan['delete'], 'detach' => $plan['detach']];
    }

    /** Regole di buon senso che nemmeno il super_admin deve scavalcare per sbaglio. */
    private static function guard(Model $record): void
    {
        if ($record instanceof User) {
            if ($record->isSuperAdmin()) {
                throw new DomainException('Un account super_admin non si elimina dal pannello (si gestisce da riga di comando).');
            }
            if (Auth::id() === $record->getKey()) {
                throw new DomainException('Non puoi eliminare il tuo stesso account.');
            }
        }
        if ($record instanceof Consultant && Consultant::query()->where('sponsor_id', $record->getKey())->exists()) {
            throw new DomainException('Questo incaricato ha incaricati diretti nella sua struttura: spostali prima (o cessa l\'incaricato) per non rompere l\'albero della rete.');
        }
        if ($record instanceof CompPlanVersion && $record->status === CompPlanVersion::ACTIVE) {
            throw new DomainException('La versione attiva del piano non si elimina: attiva prima un\'altra versione.');
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @param  list<array{op: string, table: string, column: string, ids: list<int|string>}>  $steps
     * @param  array<string, int>  $delete
     * @param  array<string, int>  $detach
     * @param  array<string, true>  $seen
     */
    private static function walk(string $table, array $ids, array &$steps, array &$delete, array &$detach, array &$seen): void
    {
        foreach (self::referrers()[$table] ?? [] as $ref) {
            $childIds = [];
            $hasId = Schema::hasColumn($ref['table'], 'id');
            $base = DB::table($ref['table'])->where(fn ($q) => $q->whereIn($ref['column'], $ids));
            if ($hasId) {
                $childIds = $base->pluck('id')->all();
                $childIds = array_values(array_filter($childIds, fn ($id) => ! isset($seen[$ref['table'].':'.$id])));
                $count = count($childIds);
            } else {
                $count = $base->count();
            }
            if ($count === 0) {
                continue;
            }
            $removes = $ref['on_delete'] === 'cascade' || ! $ref['nullable'];
            if ($ref['on_delete'] === 'set null') {
                $removes = false;
            }
            if ($removes) {
                foreach ($childIds as $id) {
                    $seen[$ref['table'].':'.$id] = true;
                }
                $steps[] = ['op' => 'delete', 'table' => $ref['table'], 'column' => $hasId ? 'id' : $ref['column'], 'ids' => $hasId ? $childIds : $ids];
                $delete[$ref['table']] = ($delete[$ref['table']] ?? 0) + $count;
                if ($hasId) {
                    self::walk($ref['table'], $childIds, $steps, $delete, $detach, $seen);
                }
            } else {
                $steps[] = ['op' => 'nullify', 'table' => $ref['table'], 'column' => $ref['column'], 'ids' => $ids];
                $detach[$ref['table']] = ($detach[$ref['table']] ?? 0) + $count;
            }
        }
    }

    /** @return array<string, list<array{table: string, column: string, nullable: bool, on_delete: string}>> tabella referenziata → chi la referenzia */
    private static function referrers(): array
    {
        if (self::$referrers !== null) {
            return self::$referrers;
        }
        $map = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::PROTECTED_TABLES, true)) {
                continue;
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    continue;
                }
                $col = $fk['columns'][0];
                $map[$fk['foreign_table']][] = [
                    'table' => $table,
                    'column' => $col,
                    'nullable' => (bool) ($columns[$col]['nullable'] ?? false),
                    'on_delete' => strtolower((string) ($fk['on_delete'] ?? 'no action')),
                ];
            }
        }

        return self::$referrers = $map;
    }

    /** Per i test: dopo una migrazione le chiavi esterne possono essere cambiate. */
    public static function flush(): void
    {
        self::$referrers = null;
    }
}
