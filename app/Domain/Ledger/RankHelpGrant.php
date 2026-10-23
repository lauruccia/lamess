<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\PeriodResolver;
use App\Domain\Periods\PeriodStateException;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\RankHelp;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * «Agenti regalati»: l'azienda aggiunge N agenti di una qualifica sotto un incaricato per aiutarlo a qualificarsi
 * (per esempio 2 Pearl sotto un Pearl per fargli raggiungere Crystal).
 *
 * Gli agenti sono virtuali: contano al 1° livello, in colonne diverse, solo per i requisiti della QUALIFICA
 * (Pearl al 1° livello e struttura di altri incaricati). Non generano compensi, non sono clienti, non entrano nei
 * requisiti dei livelli indiretti e non compaiono nella rete. Valgono dalla data indicata fino alla scadenza (vuota = finché
 * non vengono annullati).
 */
final class RankHelpGrant
{
    public const MAX_QUANTITY = 50;

    public function __construct(private readonly PeriodResolver $periods) {}

    /**
     * Qualifiche che si possono regalare (tutte tranne quella d'ingresso) del piano in vigore nel mese.
     *
     * @return array<string, string> codice => nome
     */
    public static function rankOptions(?DateTimeInterface $at = null): array
    {
        $at ??= new DateTimeImmutable;
        $plan = CompPlanVersion::inForceForPeriod($at->format('Y-m'))?->plan();
        if (! $plan instanceof PlanConfig) {
            return [];
        }
        $out = [];
        for ($s = 1; $plan->hasSeq($s); $s++) {
            $r = $plan->bySeq($s);
            $out[$r->code] = $r->name;
        }

        return $out;
    }

    /** @throws InvalidArgumentException|PeriodStateException */
    public function add(
        Consultant $beneficiary,
        string $rankCode,
        int $quantity,
        string $reason,
        ?DateTimeInterface $validUntil = null,
        ?DateTimeInterface $at = null,
        ?User $by = null,
    ): RankHelp {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Serve la causale.');
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('La causale può avere al massimo 255 caratteri.');
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('Il numero di agenti va da 1 a '.self::MAX_QUANTITY.'.');
        }
        if ($beneficiary->status === 'terminated') {
            throw new InvalidArgumentException("L'incaricato {$beneficiary->code} è cessato: non può ricevere agenti.");
        }
        $at ??= new DateTimeImmutable;
        try {
            $period = $this->periods->forDate($at);
        } catch (OrderException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
        if (! array_key_exists($rankCode, self::rankOptions($at))) {
            throw new InvalidArgumentException('Qualifica non valida per il piano in vigore.');
        }
        if ($validUntil !== null && $validUntil->format('Y-m-d') < $at->format('Y-m-d')) {
            throw new InvalidArgumentException('La scadenza non può essere prima della data di partenza.');
        }

        $help = RankHelp::create([
            'consultant_id' => $beneficiary->id, 'rank_code' => $rankCode, 'quantity' => $quantity, 'reason' => $reason,
            'starts_at' => DateTimeImmutable::createFromInterface($at), 'valid_until' => $validUntil?->format('Y-m-d'),
            'status' => 'confirmed', 'created_by' => $by?->id,
        ]);
        AuditLog::record(
            'rank_help.added', "Agenti regalati a {$beneficiary->code}: {$quantity} × {$rankCode} ({$reason})", $beneficiary,
            ['aiuto' => $help->id, 'qualifica' => $rankCode, 'numero' => $quantity, 'scadenza' => $validUntil?->format('Y-m-d'), 'periodo' => $period->code, 'assegnati_da' => $by?->email],
            $reason,
        );

        return $help;
    }

    public function cancel(RankHelp $help, string $reason): RankHelp
    {
        if ($help->status === 'cancelled') {
            throw new InvalidArgumentException('Gli agenti regalati sono già annullati.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Serve il motivo dell\'annullamento.');
        }
        $help->update(['status' => 'cancelled']);
        AuditLog::record('rank_help.cancelled', "Agenti regalati #{$help->id} annullati", $help->consultant, ['aiuto' => $help->id, 'qualifica' => $help->rank_code, 'numero' => $help->quantity], trim($reason));

        return $help;
    }

    /** Descrizione breve per elenchi e riepiloghi. */
    public static function label(string $rankCode, int $quantity): string
    {
        $name = self::rankOptions()[$rankCode] ?? $rankCode;

        return $quantity.' '.($quantity === 1 ? 'agente' : 'agenti').' '.$name;
    }
}
