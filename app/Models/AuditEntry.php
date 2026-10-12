<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Riga del log di audit. SOLA LETTURA: si scrive esclusivamente con App\Domain\Audit\AuditLog::record().
 * Ogni tentativo di modificare o cancellare una riga tramite il modello solleva un'eccezione.
 */
class AuditEntry extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(static fn () => throw new LogicException('Il log di audit si scrive solo con AuditLog::record().'));
        static::updating(static fn () => throw new LogicException('Il log di audit è in sola scrittura: le righe non si modificano.'));
        static::deleting(static fn () => throw new LogicException('Il log di audit è in sola scrittura: le righe non si cancellano.'));
    }

    /** @return array<string, mixed>|null */
    public function changesArray(): ?array
    {
        return $this->changes === null ? null : json_decode((string) $this->changes, true);
    }
}
