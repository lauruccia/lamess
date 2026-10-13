<?php

declare(strict_types=1);

namespace App\Domain\Consultants;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Features\Feature;
use App\Domain\Payouts\PayoutService;
use App\Filament\Resources\AnnualFees\AnnualFeeResource;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\IvdContracts\IvdContractResource;
use App\Filament\Resources\KycDocuments\KycDocumentResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Filament\Resources\PvMovements\PvMovementResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\AnnualFee;
use App\Models\AuditEntry;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\ConsultantNote;
use App\Models\Customer;
use App\Models\IvdContract;
use App\Models\KycDocument;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PvMovement;
use App\Models\Settlement;
use App\Models\User;
use App\Support\StatusLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scheda incaricato 360° (sola lettura): contatori con link alle liste, tab e storico unico.
 *
 * Tutto si legge da tabelle già esistenti: nessun calcolo nuovo e nessuna scrittura. Il numero di righe di ogni tab è
 * limitato (le liste complete sono a un clic dai pulsanti «Apri la lista»). Quello che un admin può vedere dipende
 * dal registro funzionalità (tab, contatori, sezioni) e dai permessi delle liste di destinazione.
 */
final class Consultant360
{
    private const TZ = 'Europe/Rome';

    /** @var array<string, string>|null */
    private ?array $rankNames = null;

    public function __construct(private readonly Consultant $c) {}

    // ───────────────────────── contatori ─────────────────────────

    /**
     * Pulsanti-contatore: numero di elementi e link alla lista già filtrata.
     * Compare solo ciò che è acceso nel registro e che l'utente può aprire.
     *
     * @return list<array{key: string, label: string, count: int, url: string, hint: ?string}>
     */
    public function counters(?User $user = null): array
    {
        $id = $this->c->id;
        $defs = [
            ['directs', 'Diretti', ConsultantResource::class, fn () => Consultant::where('sponsor_id', $id)->count(), ['sponsor' => ['value' => $id]], null],
            ['customers', 'Clienti', CustomerResource::class, fn () => Customer::where('assigned_consultant_id', $id)->count(), ['consultant' => ['value' => $id]], null],
            ['orders', 'Ordini', OrderResource::class, fn () => Order::where('beneficiary_consultant_id', $id)->count(), ['consultant' => ['value' => $id]], LegacyPlan::hidden() ? 'a nome suo' : 'a nome suo (PV a lui)'],
            ['movements', 'Movimenti PV', PvMovementResource::class, fn () => PvMovement::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id]], null],
            ['settlements', 'Liquidazioni', SettlementResource::class, fn () => Settlement::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id]], null],
            ['payouts', 'Pagamenti', PayoutResource::class, fn () => Payout::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id]], null],
            ['contracts', 'Contratti IVD', IvdContractResource::class, fn () => IvdContract::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id]], null],
            ['kyc', 'Documenti KYC', KycDocumentResource::class, fn () => KycDocument::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id], 'status' => ['value' => '']], null],
            ['fees', 'Canoni', AnnualFeeResource::class, fn () => AnnualFee::where('consultant_id', $id)->count(), ['consultant' => ['value' => $id], 'status' => ['value' => '']], null],
        ];
        $out = [];
        foreach ($defs as [$key, $label, $resource, $count, $filters, $hint]) {
            if (! Feature::enabled("consultant360.counter.{$key}", $user) || ! $resource::canViewAny()) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $label, 'count' => (int) $count(), 'url' => $resource::getUrl('index', ['filters' => $filters]), 'hint' => $hint];
        }

        return $out;
    }

    /** Saldo ancora da pagare (liquidazioni aperte non collegate a un pagamento). */
    public function openBalance(): string
    {
        return (string) app(PayoutService::class)->openBalance($this->c->id);
    }

    // ───────────────────────── storico unico ─────────────────────────

    /**
     * Eventi dell’incaricato, dal più recente. Le note fissate stanno sempre in cima.
     * Non include il registro di audit (vi compare l'identità del super_admin): quello ha la sua tab.
     *
     * @return list<array{at: ?Carbon, kind: string, text: string, by: ?string, pinned: bool}>
     */
    public function timeline(int $limit = 80, bool $withNotes = true): array
    {
        $id = $this->c->id;
        $ev = [];
        $add = function (?string $at, string $kind, string $text, ?string $by = null) use (&$ev): void {
            if ($at !== null) {
                $ev[] = ['at' => Carbon::parse($at, 'UTC')->setTimezone(self::TZ), 'kind' => $kind, 'text' => $text, 'by' => $by, 'pinned' => false];
            }
        };
        $name = fn (?int $cid): string => $cid === null ? 'nessuno' : (Consultant::find($cid)?->name ?? "#{$cid}");

        foreach (DB::table('consultant_status_changes')->where('consultant_id', $id)->orderByDesc('id')->limit(40)->get() as $r) {
            $add($r->changed_at, 'Stato', 'Stato: '.($r->from_status ? $this->st($r->from_status).' → ' : '').$this->st($r->to_status).($r->reason ? " ({$r->reason})" : ''), $r->changed_by);
        }
        foreach (DB::table('sponsor_history')->where('consultant_id', $id)->orderByDesc('id')->limit(20)->get() as $r) {
            $add($r->created_at, 'Rete', 'Sponsor: '.$name($r->from_sponsor_id !== null ? (int) $r->from_sponsor_id : null).' → '.$name($r->to_sponsor_id !== null ? (int) $r->to_sponsor_id : null).' ('.$this->sponsorReason((string) $r->reason).')', $r->approved_by);
        }
        foreach (DB::table('rank_achievements')->where('consultant_id', $id)->orderByDesc('achieved_at')->limit(20)->get() as $r) {
            $add($r->achieved_at, 'Qualifica', 'Qualifica raggiunta: '.$this->rankName($r->rank_code).' (in vigore dal '.Carbon::parse($r->effective_from)->format('d/m/Y').($r->source === 'manual' ? ', forzata' : '').')');
        }
        foreach (DB::table('rank_awards')->where('consultant_id', $id)->orderByDesc('id')->limit(20)->get() as $r) {
            $add($r->created_at, 'Premio', 'Premio qualifica '.$this->rankName($r->rank_code).': '.$this->eur($r->amount).' ('.mb_strtolower((string) StatusLabels::label($r->status)).')');
        }
        foreach (DB::table('ivd_contracts')->where('consultant_id', $id)->orderByDesc('id')->limit(10)->get() as $r) {
            $add($r->signed_at, 'Contratto', 'Lettera IVD firmata (versione '.$r->template_version.')');
            $add($r->accepted_at, 'Contratto', 'Lettera IVD accettata dal Consorzio', $r->accepted_by);
            $add($r->rejected_at, 'Contratto', 'Lettera IVD rifiutata'.($r->rejection_reason ? " ({$r->rejection_reason})" : ''));
        }
        foreach (DB::table('kyc_documents')->where('consultant_id', $id)->orderByDesc('id')->limit(20)->get() as $r) {
            $add($r->created_at, 'KYC', 'Documento caricato: '.$r->type);
            $add($r->reviewed_at, 'KYC', 'Documento '.$r->type.': '.mb_strtolower((string) StatusLabels::label($r->status)).($r->notes ? " ({$r->notes})" : ''), $r->reviewed_by);
        }
        foreach (DB::table('annual_fees')->where('consultant_id', $id)->whereNotNull('paid_at')->orderByDesc('id')->limit(10)->get() as $r) {
            $add($r->paid_at, 'Canone', 'Canone anno '.$r->year_index.' pagato: '.$this->eur($r->amount));
        }
        foreach (DB::table('settlements')->join('periods', 'periods.id', '=', 'settlements.period_id')->where('settlements.consultant_id', $id)->orderByDesc('settlements.id')->limit(15)->get(['settlements.created_at', 'periods.code', 'settlements.accrued', 'settlements.held']) as $r) {
            $add($r->created_at, 'Liquidazione', "Liquidazione {$r->code}: ".$this->eur($r->accrued).((float) $r->held > 0 ? ' (trattenuti '.$this->eur($r->held).')' : ''));
        }
        foreach (DB::table('payouts')->where('consultant_id', $id)->orderByDesc('id')->limit(15)->get() as $r) {
            $add($r->created_at, 'Pagamento', 'Pagamento preparato: '.$this->eur($r->amount), $r->prepared_by);
            $add($r->paid_at, 'Pagamento', 'Pagamento effettuato: '.$this->eur($r->amount), $r->paid_by);
        }
        foreach (DB::table('orders')->where('beneficiary_consultant_id', $id)->whereNotNull('paid_at')->orderByDesc('paid_at')->limit(15)->get() as $r) {
            $add($r->paid_at, 'Ordine', "Ordine {$r->number} pagato: ".$this->eur($r->total).(LegacyPlan::hidden() ? '' : ' · '.$this->n($r->pv_total).' PV'));
        }
        foreach (DB::table('customer_assignment_history')->where(fn ($w) => $w->where('from_consultant_id', $id)->orWhere('to_consultant_id', $id))->orderByDesc('id')->limit(15)->get() as $r) {
            $add($r->created_at, 'Clienti', 'Cliente #'.$r->customer_id.($r->to_consultant_id === $id ? ' assegnato a lui' : ' passato a '.$name($r->to_consultant_id !== null ? (int) $r->to_consultant_id : null)).($r->reason ? " ({$r->reason})" : ''), $r->changed_by);
        }
        $ev[] = ['at' => Carbon::parse($this->c->since, 'UTC')->setTimezone(self::TZ), 'kind' => 'Rete', 'text' => 'Incaricato (firma della Lettera IVD)', 'by' => null, 'pinned' => false];

        if ($withNotes) {
            foreach (ConsultantNote::where('consultant_id', $id)->orderByDesc('id')->limit(40)->get() as $n) {
                $ev[] = ['at' => $n->created_at?->copy()->setTimezone(self::TZ), 'kind' => 'Nota', 'text' => $n->body, 'by' => $n->author_label, 'pinned' => $n->pinned];
            }
        }
        usort($ev, static fn ($a, $b) => [$b['pinned'], $b['at']?->getTimestamp()] <=> [$a['pinned'], $a['at']?->getTimestamp()]);

        return array_slice($ev, 0, $limit);
    }

    // ───────────────────────── tab ─────────────────────────

    /**
     * Contenuto di una tab: dati chiave e tabelle.
     *
     * @return array{facts: list<array{0: string, 1: string}>, tables: list<array{title: string, columns: list<string>, rows: list<list<string>>, more: ?array{label: string, url: string}, empty: string}>}
     */
    public function tab(string $name): array
    {
        return match ($name) {
            'profile' => $this->profile(),
            'network' => $this->network(),
            'volumes' => $this->volumes(),
            'commissions' => $this->commissions(),
            'wallet' => $this->wallet(),
            'sales' => $this->sales(),
            'documents' => $this->documents(),
            'audit' => $this->audit(),
            default => ['facts' => [], 'tables' => []],
        };
    }

    private function profile(): array
    {
        $c = $this->c;
        $user = $c->user_id ? User::find($c->user_id) : null;
        $p = $user ? DB::table('profiles')->where('user_id', $user->id)->first() : null;
        $badge = $c->badge_number ? $c->badge_number.($c->badge_valid_until ? ' (fino al '.$c->badge_valid_until->format('d/m/Y').')' : '') : '—';
        $facts = [
            ['Nome e cognome', $c->name], ['Codice attività', $c->code], ['Codice referral', (string) ($c->referral_code ?: '—')],
            ['Stato', (string) StatusLabels::label($c->status)], ['Incaricato dal', $this->d($c->since)],
            ['Ingresso in rete', $c->network_since ? $this->d($c->network_since) : '— (vale la firma della lettera)'],
            ['Email', $user?->email ?? '—'], ['Telefono', (string) ($p->phone ?? '—')],
            ['Indirizzo', $p ? trim(implode(', ', array_filter([$p->address, trim(($p->zip ?? '').' '.($p->city ?? '').' '.($p->province ? "({$p->province})" : '')), $p->country_code]))) ?: '—' : '—'],
            ['Codice fiscale', (string) ($p->tax_code ?? $c->person_key ?? '—')], ['Partita IVA', (string) ($p->vat_number ?? '—')],
            ['Tesserino', $badge], ['Onboarding completo', $c->onboarding_complete ? 'Sì' : 'No'], ['KYC', (string) StatusLabels::label($c->kyc_status ?: 'unverified')],
            ['IBAN', $this->maskIban($c->iban).($c->iban_holder ? " — intestato a {$c->iban_holder}" : '')],
            ['Pagamenti sospesi', $c->bonus_hold ? 'Sì' : 'No'],
        ];

        return ['facts' => $facts, 'tables' => []];
    }

    private function network(): array
    {
        $chain = [];
        $cur = $this->c->sponsor_id;
        for ($i = 0; $cur !== null && $i < 8; $i++) {
            $s = Consultant::find($cur);
            if ($s === null) {
                break;
            }
            $chain[] = "{$s->code} — {$s->name}";
            $cur = $s->sponsor_id;
        }
        $orig = $this->c->original_sponsor_id ? Consultant::find($this->c->original_sponsor_id) : null;
        $facts = [
            ['Sponsor attuale', $chain[0] ?? 'nessuno'], ['Sponsor originale', $orig ? "{$orig->code} — {$orig->name}" : '—'],
            ['Catena verso l\'alto', $chain === [] ? '—' : implode(' ← ', array_slice($chain, 0, 6))],
            ['Incaricati diretti (1° livello)', (string) Consultant::where('sponsor_id', $this->c->id)->count()],
            ['Clienti diretti', (string) Customer::where('assigned_consultant_id', $this->c->id)->count()],
        ];

        $name = fn ($x) => $x ? (Consultant::find($x)?->name ?? "#{$x}") : '—';
        $hist = DB::table('sponsor_history')->where('consultant_id', $this->c->id)->orderByDesc('id')->limit(15)->get()
            ->map(fn ($r) => [$this->d($r->created_at), $name($r->from_sponsor_id), $name($r->to_sponsor_id), $this->sponsorReason((string) $r->reason), (string) ($r->note ?? '')])->all();
        // Ordini pagati nel mese corrente (fuso Europe/Rome): numero e importo (IVA inclusa), per incaricati e clienti diretti.
        $monthStart = Carbon::now(self::TZ)->startOfMonth()->utc();
        $monthEnd = Carbon::now(self::TZ)->endOfMonth()->utc();
        $monthlyByUser = fn (array $userIds) => Order::whereIn('buyer_user_id', $userIds)
            ->whereIn('status', Order::PAID_STATES)->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->selectRaw('buyer_user_id, count(*) as n, sum(total) as amount')->groupBy('buyer_user_id')->get()->keyBy('buyer_user_id');
        $directRows = Consultant::where('sponsor_id', $this->c->id)->orderBy('code')->limit(30)->get();
        $directMonthly = $monthlyByUser($directRows->pluck('user_id')->filter()->all());
        $directs = $directRows->map(fn (Consultant $d) => [$d->code, $d->name, (string) ($directMonthly[$d->user_id]->n ?? 0), $this->eur($directMonthly[$d->user_id]->amount ?? 0)])->all();
        $customerRows = DB::table('customers')->leftJoin('users', 'users.id', '=', 'customers.user_id')->where('customers.assigned_consultant_id', $this->c->id)
            ->orderByDesc('customers.id')->limit(20)->get(['customers.user_id', 'users.name', 'users.email', 'customers.registered_at']);
        $monthly = $monthlyByUser($customerRows->pluck('user_id')->filter()->all());
        $customers = $customerRows->map(fn ($r) => [
            (string) ($r->name ?? '—'), (string) ($r->email ?? '—'), $this->d($r->registered_at),
            (string) ($monthly[$r->user_id]->n ?? 0), $this->eur($monthly[$r->user_id]->amount ?? 0),
        ])->all();

        return ['facts' => $facts, 'tables' => [
            $this->table('Storico sponsor', ['Data', 'Da', 'A', 'Motivo', 'Nota'], $hist, 'Lo sponsor non è mai cambiato.'),
            $this->table('Incaricati diretti (1° livello)', ['Codice', 'Nome', 'Ordini del mese', 'Importo del mese'], $directs, 'Nessun diretto.', $this->link('Apri la lista degli incaricati diretti', ConsultantResource::class, ['sponsor' => ['value' => $this->c->id]])),
            $this->table('Clienti diretti (ultimi 20)', ['Nome', 'Email', 'Registrato il', 'Ordini del mese', 'Importo del mese'], $customers, 'Nessun cliente.', $this->link('Apri la lista dei clienti', CustomerResource::class, ['consultant' => ['value' => $this->c->id]])),
        ]];
    }

    private function volumes(): array
    {
        $rows = DB::table('consultant_period_stats as s')->join('periods as p', 'p.id', '=', 's.period_id')
            ->where('s.consultant_id', $this->c->id)
            ->whereRaw('s.run_id = coalesce(p.official_run_id, (select max(r.id) from calculation_runs r where r.period_id = p.id and r.status = ?))', ['completed'])
            ->orderByDesc('p.code')->limit(12)
            ->get(['p.code', 's.personal_pvf', 's.personal_pvc', 's.activity_points', 's.pr_closing', 's.group_pr_closing', 's.is_active', 's.effective_rank', 's.achieved_rank'])
            ->map(fn ($r) => [$r->code, $this->n($r->personal_pvf), $this->n($r->personal_pvc), $this->n($r->activity_points), $this->n($r->pr_closing), $this->n($r->group_pr_closing), $r->is_active ? 'Attivo' : 'Non attivo', $this->rankName((string) $r->effective_rank)])->all();
        $ach = DB::table('rank_achievements')->where('consultant_id', $this->c->id)->orderByDesc('achieved_at')->get()
            ->map(fn ($r) => [$this->rankName($r->rank_code), $this->d($r->achieved_at), $this->d($r->effective_from), $r->source === 'manual' ? 'Forzata' : 'Calcolata'])->all();
        $awards = DB::table('rank_awards')->where('consultant_id', $this->c->id)->orderByDesc('id')->get()
            ->map(fn ($r) => [$this->rankName($r->rank_code), $this->eur($r->amount), (string) StatusLabels::label($r->status), (string) ($r->reason ?? '')])->all();
        $mov = PvMovement::where('consultant_id', $this->c->id)->count();

        return ['facts' => [['Movimenti PV registrati', (string) $mov]], 'tables' => [
            $this->table('Mese per mese (ultimi 12 mesi con calcolo)', ['Periodo', 'PVF', 'PVC', 'Punti attività', 'PR (propri)', 'PR di gruppo', 'Attività', 'Qualifica nel mese'], $rows, 'Nessun calcolo ancora per questo incaricato.'),
            $this->table('Qualifiche raggiunte', ['Qualifica', 'Raggiunta il', 'In vigore dal', 'Origine'], $ach, 'Nessuna qualifica oltre quella iniziale.'),
            $this->table('Premi qualifica', ['Qualifica', 'Importo', 'Stato', 'Nota'], $awards, 'Nessun premio.'),
        ]];
    }

    private function commissions(): array
    {
        $base = DB::table('commission_lines as cl')->join('periods as p', 'p.id', '=', 'cl.period_id')->where('cl.beneficiary_id', $this->c->id)
            ->whereRaw('cl.run_id = coalesce(p.official_run_id, (select max(r.id) from calculation_runs r where r.period_id = p.id and r.status = ?))', ['completed']);
        $sum = (clone $base)->groupBy('p.code', 'cl.bonus_type', 'cl.engine_status')->orderByDesc('p.code')->limit(60)
            ->get(['p.code', 'cl.bonus_type', 'cl.engine_status', DB::raw('count(*) as n'), DB::raw('sum(cl.amount) as amount')])
            ->map(fn ($r) => [$r->code, $this->bonus($r->bonus_type), (string) StatusLabels::label($r->engine_status), (string) $r->n, $this->eur($r->amount)])->all();
        $lines = (clone $base)->orderByDesc('cl.id')->limit(25)
            ->get(['p.code', 'cl.bonus_type', 'cl.base_amount', 'cl.pct', 'cl.amount', 'cl.reason', 'cl.engine_status', 'cl.source_consultant_id'])
            ->map(fn ($r) => [$r->code, $this->bonus($r->bonus_type), $this->n($r->base_amount), $r->pct !== null ? $this->n((float) $r->pct * 100).'%' : '—', $this->eur($r->amount), (string) StatusLabels::label($r->engine_status), LegacyPlan::hidden() && LegacyPlan::isLegacyBonus((string) $r->bonus_type) ? '' : (string) ($r->reason ?? '')])->all();
        $total = (clone $base)->where('cl.engine_status', '!=', 'excluded')->sum('cl.amount');
        $excluded = (clone $base)->where('cl.engine_status', 'excluded')->sum('cl.amount');

        return ['facts' => [['Totale maturato o trattenuto (mesi calcolati)', $this->eur($total)], ['Importi non spettanti (esclusi dal motore)', $this->eur($excluded)]], 'tables' => [
            $this->table('Per mese, tipo e stato', ['Periodo', 'Tipo', 'Stato', 'Righe', 'Importo'], $sum, 'Nessuna commissione.'),
            $this->table('Ultime 25 righe', ['Periodo', 'Tipo', 'Base', '%', 'Importo', 'Stato', 'Spiegazione'], $lines, 'Nessuna riga.'),
        ]];
    }

    private function wallet(): array
    {
        $id = $this->c->id;
        $set = DB::table('settlements as s')->join('periods as p', 'p.id', '=', 's.period_id')->where('s.consultant_id', $id)->orderByDesc('p.code')->limit(24)
            ->get(['p.code', 's.accrued', 's.held', 's.status', 's.payout_id'])
            ->map(fn ($r) => [$r->code, $this->eur($r->accrued), $this->eur($r->held), (string) StatusLabels::label($r->status), $r->payout_id ? 'Sì' : 'No'])->all();
        $pay = Payout::where('consultant_id', $id)->orderByDesc('id')->limit(20)->get()
            ->map(fn (Payout $p) => [$this->d($p->created_at), $this->eur($p->amount), (string) StatusLabels::label($p->status), $p->paid_at ? $this->d($p->paid_at) : '—', (string) ($p->method ?: '—'), $this->maskIban($p->iban)])->all();
        $facts = [
            ['Da pagare (liquidazioni aperte)', $this->eur($this->openBalance())],
            ['Maturato in tutte le liquidazioni', $this->eur(Settlement::where('consultant_id', $id)->sum('accrued'))],
            ['Trattenuto', $this->eur(Settlement::where('consultant_id', $id)->sum('held'))],
            ['Già pagato', $this->eur(Payout::where('consultant_id', $id)->where('status', 'paid')->sum('amount'))],
            ['IBAN', $this->maskIban($this->c->iban)],
        ];

        return ['facts' => $facts, 'tables' => [
            $this->table('Liquidazioni mensili', ['Periodo', 'Maturato', 'Trattenuto', 'Stato', 'In un pagamento'], $set, 'Nessuna liquidazione.', $this->link('Apri le liquidazioni', SettlementResource::class, ['consultant' => ['value' => $id]])),
            $this->table('Pagamenti', ['Preparato il', 'Importo', 'Stato', 'Pagato il', 'Metodo', 'IBAN'], $pay, 'Nessun pagamento.', $this->link('Apri i pagamenti', PayoutResource::class, ['consultant' => ['value' => $id]])),
        ]];
    }

    private function sales(): array
    {
        $id = $this->c->id;
        $ord = Order::where('beneficiary_consultant_id', $id)->orderByDesc('id')->limit(15)->get()
            ->map(fn (Order $o) => array_values(array_filter([(string) $o->number, $this->d($o->created_at), (string) StatusLabels::label($o->status), $this->eur($o->total), LegacyPlan::hidden() ? null : $this->n($o->pv_total)], static fn ($v) => $v !== null)))->all();
        $mov = PvMovement::where('consultant_id', $id)->orderByDesc('id')->limit(15)->get()
            ->map(fn (PvMovement $m) => ['#'.$m->id, $this->d($m->event_at), strtoupper((string) $m->kind), (string) $m->type, $this->n($m->pv), (string) StatusLabels::label($m->status)])->all();

        $orders = $this->table('Ultimi ordini', LegacyPlan::hidden() ? ['Numero', 'Data', 'Stato', 'Totale'] : ['Numero', 'Data', 'Stato', 'Totale', 'PV'], $ord, 'Nessun ordine.', $this->link('Apri gli ordini', OrderResource::class, ['consultant' => ['value' => $id]]));
        if (LegacyPlan::hidden()) {
            return ['facts' => [['Ordini a suo nome', (string) Order::where('beneficiary_consultant_id', $id)->count()]], 'tables' => [$orders]];
        }

        return ['facts' => [['Ordini a suo nome', (string) Order::where('beneficiary_consultant_id', $id)->count()], ['Movimenti PV', (string) PvMovement::where('consultant_id', $id)->count()]], 'tables' => [
            $orders,
            $this->table('Ultimi movimenti PV', ['#', 'Data', 'Tipo', 'Causale', 'PV', 'Stato'], $mov, 'Nessun movimento.', $this->link('Apri i movimenti PV', PvMovementResource::class, ['consultant' => ['value' => $id]])),
        ]];
    }

    private function documents(): array
    {
        $id = $this->c->id;
        $kyc = KycDocument::where('consultant_id', $id)->orderByDesc('id')->get()
            ->map(fn (KycDocument $k) => [(string) $k->type, (string) $k->original_name, (string) StatusLabels::label($k->status), $k->reviewed_at ? $this->d($k->reviewed_at, true) : '—', (string) ($k->reviewed_by ?? '—'), (string) ($k->notes ?? '')])->all();
        $ct = IvdContract::where('consultant_id', $id)->orderByDesc('id')->get()
            ->map(fn (IvdContract $c) => ['Versione '.$c->template_version, (string) StatusLabels::label($c->status), $this->d($c->signed_at), $c->accepted_at ? $this->d($c->accepted_at) : '—', $c->expires_at ? $this->d($c->expires_at) : '—', (string) $c->term_index])->all();
        $fees = AnnualFee::where('consultant_id', $id)->orderBy('year_index')->get()
            ->map(fn (AnnualFee $f) => ['Anno '.$f->year_index, $this->eur($f->amount), $this->d($f->due_at), $f->paid_at ? $this->d($f->paid_at) : '—', (string) StatusLabels::label($f->status)])->all();

        return ['facts' => [['KYC', (string) StatusLabels::label($this->c->kyc_status ?: 'unverified')], ['Onboarding completo', $this->c->onboarding_complete ? 'Sì' : 'No']], 'tables' => [
            $this->table('Documenti KYC', ['Tipo', 'File', 'Stato', 'Verificato il', 'Da', 'Note'], $kyc, 'Nessun documento.', $this->link('Apri i documenti KYC', KycDocumentResource::class, ['consultant' => ['value' => $id], 'status' => ['value' => '']])),
            $this->table('Contratti IVD', ['Versione', 'Stato', 'Firmato il', 'Accettato il', 'Scade il', 'Anno di contratto'], $ct, 'Nessun contratto.', $this->link('Apri i contratti', IvdContractResource::class, ['consultant' => ['value' => $id], 'status' => ['value' => '']])),
            $this->table('Canoni annuali', ['Anno', 'Importo', 'Scadenza', 'Pagato il', 'Stato'], $fees, 'Nessun canone.', $this->link('Apri i canoni', AnnualFeeResource::class, ['consultant' => ['value' => $id], 'status' => ['value' => '']])),
        ]];
    }

    /** Registro di audit dell’incaricato: contiene l'identità di chi ha operato, quindi solo super_admin (lo impone anche il registro funzionalità). */
    private function audit(): array
    {
        $rows = AuditEntry::where('subject_type', 'Consultant')->where('subject_id', (string) $this->c->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditEntry $a) => [Carbon::parse($a->occurred_at)->setTimezone(self::TZ)->format('d/m/Y H:i'), (string) $a->actor_label, (string) $a->action, (string) $a->description, (string) ($a->reason ?? '')])->all();

        return ['facts' => [], 'tables' => [$this->table('Ultime 50 operazioni sull’incaricato', ['Quando', 'Chi', 'Azione', 'Descrizione', 'Motivo'], $rows, 'Nessuna operazione registrata.')]];
    }

    // ───────────────────────── utilità ─────────────────────────

    public function maskIban(?string $iban): string
    {
        $iban = preg_replace('/\s+/', '', (string) $iban);
        if ($iban === '') {
            return '— (mancante)';
        }

        return strlen($iban) <= 8 ? str_repeat('•', strlen($iban)) : substr($iban, 0, 4).str_repeat('•', max(0, strlen($iban) - 8)).substr($iban, -4);
    }

    /** @return array{label: string, url: string}|null */
    private function link(string $label, string $resource, array $filters): ?array
    {
        return $resource::canViewAny() ? ['label' => $label, 'url' => $resource::getUrl('index', ['filters' => $filters])] : null;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  array{label: string, url: string}|null  $more
     * @return array{title: string, columns: list<string>, rows: list<list<string>>, more: ?array{label: string, url: string}, empty: string}
     */
    private function table(string $title, array $columns, array $rows, string $empty, ?array $more = null): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'more' => $rows === [] ? null : $more, 'empty' => $empty];
    }

    private function d(mixed $v, bool $time = false): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return Carbon::parse($v, 'UTC')->setTimezone(self::TZ)->format($time ? 'd/m/Y H:i' : 'd/m/Y');
    }

    private function eur(mixed $v): string
    {
        return number_format((float) $v, 2, ',', '.').' €';
    }

    private function n(mixed $v): string
    {
        $f = (float) $v;

        return number_format($f, fmod($f, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }

    private function st(?string $s): string
    {
        return (string) StatusLabels::label($s);
    }

    private function sponsorReason(string $r): string
    {
        return ['compression' => 'ricollegamento per cessazione', 'technical_fix' => 'correzione tecnica', 'transfer' => 'cessione', 'move' => 'spostamento'][$r] ?? ($r !== '' ? $r : '—');
    }

    private function bonus(string $t): string
    {
        if (LegacyPlan::hidden() && LegacyPlan::isLegacyBonus($t)) {
            return LegacyPlan::NEUTRAL_LABEL;
        }

        return ['direct' => 'Direct', 'team' => 'Team', 'matching' => 'Matching', 'rank' => 'Rank Bonus', 'fsb' => 'Fast Start', 'clawback' => 'Storno', 'rank_installment' => 'Rata Rank Bonus', 'indirect' => 'Compenso indiretto', 'indirect_ext' => 'Indiretto esteso', 'structure' => 'Bonus di Struttura'][$t] ?? $t;
    }

    private function rankName(?string $code): string
    {
        $this->rankNames ??= collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])->mapWithKeys(fn ($r) => [$r['code'] => $r['name']])->all();

        return $code === null || $code === '' ? '—' : ($this->rankNames[$code] ?? $code);
    }
}
