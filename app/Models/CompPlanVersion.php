<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Canonical;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class CompPlanVersion extends Model
{
    protected $guarded = [];

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    /** Attiva ma con efficacia futura: non sostituisce ancora la versione in vigore. */
    public const SCHEDULED = 'scheduled';

    /** Pubblicazione richiesta: attende l'approvazione di un secondo amministratore (quattro occhi). */
    public const PENDING_APPROVAL = 'pending_approval';

    protected function casts(): array
    {
        return ['data' => 'array', 'activated_at' => 'immutable_datetime', 'effective_from' => 'immutable_date:Y-m-d', 'requested_at' => 'immutable_datetime', 'requested_effective_from' => 'immutable_date:Y-m-d'];
    }

    /**
     * Piano di calcolo del motore costruito dai dati salvati. Al piano Incaricati si aggiunge il giorno di attivazione
     * della versione (efficace dal, altrimenti giorno di attivazione): serve ai giorni di mantenimento delle qualifiche.
     */
    public function plan(): PlanConfig
    {
        $data = $this->data;
        $activation = ($this->effective_from ?? $this->activated_at)?->format('Y-m-d');
        if ($activation !== null && isset($data['kosmos'])) {
            $data['kosmos']['activation_date'] = $activation;
        }

        return PlanConfig::fromArray($data);
    }

    /** Crea una versione dai dati del piano, validandoli con il motore. */
    public static function createFromArray(string $name, array $data, string $status = self::DRAFT): self
    {
        PlanConfig::fromArray($data); // lancia se i dati non sono validi

        return self::create([
            'name' => $name, 'status' => $status, 'data' => $data, 'content_hash' => Canonical::hash($data),
            'activated_at' => $status === self::ACTIVE ? now() : null,
        ]);
    }

    /** Versione in vigore il primo giorno del mese «AAAA-MM» (il piano di un periodo non cambia a metà mese). */
    public static function inForceForPeriod(string $code): ?self
    {
        return self::inForce(new \DateTimeImmutable($code.'-01'));
    }

    /**
     * Versione del piano in vigore a una data: la più recente tra le attive/programmate con efficacia già iniziata.
     * Le versioni senza data di efficacia (importate prima della fase 2) valgono dal giorno di attivazione.
     */
    public static function inForce(?\DateTimeInterface $at = null): ?self
    {
        $day = ($at ? CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($at)) : now())->format('Y-m-d');

        return self::whereIn('status', [self::ACTIVE, self::SCHEDULED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $day))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
