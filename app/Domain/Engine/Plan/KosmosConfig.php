<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tabelle del piano «Incaricati» (presentazioni «Lamèss Incaricati» e «Seconda parte»).
 * Nessun valore è nel codice di calcolo: tutto è modificabile dal pannello del piano.
 *
 * Le percentuali sono frazioni (0.10 = 10%); nel JSON sono punti percentuali (10 = 10%).
 */
final readonly class KosmosConfig
{
    /**
     * @param  list<array{min_clients:int,rate:BigDecimal}>  $directTiers  scaglioni dei compensi diretti (per numero di clienti)
     * @param  list<array{level:int,rate:BigDecimal,min_clients:int,min_pearl:int}>  $indirectLevels
     * @param  array<string, BigDecimal>  $structureBonus  rank => bonus € (PearlQ)
     * @param  array<string, BigDecimal>  $extendedRates  rank => aliquota sui livelli oltre l'ultimo
     * @param  string  $clientRule  any_order = basta un ordine · min_amount = un ordine di almeno $clientMinAmount € (IVA inclusa)
     * @param  string|null  $rankGraceUntil  data (Y-m-d) fino a cui le qualifiche non si riducono per mancanza di clienti; null = nessuna protezione
     * @param  int|null  $rankGraceDays  giorni dall'attivazione del piano in cui le qualifiche non si riducono; null o 0 = nessuna protezione a giorni
     * @param  string|null  $activationDate  giorno (Y-m-d) in cui il piano diventa efficace: lo aggiunge la versione del piano, non è nel JSON
     */
    public function __construct(
        public BigDecimal $vapShare,
        public array $directTiers,
        public array $indirectLevels,
        public array $structureBonus,
        public int $structureClients,
        public int $structureWindowDays,
        public array $extendedRates,
        public int $extendedStopLevels,
        public ?int $extendedRequiresLevel,
        public string $pearlRank,
        public string $clientRule,
        public BigDecimal $clientMinAmount,
        public ?string $rankGraceUntil,
        public ?int $rankGraceDays = null,
        public ?string $activationDate = null,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->directTiers === []) {
            throw new InvalidArgumentException('Il piano Incaricati richiede almeno uno scaglione dei compensi diretti');
        }
        $prev = -1;
        foreach ($this->directTiers as $t) {
            if ($t['min_clients'] <= $prev) {
                throw new InvalidArgumentException('Gli scaglioni dei diretti devono avere clienti minimi crescenti');
            }
            $prev = $t['min_clients'];
        }
        $lv = 0;
        foreach ($this->indirectLevels as $l) {
            if ($l['level'] !== ++$lv) {
                throw new InvalidArgumentException('I livelli degli indiretti devono essere 1, 2, 3… consecutivi');
            }
        }
        if ($this->extendedRequiresLevel !== null && ($this->extendedRequiresLevel < 1 || $this->extendedRequiresLevel > $this->maxLevel())) {
            throw new InvalidArgumentException('Livello di requisiti degli indiretti estesi inesistente');
        }
        if ($this->structureClients < 1 || $this->structureWindowDays < 1) {
            throw new InvalidArgumentException('PearlQ: clienti e giorni devono essere positivi');
        }
        if (! in_array($this->clientRule, [self::CLIENT_ANY_ORDER, self::CLIENT_MIN_AMOUNT], true)) {
            throw new InvalidArgumentException("Regola cliente sconosciuta: {$this->clientRule}");
        }
        if ($this->clientMinAmount->isNegative()) {
            throw new InvalidArgumentException('L’importo minimo del cliente non può essere negativo');
        }
        if ($this->rankGraceUntil !== null) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $this->rankGraceUntil);
            if ($d === false || $d->format('Y-m-d') !== $this->rankGraceUntil) {
                throw new InvalidArgumentException('Data di mantenimento delle qualifiche non valida (usa AAAA-MM-GG)');
            }
        }
        if ($this->rankGraceDays !== null && ($this->rankGraceDays < 0 || $this->rankGraceDays > 3650)) {
            throw new InvalidArgumentException('I giorni di mantenimento delle qualifiche devono essere tra 0 e 3650');
        }
        if ($this->activationDate !== null) {
            $a = DateTimeImmutable::createFromFormat('!Y-m-d', $this->activationDate);
            if ($a === false || $a->format('Y-m-d') !== $this->activationDate) {
                throw new InvalidArgumentException('Data di attivazione del piano non valida (usa AAAA-MM-GG)');
            }
        }
    }

    public const CLIENT_ANY_ORDER = 'any_order';

    public const CLIENT_MIN_AMOUNT = 'min_amount';

    /**
     * Un ordine di questo importo (€, IVA inclusa) rende cliente? Due vie alternative:
     * il totale dell'ordine raggiunge l'importo minimo del piano (con «qualunque ordine» basta che sia positivo),
     * oppure l'ordine contiene un prodotto con l'interruttore «Rende cliente» (a prescindere dall'importo).
     */
    public function orderQualifies(BigDecimal $orderAmount, bool $hasClientProduct = false): bool
    {
        if (! $orderAmount->isPositive()) {
            return false;
        }

        return $hasClientProduct || $this->clientRule === self::CLIENT_ANY_ORDER || $orderAmount->isGreaterThanOrEqualTo($this->clientMinAmount);
    }

    /**
     * Ultimo giorno (Y-m-d) di protezione delle qualifiche attuali, o null se non c'è protezione.
     *
     * Vale la più lontana tra la data scelta dall'admin e la fine dei giorni dall'attivazione del piano. I giorni partono
     * dal giorno di attivazione (compreso) e si arrotondano a fine mese: ogni mese iniziato dentro la finestra è protetto
     * per intero (90 giorni dal 1° novembre = novembre, dicembre e gennaio).
     */
    public function rankGraceEnd(): ?string
    {
        $end = $this->rankGraceUntil;
        if ($this->rankGraceDays !== null && $this->rankGraceDays > 0 && $this->activationDate !== null) {
            $last = (new DateTimeImmutable($this->activationDate))->modify('+'.($this->rankGraceDays - 1).' days')->modify('last day of this month')->format('Y-m-d');
            $end = $end === null || $last > $end ? $last : $end;
        }

        return $end;
    }

    /**
     * Le qualifiche del periodo sono protette: l'ultimo giorno del mese cade entro la fine della protezione ({@see rankGraceEnd()}).
     *
     * @param  DateTimeImmutable  $periodEnd  fine esclusiva del periodo (inizio del mese successivo), come {@see PeriodKey::end()}
     */
    public function ranksProtectedIn(DateTimeImmutable $periodEnd): bool
    {
        $end = $this->rankGraceEnd();

        return $end !== null && $periodEnd->modify('-1 day')->format('Y-m-d') <= $end;
    }

    public function maxLevel(): int
    {
        return count($this->indirectLevels);
    }

    /** Aliquota dei diretti per numero di clienti (scaglione intero raggiunto). */
    public function directRate(int $clients): BigDecimal
    {
        $rate = Num::zero();
        foreach ($this->directTiers as $t) {
            if ($clients >= $t['min_clients']) {
                $rate = $t['rate'];
            }
        }

        return $rate;
    }

    /** @return array{level:int,rate:BigDecimal,min_clients:int,min_pearl:int}|null */
    public function level(int $n): ?array
    {
        return $this->indirectLevels[$n - 1] ?? null;
    }

    public function structureBonusFor(string $rankCode): BigDecimal
    {
        return $this->structureBonus[$rankCode] ?? Num::zero();
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $tiers = [];
        foreach ($d['direct_tiers'] ?? [] as $t) {
            $tiers[] = ['min_clients' => (int) $t['min_clients'], 'rate' => Num::pct((string) $t['pct'])];
        }
        usort($tiers, static fn (array $a, array $b) => $a['min_clients'] <=> $b['min_clients']);
        $levels = [];
        foreach ($d['indirect_levels'] ?? [] as $l) {
            $levels[] = [
                'level' => (int) $l['level'], 'rate' => Num::pct((string) $l['pct']),
                'min_clients' => (int) ($l['min_clients'] ?? 0), 'min_pearl' => (int) ($l['min_pearl'] ?? 0),
            ];
        }
        usort($levels, static fn (array $a, array $b) => $a['level'] <=> $b['level']);
        $bonus = [];
        foreach ($d['structure_bonus'] ?? [] as $code => $amount) {
            $bonus[(string) $code] = Num::of((string) $amount);
        }
        $ext = [];
        foreach ($d['extended_pct'] ?? [] as $code => $pct) {
            $ext[(string) $code] = Num::pct((string) $pct);
        }

        return new self(
            vapShare: Num::of((string) ($d['vap_share_pct'] ?? '66.66666'))->dividedBy(100, 10, RoundingMode::HalfUp),
            directTiers: $tiers, indirectLevels: $levels, structureBonus: $bonus,
            structureClients: (int) ($d['structure_clients'] ?? 3),
            structureWindowDays: (int) ($d['structure_window_days'] ?? 30),
            extendedRates: $ext,
            extendedStopLevels: (int) ($d['extended_stop_levels'] ?? 5),
            extendedRequiresLevel: isset($d['extended_requires_level']) && $d['extended_requires_level'] !== '' ? (int) $d['extended_requires_level'] : null,
            pearlRank: (string) ($d['pearl_rank'] ?? 'PEARL'),
            clientRule: (string) ($d['client_rule'] ?? self::CLIENT_MIN_AMOUNT),
            clientMinAmount: Num::of((string) ($d['client_min_amount'] ?? '150')),
            rankGraceUntil: isset($d['rank_grace_until']) && trim((string) $d['rank_grace_until']) !== '' ? trim((string) $d['rank_grace_until']) : null,
            rankGraceDays: isset($d['rank_grace_days']) && trim((string) $d['rank_grace_days']) !== '' ? (int) $d['rank_grace_days'] : null,
            activationDate: isset($d['activation_date']) && trim((string) $d['activation_date']) !== '' ? trim((string) $d['activation_date']) : null,
        );
    }
}
