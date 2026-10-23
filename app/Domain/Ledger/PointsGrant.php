<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Calc\KosmosCalculator;
use App\Domain\Engine\Support\Num;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\PeriodResolver;
use App\Domain\Periods\PeriodStateException;
use App\Models\Consultant;
use App\Models\PvMovement;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Assegnazione libera dei punti, riservata all'azienda: si sceglie il beneficiario, il punteggio e la causale, senza
 * bisogno di un acquisto di box o kit (per esempio la quota del consorzio Kadeo versata da un socio).
 *
 * Tre effetti sul Piano Incaricati:
 *  - {@see self::EFFECT_SCORE}: solo punteggio registrato, nessun effetto su qualifiche e compensi;
 *  - {@see self::EFFECT_CLIENT}: il cliente indicato vale come cliente del beneficiario per N mesi (qualifica e requisiti),
 *    senza compensi sulla vendita;
 *  - {@see self::EFFECT_PURCHASE}: come un acquisto di quell'importo in euro: cliente per N mesi e compensi sul V.A.P. del piano.
 *
 * Ogni assegnazione è un movimento del registro (tipo «grant») nel periodo aperto del mese della data indicata.
 */
final class PointsGrant
{
    public const TYPE = 'grant';

    public const EFFECT_SCORE = 'score';

    public const EFFECT_CLIENT = 'client';

    public const EFFECT_PURCHASE = 'purchase';

    /** Etichette brevi, mostrate come scelte nella finestra «Assegna punti». */
    public const EFFECTS = [
        self::EFFECT_SCORE => 'Solo registro punti',
        self::EFFECT_CLIENT => 'Vale come cliente',
        self::EFFECT_PURCHASE => 'Vale come un acquisto',
    ];

    /** Cosa succede davvero con ciascuna scelta, in parole semplici. */
    public const EFFECT_DESCRIPTIONS = [
        self::EFFECT_SCORE => 'I punti restano nel registro e nella scheda dell\'incaricato. Non cambiano né i clienti, né la qualifica, né i compensi.',
        self::EFFECT_CLIENT => 'La persona indicata conta come cliente del beneficiario per il numero di mesi scelto: serve per lo scaglione dei diretti, i requisiti e la qualifica. Non genera nessun compenso.',
        self::EFFECT_PURCHASE => 'Come se la persona avesse fatto un ordine dell\'importo indicato: conta come cliente per i mesi scelti e genera anche i compensi (diretti e indiretti) su quell\'importo.',
    ];

    public function __construct(private readonly PeriodResolver $periods) {}

    /**
     * @param  string  $points  punti da assegnare (maggiori di zero)
     * @param  string  $reason  causale
     * @param  User|null  $client  la persona che il piano conta come cliente (solo per gli effetti «cliente» e «acquisto»)
     * @param  string|null  $amount  importo in euro IVA inclusa (obbligatorio per l'effetto «acquisto»)
     * @param  int  $months  mesi di validità del cliente (ignorato se è indicata la scadenza)
     * @param  DateTimeInterface|null  $validUntil  «valido fino al»: la validità conta a mesi interi e finisce con l'ultimo giorno del mese scelto
     * @param  int  $clients  quanti clienti vale l'assegnazione
     *
     * @throws InvalidArgumentException|PeriodStateException
     */
    public function grant(
        Consultant $beneficiary,
        string $points,
        string $reason,
        string $effect = self::EFFECT_SCORE,
        ?User $client = null,
        ?string $amount = null,
        int $months = 1,
        int $clients = 1,
        ?DateTimeInterface $at = null,
        ?User $by = null,
        ?DateTimeInterface $validUntil = null,
    ): PvMovement {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Serve la causale.');
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('La causale può avere al massimo 255 caratteri.');
        }
        if (! is_numeric($points) || ! Num::of($points)->isPositive()) {
            throw new InvalidArgumentException('I punti devono essere maggiori di zero.');
        }
        if (! array_key_exists($effect, self::EFFECTS)) {
            throw new InvalidArgumentException('Effetto sul piano non valido.');
        }
        if ($beneficiary->status === 'terminated') {
            throw new InvalidArgumentException("L'incaricato {$beneficiary->code} è cessato: non può ricevere punti.");
        }

        $isClient = $effect !== self::EFFECT_SCORE;
        if ($isClient && $validUntil !== null) {
            $from = DateTimeImmutable::createFromInterface($at ?? new DateTimeImmutable);
            $months = KosmosCalculator::monthIndex(DateTimeImmutable::createFromInterface($validUntil)) - KosmosCalculator::monthIndex($from);
            if ($months < 1) {
                throw new InvalidArgumentException('Il cliente vale già nel mese della data indicata: scegli una scadenza in un mese successivo (la validità finisce con l\'ultimo giorno del mese scelto).');
            }
        }
        if ($isClient) {
            if ($client === null) {
                throw new InvalidArgumentException('Indica la persona che vale come cliente.');
            }
            if ($months < 1 || $months > 120) {
                throw new InvalidArgumentException('I mesi di validità vanno da 1 a 120.');
            }
            if ($clients < 1 || $clients > 100) {
                throw new InvalidArgumentException('Il numero di clienti va da 1 a 100.');
            }
        }
        if ($effect === self::EFFECT_PURCHASE && ($amount === null || ! is_numeric($amount) || ! Num::of($amount)->isPositive())) {
            throw new InvalidArgumentException('Per l\'effetto «come un acquisto» serve l\'importo in euro.');
        }

        $at ??= new DateTimeImmutable;
        try {
            $period = $this->periods->forDate($at);
        } catch (OrderException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $movement = PvLedger::record(
            $period, $beneficiary, $isClient ? 'pvf' : 'pvc', $points, $at,
            type: self::TYPE, excluded: ! $isClient, amount: $effect === self::EFFECT_PURCHASE ? $amount : null,
            vapAmount: $effect === self::EFFECT_CLIENT ? '0' : null,
            validityMonths: $isClient ? $months : null, clientCount: $isClient ? $clients : null, makesClient: $isClient,
            note: $reason, clientUserId: $isClient ? $client->id : null,
        );

        AuditLog::record(
            'points.granted', "Punti assegnati a {$beneficiary->code}: {$points} ({$reason})", $beneficiary,
            ['movimento' => $movement->id, 'punti' => $points, 'effetto' => $effect, 'cliente' => $client?->email, 'importo' => $amount, 'mesi' => $isClient ? $months : null, 'valido_fino_al' => $isClient ? $validUntil?->format('Y-m-d') : null, 'assegnati_da' => $by?->email],
            $reason,
        );

        return $movement;
    }

    /** Annulla un'assegnazione (periodo ancora aperto): il movimento non conta più, resta nel registro. */
    public function cancel(PvMovement $movement, string $reason): PvMovement
    {
        if ($movement->type !== self::TYPE) {
            throw new InvalidArgumentException('Non è un\'assegnazione libera di punti.');
        }
        if ($movement->status === 'cancelled') {
            throw new InvalidArgumentException('L\'assegnazione è già annullata.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Serve il motivo dell\'annullamento.');
        }
        $movement->update(['status' => 'cancelled']);
        AuditLog::record('points.cancelled', "Assegnazione di punti #{$movement->id} annullata", $movement->consultant, ['movimento' => $movement->id, 'punti' => (string) $movement->pv], trim($reason));

        return $movement;
    }

    /**
     * Punti assegnati a un incaricato, per la sua scheda (admin) e per la sua area.
     *
     * @return array{total: string, count: int, rows: list<array{id:int, date:string, points:string, reason:string, effect:string, client:?string, cancelled:bool}>}
     */
    public static function summaryFor(Consultant $c, int $limit = 15): array
    {
        $all = PvMovement::query()->where('consultant_id', $c->id)->where('type', self::TYPE);
        $total = (clone $all)->where('status', '<>', 'cancelled')->sum('pv');
        $rows = (clone $all)->orderByDesc('event_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (PvMovement $m): array => [
            'id' => $m->id, 'date' => $m->event_at->setTimezone(new \DateTimeZone('Europe/Rome'))->format('d/m/Y'),
            'points' => number_format((float) $m->pv, fmod((float) $m->pv, 1.0) === 0.0 ? 0 : 2, ',', '.'),
            'reason' => (string) $m->note, 'effect' => self::effectOf($m),
            'client' => $m->client_user_id === null ? null : User::withoutGlobalScopes()->find($m->client_user_id)?->name,
            'cancelled' => $m->status === 'cancelled',
        ])->all();

        return ['total' => number_format((float) $total, fmod((float) $total, 1.0) === 0.0 ? 0 : 2, ',', '.'), 'count' => (clone $all)->count(), 'rows' => $rows];
    }

    /** Descrizione breve dell'effetto di un movimento «grant». */
    public static function effectOf(PvMovement $m): string
    {
        if ($m->excluded_from_bonus || ! $m->makes_client) {
            return 'Solo punteggio';
        }
        $months = (int) ($m->validity_months ?? 1);
        $label = 'Cliente per '.$months.($months === 1 ? ' mese' : ' mesi');

        return $m->amount === null ? $label.', senza compensi' : $label.' e compensi';
    }
}
