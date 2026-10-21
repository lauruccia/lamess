<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Engine\Support\Num;
use App\Models\Consultant;
use App\Models\StructureBonus;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statino settimanale dei Bonus di Struttura, separato dallo statino mensile (Dirette e Indirette).
 * La settimana (lunedì–domenica, ora di Roma) è quella della conferma del bonus: da quel momento l'importo è definitivo.
 */
final class BonusStatementService
{
    public const WEEK_PATTERN = '/^\d{4}-W\d{2}$/';

    public static function weekKey(\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->setTimezone('Europe/Rome')->format('o-\WW');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} lunedì 00:00 e lunedì successivo, ora di Roma */
    public static function range(string $week): array
    {
        if (preg_match(self::WEEK_PATTERN, $week) !== 1) {
            throw new \InvalidArgumentException('Settimana non valida.');
        }
        [$y, $w] = explode('-W', $week);
        $start = CarbonImmutable::now('Europe/Rome')->setISODate((int) $y, (int) $w)->startOfDay();

        return [$start, $start->addWeek()];
    }

    private function base(Consultant $c)
    {
        return StructureBonus::where('beneficiary_id', $c->id)->whereIn('status', [StructureBonus::CONFIRMED, StructureBonus::PAID])
            ->where('engine_status', 'accrued')->whereNotNull('confirmed_at');
    }

    /** @return Collection<int, array{week:string,from:CarbonImmutable,count:int,total:string}> dalla settimana più recente */
    public function weeks(Consultant $c): Collection
    {
        $out = [];
        foreach ($this->base($c)->get(['id', 'amount', 'confirmed_at']) as $b) {
            $k = self::weekKey($b->confirmed_at);
            $out[$k] ??= ['week' => $k, 'from' => self::range($k)[0], 'count' => 0, 'total' => BigDecimal::zero()];
            $out[$k]['count']++;
            $out[$k]['total'] = $out[$k]['total']->plus((string) $b->amount);
        }
        krsort($out);

        return collect(array_values($out))->map(fn (array $w) => ['total' => (string) Num::money($w['total'])] + $w);
    }

    /** @return array<string,mixed> */
    public function build(Consultant $c, string $week): array
    {
        [$from, $to] = self::range($week);
        $rows = $this->base($c)->where('confirmed_at', '>=', $from->utc())->where('confirmed_at', '<', $to->utc())
            ->with('source')->orderBy('confirmed_at')->orderBy('id')->get();
        $total = BigDecimal::zero();
        $lines = [];
        foreach ($rows as $b) {
            $total = $total->plus((string) $b->amount);
            $x = (array) $b->explanation;
            $lines[] = [
                'source' => $b->source ? "{$b->source->name} [{$b->source->code}]" : '—',
                'why' => (string) ($x['formula'] ?? ''), 'reached_at' => $x['reached_at'] ?? null,
                'amount' => (string) $b->amount, 'confirmed_at' => $b->confirmed_at,
                'state' => $b->wallet_credited_at !== null ? 'In Tasca' : ($b->status === StructureBonus::PAID ? 'Pagato' : 'Confermato'),
            ];
        }

        return ['week' => $week, 'from' => $from, 'to' => $to->subDay(), 'consultant' => $c, 'lines' => $lines, 'total' => (string) Num::money($total), 'seller' => config('lamess.seller')];
    }

    public function pdf(Consultant $c, string $week): string
    {
        return Pdf::loadView('pdf.bonus-statement', $this->build($c, $week))->output();
    }
}
