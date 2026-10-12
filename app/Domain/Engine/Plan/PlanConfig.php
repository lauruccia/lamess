<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

use App\Domain\Engine\Policy\Policies;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Piano compensi (una versione). Contiene TUTTI i valori usati dal motore:
 * nessuna percentuale, soglia o importo è scritto nel codice di calcolo.
 */
final class PlanConfig
{
    /** Parametri scalari: chiave => [etichetta, default (stringa), fonte]. */
    public const SETTINGS = [
        'activity_threshold' => ['Soglia punti per lo stato ATTIVO', '100', 'PMK p.14'],
        'commission_base_per_pv' => ['Euro di base provvigionale per 1 PV/CV', '1', 'PMK p.25–28 (8.000 PV × 9% = 720 €)'],
        'fifty_rule_share' => ['Quota massima di una linea (regola del 50%)', '0.5', 'PMK p.19–20'],
        'indirect_min_rank' => ['Qualifica minima per le commissioni indirette', 'PEARL', 'PMK p.24'],
        'matching_min_rank' => ['Qualifica minima per il Matching', 'SAPPHIRE', 'PMK p.29'],
        'rank_bonus_installment_threshold' => ['Soglia oltre cui il Rank Bonus è rateizzato (€)', '50000', 'PMK p.32'],
        'rank_bonus_installments' => ['Numero di rate del Rank Bonus', '12', 'PMK p.32'],
        'rank_bonus_hold_months' => ['Mesi di trattenuta del Rank Bonus da inattivo', '3', 'OPEN-06'],
        'fsb_enabled' => ['Fast Start Bonus attivo', 'true', 'PMK 070726 p.33–35'],
        'payout_mode' => ['Pagamento delle provvigioni (monthly_auto | on_request)', 'monthly_auto', 'PMK 070726 p.14: bonifico mensile entro il 15 del mese successivo (Reg. 6.1.a: su richiesta) — OPEN-33'],
        'payout_day' => ['Giorno del mese successivo entro cui si paga', '15', 'PMK 070726 p.14'],
        'min_payout' => ['Pagamento minimo (€)', '15', 'lamess.config (Odoo): Minimum Payout'],
        'scn_threshold' => ['Soglia SCN (€)', '5000', 'lamess.config (Odoo): SCN Threshold — significato da chiarire'],
        'engine' => ['Motore di calcolo (multilevel_v3 | kosmos_v1)', 'multilevel_v3', 'multilevel_v3 = piano Incaricati (Direct/Team/Matching/Rank Bonus/Fast Start); kosmos_v1 = piano Incaricati (presentazioni 08/10/2026). Con kosmos_v1 servono le tabelle «Piano Incaricati»'],
        'move_node_window_days' => ['Finestra per spostare un incaricato nell\'albero (giorni)', '7', 'lamess.config (Odoo): Move Node Window — significato da chiarire'],
    ];

    public readonly BigDecimal $activityThreshold;

    public readonly BigDecimal $commissionBasePerPv;

    public readonly BigDecimal $fiftyRuleShare;

    public readonly BigDecimal $rankBonusInstallmentThreshold;

    public readonly BigDecimal $minPayout;

    public readonly BigDecimal $scnThreshold;

    /**
     * @param  list<RankDef>  $ranks
     * @param  array<string, list<BigDecimal>>  $matching  rank => aliquote per livello (frazioni)
     * @param  list<FsbStepDef>  $fsbSteps
     */
    public function __construct(
        public readonly string $label,
        public readonly array $ranks,
        public readonly array $matching,
        public readonly array $fsbSteps,
        public readonly Policies $policies,
        BigDecimal|string $activityThreshold = '100',
        BigDecimal|string $commissionBasePerPv = '1',
        BigDecimal|string $fiftyRuleShare = '0.5',
        public readonly string $indirectMinRank = 'PEARL',
        public readonly string $matchingMinRank = 'SAPPHIRE',
        BigDecimal|string $rankBonusInstallmentThreshold = '50000',
        public readonly int $rankBonusInstallments = 12,
        public readonly int $rankBonusHoldMonths = 3,
        public readonly bool $fsbEnabled = true,
        BigDecimal|string $minPayout = '15',
        BigDecimal|string $scnThreshold = '5000',
        public readonly int $moveNodeWindowDays = 7,
        public readonly string $payoutMode = 'monthly_auto',
        public readonly int $payoutDay = 15,
        public readonly ?KosmosConfig $kosmos = null,
    ) {
        $this->minPayout = Num::of($minPayout);
        $this->scnThreshold = Num::of($scnThreshold);
        $this->activityThreshold = Num::of($activityThreshold);
        $this->commissionBasePerPv = Num::of($commissionBasePerPv);
        $this->fiftyRuleShare = Num::of($fiftyRuleShare);
        $this->rankBonusInstallmentThreshold = Num::of($rankBonusInstallmentThreshold);
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->ranks === []) {
            throw new InvalidArgumentException('Il piano non ha rank');
        }
        $seqs = array_map(static fn (RankDef $r) => $r->sequence, $this->ranks);
        $sorted = $seqs;
        sort($sorted);
        if ($seqs !== $sorted || count(array_unique($seqs)) !== count($seqs)) {
            throw new InvalidArgumentException('I rank devono avere sequenze uniche e ordinate');
        }
        if (! $this->ranks[0]->prRequired->isZero()) {
            throw new InvalidArgumentException("Il primo rank deve essere quello d'ingresso (0 PR)");
        }
        $codes = array_map(static fn (RankDef $r) => $r->code, $this->ranks);
        foreach ($this->ranks as $r) {
            foreach ($r->structure as $req) {
                if (! in_array($req->rankCode, $codes, true)) {
                    throw new InvalidArgumentException("Requisito {$req->rankCode} sconosciuto in {$r->code}");
                }
            }
        }
        if (! in_array($this->payoutMode, ['monthly_auto', 'on_request'], true)) {
            throw new InvalidArgumentException("Modalità di pagamento sconosciuta: {$this->payoutMode}");
        }
        if ($this->payoutDay < 1 || $this->payoutDay > 28) {
            throw new InvalidArgumentException('Il giorno di pagamento deve essere tra 1 e 28');
        }
        if ($this->kosmos !== null) {
            foreach (array_merge(array_keys($this->kosmos->structureBonus), array_keys($this->kosmos->extendedRates), [$this->kosmos->pearlRank]) as $code) {
                if (! in_array($code, $codes, true)) {
                    throw new InvalidArgumentException("Rank {$code} sconosciuto nella sezione kosmos");
                }
            }
        }
        foreach ([$this->indirectMinRank, $this->matchingMinRank] as $code) {
            if (! in_array($code, $codes, true)) {
                throw new InvalidArgumentException("Rank {$code} sconosciuto nei parametri del piano");
            }
        }
    }

    public function engine(): string
    {
        return $this->kosmos === null ? 'multilevel_v3' : 'kosmos_v1';
    }

    public function rank(string $code): RankDef
    {
        foreach ($this->ranks as $r) {
            if ($r->code === $code) {
                return $r;
            }
        }
        throw new OutOfBoundsException("Rank sconosciuto: {$code}");
    }

    public function hasCode(string $code): bool
    {
        foreach ($this->ranks as $r) {
            if ($r->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function bySeq(int $seq): RankDef
    {
        foreach ($this->ranks as $r) {
            if ($r->sequence === $seq) {
                return $r;
            }
        }
        throw new OutOfBoundsException("Sequenza rank sconosciuta: {$seq}");
    }

    public function hasSeq(int $seq): bool
    {
        foreach ($this->ranks as $r) {
            if ($r->sequence === $seq) {
                return true;
            }
        }

        return false;
    }

    public function entryRank(): RankDef
    {
        return $this->ranks[0];
    }

    public function maxDirectRate(): BigDecimal
    {
        $max = Num::zero();
        foreach ($this->ranks as $r) {
            $max = Num::max($max, $r->directRate);
        }

        return $max;
    }

    public function matchingRate(string $rankCode, int $level): BigDecimal
    {
        $rates = $this->matching[$rankCode] ?? [];

        return ($level > 0 && $level <= count($rates)) ? $rates[$level - 1] : Num::zero();
    }

    public function matchingDepth(): int
    {
        $max = 0;
        foreach ($this->matching as $rates) {
            $max = max($max, count($rates));
        }

        return $max;
    }

    /** Copia con politiche diverse (simulazioni). @param array<string,string|bool> $overrides */
    public function withPolicies(array $overrides): self
    {
        return new self(
            $this->label, $this->ranks, $this->matching, $this->fsbSteps, $this->policies->with($overrides),
            $this->activityThreshold, $this->commissionBasePerPv, $this->fiftyRuleShare,
            $this->indirectMinRank, $this->matchingMinRank, $this->rankBonusInstallmentThreshold,
            $this->rankBonusInstallments, $this->rankBonusHoldMonths, $this->fsbEnabled,
            $this->minPayout, $this->scnThreshold, $this->moveNodeWindowDays, $this->payoutMode, $this->payoutDay, $this->kosmos,
        );
    }

    /**
     * Costruzione da array (file JSON del piano o tabelle plan_*). Le percentuali sono
     * espresse in punti percentuali (18 = 18%) come nel PMK.
     *
     * @param  array<string, mixed>  $d
     */
    /** Nomi qualifica senza la vecchia dicitura «Consulente» (piani salvati prima del cambio in «Incaricato»). */
    private static function cleanRankName(string $n): string
    {
        return $n === 'Consulente' ? 'Incaricato' : preg_replace('/^Consulente /', '', $n);
    }

    public static function fromArray(array $d): self
    {
        $ranks = [];
        foreach ($d['ranks'] as $r) {
            $ranks[] = new RankDef(
                code: $r['code'], name: self::cleanRankName((string) $r['name']), sequence: (int) $r['sequence'],
                prRequired: Num::of((string) $r['pr_required']),
                fiftyPercentRule: (bool) $r['fifty_percent_rule'],
                directRate: Num::pct((string) $r['direct_pct']),
                rankBonus: Num::of((string) ($r['rank_bonus'] ?? 0)),
                structure: array_map(
                    static fn (array $s) => new StructureReq($s['rank'], (int) $s['count']),
                    $r['structure'] ?? [],
                ),
                source: (string) ($r['source'] ?? ''),
                minClients: (int) ($r['min_clients'] ?? 0),
                minPearlFirstLevel: (int) ($r['min_pearl_first_level'] ?? 0),
                minColumns: (int) ($r['min_columns'] ?? 0),
                minColumnClients: (int) ($r['min_column_clients'] ?? 0),
                disabled: array_values(array_map('strval', $r['disabled_requirements'] ?? [])),
            );
        }
        $matching = [];
        foreach (($d['matching_pct'] ?? []) as $code => $levels) {
            $matching[$code] = array_map(static fn ($p) => Num::pct((string) $p), $levels);
        }
        $fsb = [];
        foreach (($d['fsb_steps'] ?? []) as $s) {
            $fsb[] = new FsbStepDef(
                number: (int) $s['step'], days: isset($s['days']) ? (int) $s['days'] : null,
                directsRequired: (int) ($s['directs_required'] ?? 0),
                directMinPv: Num::of((string) ($s['direct_min_pv'] ?? 0)),
                pvTarget: Num::of((string) ($s['pv_target'] ?? 0)),
                requiredRankCode: $s['required_rank'] ?? null,
                fiftyPercentRule: (bool) ($s['fifty_percent_rule'] ?? false),
                amount: Num::of((string) $s['amount']),
                kind: $s['kind'] ?? 'standard',
                legsRequired: (int) ($s['legs_required'] ?? 0),
                legsStepNumber: (int) ($s['legs_step_number'] ?? 0),
                source: (string) ($s['source'] ?? ''),
            );
        }
        $st = $d['settings'] ?? [];
        $get = static fn (string $k) => (string) ($st[$k] ?? self::SETTINGS[$k][1]);
        $engine = $get('engine');
        if (! in_array($engine, ['multilevel_v3', 'kosmos_v1'], true)) {
            throw new InvalidArgumentException("Motore di calcolo sconosciuto: {$engine}");
        }
        if ($engine === 'kosmos_v1' && empty($d['kosmos'])) {
            throw new InvalidArgumentException('Il motore kosmos_v1 richiede le tabelle del piano Incaricati (sezione kosmos)');
        }

        return new self(
            label: $d['label'], ranks: $ranks, matching: $matching, fsbSteps: $fsb,
            policies: Policies::fromArray($d['policies'] ?? null),
            activityThreshold: $get('activity_threshold'),
            commissionBasePerPv: $get('commission_base_per_pv'),
            fiftyRuleShare: $get('fifty_rule_share'),
            indirectMinRank: $get('indirect_min_rank'),
            matchingMinRank: $get('matching_min_rank'),
            rankBonusInstallmentThreshold: $get('rank_bonus_installment_threshold'),
            rankBonusInstallments: (int) $get('rank_bonus_installments'),
            rankBonusHoldMonths: (int) $get('rank_bonus_hold_months'),
            fsbEnabled: filter_var($get('fsb_enabled'), FILTER_VALIDATE_BOOLEAN),
            minPayout: $get('min_payout'),
            scnThreshold: $get('scn_threshold'),
            moveNodeWindowDays: (int) $get('move_node_window_days'),
            payoutMode: $get('payout_mode'),
            payoutDay: (int) $get('payout_day'),
            kosmos: $engine === 'kosmos_v1' ? KosmosConfig::fromArray($d['kosmos']) : null,
        );
    }
}
