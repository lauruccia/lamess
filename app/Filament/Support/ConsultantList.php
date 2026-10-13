<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CalculationRun;
use App\Models\CompPlanVersion;
use App\Models\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dati dell'elenco incaricati, letti con sottoquery nella stessa query dell'elenco (nessuna query per riga):
 * nome dello sponsor, email, numero di diretti, saldo da pagare e, dall'ultimo calcolo ufficiale, qualifica,
 * attività del mese e PR. Le sottoquery si valutano solo per le righe della pagina mostrata.
 * Registrata come singleton «per richiesta»: periodo, calcolo e nomi delle qualifiche si leggono una volta sola.
 */
final class ConsultantList
{
    private bool $runLoaded = false;

    private ?int $runId = null;

    private ?string $periodCode = null;

    /** @var array<string, string>|null */
    private ?array $rankNames = null;

    /** Calcolo da cui si leggono qualifica e attività: quello ufficiale dell'ultimo periodo, altrimenti l'ultimo completato. */
    public function runId(): ?int
    {
        if (! $this->runLoaded) {
            $this->runLoaded = true;
            $period = Period::query()->orderByDesc('code')->first(['id', 'code', 'official_run_id']);
            $this->periodCode = $period?->code;
            $this->runId = $period === null ? null : ($period->official_run_id
                ?? CalculationRun::query()->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id'));
        }

        return $this->runId;
    }

    public function periodCode(): ?string
    {
        $this->runId();

        return $this->periodCode;
    }

    /** @return array<string, string> codice qualifica => nome */
    public function rankNames(): array
    {
        return $this->rankNames ??= collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])->mapWithKeys(fn ($r) => [$r['code'] => $r['name']])->all();
    }

    public function rankName(?string $code): string
    {
        return $code === null || $code === '' ? '—' : ($this->rankNames()[$code] ?? $code);
    }

    /** Aggiunge alla query le colonne calcolate (alias `sponsor_*`, `user_email`, `directs_count`, `open_balance`, `stat_*`). */
    public function decorate(Builder $query): Builder
    {
        $run = $this->runId();
        $stat = static fn (string $col): string => $run === null ? 'null' : "(select s.{$col} from consultant_period_stats s where s.run_id = {$run} and s.consultant_id = consultants.id)";

        return $query->with('latestIvdContract')->select('consultants.*')->selectRaw(implode(', ', [
            '(select sp.code from consultants sp where sp.id = consultants.sponsor_id) as sponsor_code',
            '(select sp.name from consultants sp where sp.id = consultants.sponsor_id) as sponsor_name',
            '(select u.email from users u where u.id = consultants.user_id) as user_email',
            '(select count(*) from consultants d where d.sponsor_id = consultants.id) as directs_count',
            "(select coalesce(sum(st.accrued), 0) from settlements st where st.consultant_id = consultants.id and st.status = 'open' and st.payout_id is null) as open_balance",
            $stat('effective_rank').' as stat_rank',
            $stat('is_active').' as stat_active',
            $stat('active_reason').' as stat_reason',
            $stat('pr_closing').' as stat_pr',
            $stat('group_pr_closing').' as stat_group_pr',
        ]));
    }

    /** Conteggi per le schede di stato, in una sola query. @return array<string, int> */
    public function counts(): array
    {
        $row = DB::table('consultants')->selectRaw(
            "count(*) as total, sum(status = 'active') as active, sum(status = 'pending') as pending, sum(status = 'suspended') as suspended, "
            ."sum(status = 'terminated') as `terminated`, sum(status <> 'terminated' and onboarding_complete = 0) as onboarding, "
            ."sum(status <> 'terminated' and (kyc_status is null or kyc_status <> 'verified')) as kyc"
        )->first();

        return array_map('intval', (array) $row);
    }
}
