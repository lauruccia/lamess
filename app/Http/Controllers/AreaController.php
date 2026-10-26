<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Career\CareerProgress;
use App\Domain\Career\EarningsSummary;
use App\Domain\Career\NetworkOverview;
use App\Domain\Career\NetworkTree;
use App\Domain\Customers\ReferralResolver;
use App\Domain\Customers\ShippingAddressBook;
use App\Domain\Engine\Calc\KosmosCalculator;
use App\Domain\Ledger\PointsGrant;
use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\IdentityVerifier;
use App\Domain\Onboarding\IvdAcceptances;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Onboarding\SignatureCertificate;
use App\Domain\Orders\FakeKMoneyProvider;
use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Payouts\StatementService;
use App\Domain\Periods\SnapshotBuilder;
use App\Domain\Support\SupportException;
use App\Models\CalculationRun;
use App\Models\CommissionStatement;
use App\Models\Consultant;
use App\Models\ConsultantPeriodStat;
use App\Models\Customer;
use App\Models\IdentityVerification;
use App\Models\IvdContract;
use App\Models\KMoneyLink;
use App\Models\Period;
use App\Models\Profile;
use App\Models\Settlement;
use App\Models\ShippingAddress;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Area riservata di FAN e Incaricati (F1: domanda, firma, onboarding). */
class AreaController extends Controller
{
    private function contract(Request $request): ?IvdContract
    {
        return IvdContract::where('user_id', $request->user()->id)->latest('id')->first();
    }

    public function home(Request $request)
    {
        $user = $request->user()->load('profile', 'customer.consultant', 'consultant');

        $live = $user->consultant !== null && $user->consultant->status !== 'terminated' ? $user->consultant : null;

        return view('site.area.home', [
            'letterToSign' => $this->safely(static fn () => app(\App\Domain\Onboarding\IvdLetterSigning::class)->pendingFor($user)),
            'user' => $user, 'contract' => $this->contract($request), 'month' => $user->consultant ? $this->monthFigures($user->consultant) : null,
            // I riquadri di avanzamento e compensi non devono mai impedire di aprire la panoramica.
            'progress' => $live ? $this->safely(static fn () => app(CareerProgress::class)->forConsultant($live->id)) : null,
            'earn' => $live ? $this->safely(static fn () => app(EarningsSummary::class)->for($live->id)) : null,
            'grants' => $live ? $this->safely(static fn () => PointsGrant::summaryFor($live, 10)) : null,
        ]);
    }

    private function safely(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Numeri del mese per l’incaricato: dall'ultimo calcolo del periodo più recente (ufficiale se esiste, altrimenti l'ultimo completato).
     *
     * @return array{period: string, pvf: string, pvc: string, group_pv: string, pr: string, group_pr: string, rank: ?string, active: bool}|null
     */
    private function monthFigures(Consultant $c): ?array
    {
        $stat = $this->latestStats([$c->id])[$c->id] ?? null;

        return $stat === null ? null : [
            'period' => $stat['period'], 'pvf' => (string) $stat['pvf'], 'pvc' => (string) $stat['pvc'], 'group_pv' => (string) $stat['group_pv'],
            'pr' => (string) $stat['pr'], 'group_pr' => (string) $stat['group_pr'], 'rank' => $stat['rank'], 'active' => $stat['active'],
        ];
    }

    /**
     * @param  list<int>|null  $ids  null = tutti
     * @return array<int, array<string, mixed>>
     */
    private function latestStats(?array $ids): array
    {
        $period = Period::query()->orderByDesc('code')->first();
        if ($period === null) {
            return [];
        }
        $runId = $period->official_run_id ?? CalculationRun::query()->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id');
        if ($runId === null) {
            return [];
        }
        $q = ConsultantPeriodStat::query()->where('run_id', $runId);
        if ($ids !== null) {
            $q->whereIn('consultant_id', $ids);
        }
        $out = [];
        foreach ($q->get() as $s) {
            $out[(int) $s->consultant_id] = [
                'period' => $period->code, 'pvf' => $s->personal_pvf, 'pvc' => $s->personal_pvc, 'group_pv' => $s->group_pv, 'pr' => $s->pr_closing,
                'group_pr' => $s->group_pr_closing, 'rank' => $s->effective_rank, 'active' => (bool) $s->is_active,
            ];
        }

        return $out;
    }

    /** Il proprio ramo, in sola lettura: albero, totali per livello e per colonna, requisiti per la qualifica successiva. */
    public function network(Request $request)
    {
        $me = $this->activeConsultant($request);
        $data = app(NetworkOverview::class)->for($me);
        $careers = app(CareerProgress::class);
        $progress = $this->safely(static fn () => $careers->forConsultant($me->id));
        $tree = $data['total']['persons'] > 0 ? $this->safely(static fn () => app(NetworkTree::class)->for($data, $careers->all())) : null;

        return view('site.area.network', $data + ['progress' => $progress, 'tree' => $tree, 'kosmos' => $data['plan']?->kosmos !== null]);
    }

    /** Requisiti di una persona del proprio ramo (o i propri), caricati al clic sull'albero. Chi è fuori dal ramo non esiste: 404. */
    public function networkDetail(Request $request, int $id)
    {
        $me = $this->activeConsultant($request);
        abort_unless($id === (int) $me->id || DB::table('network_paths')->where('ancestor_id', $me->id)->where('descendant_id', $id)->where('depth', '>', 0)->exists(), 404);
        $p = $this->safely(static fn () => app(CareerProgress::class)->forConsultant($id));

        $tree = app(NetworkTree::class);
        $contact = $id === (int) $me->id ? null : $tree->contact(Consultant::findOrFail($id));

        return response()->json($tree->detail($p) + ['contact' => $contact]);
    }

    /** Filtri dell'elenco clienti dell'incaricato (chiave in query => etichetta). */
    public const CUSTOMER_FILTERS = [
        'contano' => 'Contano nel mese', 'mese' => 'Hanno ordinato nel mese', 'scadono' => 'Scadono a fine mese', 'zero' => 'Valgono 0 nel mese',
        'senza-ordini' => 'Senza ordini', 'fermi' => 'Fermi da oltre 30 giorni',
    ];

    /** Filtri che dipendono dal piano Incaricati (clienti validi mese per mese). */
    private const CUSTOMER_PLAN_FILTERS = ['contano', 'scadono', 'zero'];

    /**
     * Stato di un cliente in un mese: come lo conta il piano.
     * ordered = ordine fatto nel mese · covered = ancora valido per un ordine precedente · expiring = valido ma non lo sarà il mese dopo ·
     * expired = non vale più · under = ha ordinato ma sotto il minimo · none = nessun ordine · before = diventerà cliente più avanti.
     *
     * @param  list<array{at: \DateTimeImmutable, months: int, clients: int}>  $valid  ordini che rendono cliente
     * @return array{0:string,1:int} stato e quanti clienti vale nel mese
     */
    private function customerMonthState(array $valid, int $month, bool $hasPaidOrders): array
    {
        if ($valid === []) {
            return [$hasPaidOrders ? 'under' : 'none', 0];
        }
        $segments = KosmosCalculator::coverageIntervals($valid);
        $countsIn = static function (int $m) use ($valid, $segments): int {
            $w = 0;
            foreach ($valid as $o) {
                if (KosmosCalculator::monthIndex($o['at']) === $m) {
                    $w = max($w, (int) $o['clients']);
                }
            }
            foreach ($segments as [$first, $last, $clients]) {
                if ($first <= $m && $m <= $last) {
                    $w = max($w, (int) $clients);
                }
            }

            return $w;
        };
        $weight = $countsIn($month);
        if ($weight > 0) {
            $ordered = collect($valid)->contains(fn (array $o) => KosmosCalculator::monthIndex($o['at']) === $month);

            return [$ordered ? 'ordered' : ($countsIn($month + 1) > 0 ? 'covered' : 'expiring'), $weight];
        }
        $firstMonth = min(array_map(static fn (array $o) => KosmosCalculator::monthIndex($o['at']), $valid));

        return [$month < $firstMonth ? 'before' : 'expired', 0];
    }

    /** I clienti assegnati all’incaricato, preceduti da lui stesso. */
    public function customers(Request $request)
    {
        $me = $this->activeConsultant($request);
        $all = Customer::query()->where('assigned_consultant_id', $me->id)->with('user.profile')->orderByDesc('registered_at')->get();
        $period = Period::query()->orderByDesc('code')->first();
        $plan = $period?->planVersion->plan();
        $kosmos = $plan?->kosmos;
        $progress = $kosmos !== null ? $this->safely(static fn () => app(CareerProgress::class)->forConsultant($me->id)) : null;

        // L'incaricato è il primo «cliente» dell'elenco: i suoi acquisti contano come clienti suoi (se il piano lo prevede).
        $meUser = $me->user ?? $request->user();
        $meUser->loadMissing('profile');
        $self = new Customer(['user_id' => $meUser->id, 'assigned_consultant_id' => $me->id, 'source' => 'self', 'registered_at' => $meUser->created_at]);
        $self->setRelation('user', $meUser);
        $self->setAttribute('is_self', true);
        $people = collect([$self])->concat($all);

        $orders = DB::table('orders')->whereIn('buyer_user_id', $people->pluck('user_id'))->where('status', 'paid')
            ->selectRaw('buyer_user_id, count(*) as n, min(paid_at) as first_paid, max(paid_at) as last_paid, sum(total) as spent, max(total) as biggest')->groupBy('buyer_user_id')->get()->keyBy('buyer_user_id');

        // Mese scelto (ora italiana): di default quello in corso; dal passato più recente fino al prossimo, per vedere chi scade.
        $nowRome = CarbonImmutable::now('Europe/Rome');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('mese', '')) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->query('mese').'-01', 'Europe/Rome')->startOfMonth()
            : $nowRome->startOfMonth();
        $monthOptions = [];
        for ($i = -11; $i <= 1; $i++) {
            $d = $nowRome->startOfMonth()->addMonths($i);
            $monthOptions[$d->format('Y-m')] = $d->locale('it')->translatedFormat('F Y');
        }
        $monthOptions[$month->format('Y-m')] ??= $month->locale('it')->translatedFormat('F Y');
        krsort($monthOptions);
        $monthIndex = KosmosCalculator::monthIndex($month->toDateTimeImmutable());

        // Come il piano conta ciascuno nel mese scelto.
        $states = [];
        if ($kosmos !== null) {
            $qualifying = SnapshotBuilder::qualifyingOrders(now(), $plan, (string) $me->id)[(string) $me->id] ?? [];
            $ownCounts = $plan->policies->is('kosmos_own_purchase_as_client', 'on');
            foreach ($people as $p) {
                $isSelf = (bool) $p->getAttribute('is_self');
                if ($isSelf && ! $ownCounts) {
                    $states[$p->user_id] = ['own_off', 0];

                    continue;
                }
                $valid = $qualifying[$isSelf ? 'self:'.$me->id : (string) $p->user_id] ?? [];
                $states[$p->user_id] = $this->customerMonthState($valid, $monthIndex, isset($orders[$p->user_id]));
            }
        }
        $tally = ['ordered' => 0, 'covered' => 0, 'expiring' => 0, 'zero' => 0];
        foreach ($states as [$st]) {
            $key = match ($st) {
                'ordered', 'covered', 'expiring' => $st,
                'own_off', 'before' => null,
                default => 'zero',
            };
            if ($key !== null) {
                $tally[$key]++;
            }
        }

        $search = trim((string) $request->query('q', ''));
        $state = (string) $request->query('stato', '');
        $state = array_key_exists($state, self::CUSTOMER_FILTERS) && ($kosmos !== null || ! in_array($state, self::CUSTOMER_PLAN_FILTERS, true)) ? $state : '';
        $filtered = $people;
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $filtered = $filtered->filter(fn ($r) => str_contains(mb_strtolower(trim(($r->user?->profile?->fullName() ?: (string) $r->user?->name).' '.$r->user?->email.' '.$r->user?->profile?->phone)), $needle));
        }
        if ($state !== '') {
            $monthStart = $month->utc();
            $monthEnd = $month->addMonth()->utc();
            $filtered = $filtered->filter(function ($r) use ($orders, $state, $states, $monthStart, $monthEnd) {
                $o = $orders[$r->user_id] ?? null;
                $st = $states[$r->user_id][0] ?? null;

                return match ($state) {
                    'senza-ordini' => $o === null,
                    'contano' => in_array($st, ['ordered', 'covered', 'expiring'], true),
                    'scadono' => $st === 'expiring',
                    'zero' => in_array($st, ['expired', 'under', 'none'], true),
                    'mese' => $o !== null && Carbon::parse($o->last_paid, 'UTC')->gte($monthStart) && Carbon::parse($o->last_paid, 'UTC')->lt($monthEnd),
                    'fermi' => $o !== null && Carbon::parse($o->last_paid, 'UTC')->lt(now('UTC')->subDays(30)),
                    default => true,
                };
            });
        }

        // L'incaricato sta sempre in cima alla prima pagina; la paginazione conta solo i clienti.
        $selfRow = $filtered->first(fn ($r) => (bool) $r->getAttribute('is_self'));
        $rows = $filtered->reject(fn ($r) => (bool) $r->getAttribute('is_self'))->values();
        $perPage = 50;
        $page = max(1, (int) $request->query('pagina', 1));
        $rows = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'pageName' => 'pagina', 'query' => $request->except('pagina')]);

        return view('site.area.customers', [
            'rows' => $rows, 'selfRow' => $page === 1 ? $selfRow : null, 'all' => $all, 'orders' => $orders, 'kosmos' => $kosmos, 'progress' => $progress,
            'search' => $search, 'state' => $state, 'filters' => self::CUSTOMER_FILTERS, 'planFilters' => self::CUSTOMER_PLAN_FILTERS,
            'month' => $month->format('Y-m'), 'monthLabel' => $month->locale('it')->translatedFormat('F Y'), 'monthOptions' => $monthOptions, 'states' => $states, 'tally' => $tally,
        ]);
    }

    private function activeConsultant(Request $request): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || $c->status === 'terminated', 404);

        return $c;
    }

    /** I propri dati personali (anagrafica e recapiti); email e dati fiscali si cambiano solo tramite il Consorzio. */
    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = $this->ensureProfile($user);
        $book = app(ShippingAddressBook::class);

        $provider = app(KMoneyProvider::class);

        return view('site.area.profile', [
            'user' => $user, 'profile' => $profile, 'addresses' => $book->list($user), 'maxAddresses' => $book->max(),
            'countries' => (array) config('lamess.country_names'), 'shipCountries' => $this->shipCountries(),
            // Riquadro «Conto KMoney»: stato del collegamento e se il collegamento è possibile ora.
            'kmoneyEnabled' => app(OrderService::class)->kmoneyEnabled(),
            'kmoneyConnectable' => $provider instanceof KMoneyApiProvider || $provider instanceof FakeKMoneyProvider,
            'kmoneyLink' => KMoneyLink::where('user_id', $user->id)->first(),
            'kmoneyRegisterUrl' => (string) (config('lamess.kmoney.register_url') ?: rtrim((string) config('lamess.kmoney.base_url') ?: 'https://kmoney.it', '/').'/register'),
        ]);
    }

    /** Profilo dell'utente; se manca (es. account creato dal pannello) lo si crea vuoto, partendo dal nome dell'account. */
    private function ensureProfile(User $user): Profile
    {
        if ($user->profile !== null) {
            return $user->profile;
        }
        $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
        $last = count($parts) > 1 ? array_pop($parts) : null;

        return $user->profile()->create(['type' => 'private', 'first_name' => implode(' ', $parts) ?: null, 'last_name' => $last]);
    }

    public function addAddress(Request $request, ShippingAddressBook $book): RedirectResponse
    {
        $d = $request->validate(['label' => 'nullable|string|max:60', 'recipient' => 'nullable|string|max:190', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120', 'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'country_code' => 'nullable|string|size:2', 'phone' => 'nullable|string|max:30'], SiteController::MESSAGES);
        try {
            $book->add($request->user(), $d);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Indirizzo di spedizione salvato.');
    }

    public function updateAddress(Request $request, ShippingAddress $address, ShippingAddressBook $book): RedirectResponse
    {
        $d = $request->validate(['label' => 'nullable|string|max:60', 'recipient' => 'nullable|string|max:190', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120', 'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'country_code' => 'nullable|string|size:2', 'phone' => 'nullable|string|max:30'], SiteController::MESSAGES);
        abort_unless($address->user_id === $request->user()->id, 404);
        try {
            $book->update($request->user(), $address, $d);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Indirizzo aggiornato. Gli ordini già fatti non cambiano.');
    }

    public function addressAction(Request $request, ShippingAddress $address, string $action, ShippingAddressBook $book): RedirectResponse
    {
        try {
            match ($action) {
                'elimina' => $book->remove($request->user(), $address),
                'predefinito' => $book->makeDefault($request->user(), $address),
                default => abort(404),
            };
        } catch (OrderException $e) {
            abort(404);
        }

        return back()->with('status', $action === 'elimina' ? 'Indirizzo eliminato.' : 'Indirizzo predefinito aggiornato.');
    }

    /** @return array<string,string> codice => nome delle nazioni dove lo shop spedisce */
    private function shipCountries(): array
    {
        $names = (array) config('lamess.country_names');

        return collect((array) config('lamess.shop_countries'))->mapWithKeys(fn ($c) => [$c => $names[$c] ?? $c])->all();
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        $profile = $this->ensureProfile($request->user());
        $rules = [
            'phone' => 'nullable|string|max:30', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120',
            'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'country_code' => ['sometimes', 'required', Rule::in(array_keys((array) config('lamess.country_names')))], 'pec' => 'nullable|email|max:190', 'sdi_code' => 'nullable|string|size:7',
        ];
        $rules += $profile->type === 'company' ? ['company_name' => 'required|string|max:190'] : ['first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120'];
        $data = $request->validate($rules, SiteController::MESSAGES + ['size' => 'Il campo «:attribute» deve avere :size caratteri.', 'max' => 'Il campo «:attribute» è troppo lungo.']);
        $profile->update($data);
        $request->user()->update(['name' => $profile->fullName()]);

        return back()->with('status', 'Dati aggiornati. Le fatture già emesse restano invariate.');
    }

    public function savePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required', 'password' => ['required', 'confirmed', Password::min(10)],
        ], SiteController::MESSAGES + ['password.min' => 'La password deve avere almeno 10 caratteri.']);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return back()->withErrors(['current_password' => 'La password attuale non è corretta.']);
        }
        $request->user()->update(['password' => $data['password']]);

        return back()->with('status', 'Password cambiata.');
    }

    /** Statini dell’incaricato (Fase 6): solo mesi chiusi, solo i propri. */
    public function statements(Request $request)
    {
        $c = $request->user()->consultant ?? abort(404);
        $rows = Settlement::with('period')->where('consultant_id', $c->id)->get()->sortByDesc(fn ($r) => $r->period->code)->values();

        $statements = CommissionStatement::where('consultant_id', $c->id)->get()->keyBy('period_id');

        return view('site.area.statements', ['rows' => $rows, 'statements' => $statements]);
    }

    public function statement(Request $request, string $period, StatementService $svc)
    {
        [$p, $c] = $this->statementTarget($request, $period);

        return view('site.area.statement', [
            's' => $svc->build($p, $c), 'doc' => CommissionStatement::where('period_id', $p->id)->where('consultant_id', $c->id)->first(),
            'disputeDays' => StatementLifecycle::disputeDays(), 'minLength' => StatementLifecycle::MIN_DISPUTE_LENGTH,
        ]);
    }

    /** Reclamo circostanziato sul proprio estratto conto, entro il termine (Reg. 5.2). */
    public function disputeStatement(Request $request, string $period, StatementLifecycle $lifecycle): RedirectResponse
    {
        [$p, $c] = $this->statementTarget($request, $period);
        $data = $request->validate(['text' => 'required|string|max:5000'], SiteController::MESSAGES);
        $doc = CommissionStatement::where('period_id', $p->id)->where('consultant_id', $c->id)->firstOrFail();
        try {
            $ticket = $lifecycle->dispute($request->user(), $doc, $data['text']);
        } catch (SupportException $e) {
            return back()->withErrors(['text' => $e->getMessage()])->withInput();
        }

        return redirect("/area/assistenza/{$ticket->id}")->with('status', 'Reclamo inviato. Ti risponderemo da questa pagina.');
    }

    public function statementPdf(Request $request, string $period, StatementService $svc)
    {
        [$p, $c] = $this->statementTarget($request, $period);

        return response($svc->pdf($p, $c), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=statino-{$p->code}-{$c->code}.pdf"]);
    }

    /** @return array{0:Period,1:Consultant} */
    private function statementTarget(Request $request, string $code): array
    {
        $c = $request->user()->consultant ?? abort(404);
        $p = Period::where('code', $code)->where('status', Period::CLOSED)->firstOrFail();
        Settlement::where('period_id', $p->id)->where('consultant_id', $c->id)->firstOrFail();

        return [$p, $c];
    }

    public function becomeForm(Request $request, IvdService $ivd)
    {
        $contract = $this->contract($request);
        $open = $contract !== null && $contract->status === 'awaiting_signature';
        if ($open) {
            $ivd->logEvent($contract, 'viewed', $request->ip(), (string) $request->userAgent());
        }

        return view('site.area.become', [
            'user' => $request->user()->load('profile', 'customer.consultant'), 'contract' => $contract, 'awaiting' => $open,
            'hint' => $open ? session('signature_hint') : null,
            'needsBox' => ! $ivd->hasPaidFirstBox($request->user()),
            'lockedSponsor' => $this->lockedSponsor($request->user()),
        ]);
    }

    /** Sponsor scelto dal link referral, dal cookie o da un invito: chi si registra così non lo può cambiare. */
    private function lockedSponsor(User $user): ?Consultant
    {
        $customer = $user->customer;

        return $customer !== null && in_array($customer->source, ['referral', 'cookie', 'invite'], true) ? $customer->consultant : null;
    }

    public function apply(Request $request, IvdService $ivd, ReferralResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'tax_code' => 'required', 'birth_date' => 'required|date', 'birth_place' => 'required', 'address' => 'required',
            'city' => 'required', 'zip' => 'required', 'province' => 'nullable', 'country_code' => 'nullable|size:2', 'phone' => 'nullable', 'pec' => 'nullable|email', 'sponsor_code' => 'nullable', 'accept' => 'accepted',
        ], SiteController::MESSAGES);
        $locked = $this->lockedSponsor($request->user());
        if ($locked !== null) {
            $data['sponsor_code'] = null; // lo sponsor del link referral non si cambia
        }
        $sponsor = $locked ?? (filled($data['sponsor_code'] ?? null) ? $resolver->find($data['sponsor_code']) : null);
        if ($locked === null && filled($data['sponsor_code'] ?? null) && $sponsor === null) {
            return back()->withInput()->withErrors(['sponsor_code' => 'Sponsor non trovato o non abilitato a sponsorizzare.']);
        }
        try {
            $contract = $ivd->apply($request->user(), collect($data)->except(['sponsor_code', 'accept'])->all(), $sponsor);
            $req = $ivd->requestSignature($contract);
        } catch (OnboardingException $e) {
            return back()->withInput()->withErrors(['tax_code' => $e->getMessage()]);
        }

        return redirect('/area/diventa-incaricato')->with('signature_hint', $req->hint);
    }

    /** Nuovo invio del codice di firma (se non è arrivato o è scaduto). */
    public function resendCode(Request $request, IvdService $ivd): RedirectResponse
    {
        $contract = $this->contract($request);
        abort_if($contract === null || $contract->status !== 'awaiting_signature', 404);
        try {
            $req = $ivd->requestSignature($contract);
        } catch (OnboardingException $e) {
            return back()->withErrors(['otp' => $e->getMessage()]);
        }

        return redirect('/area/diventa-incaricato')->with('signature_hint', $req->hint);
    }

    public function sign(Request $request, IvdService $ivd): RedirectResponse
    {
        // Come sulla lettera cartacea: prima le dichiarazioni (art. 12) e le accettazioni, poi la firma. Le caselle obbligatorie bloccano la firma.
        $rules = [
            'otp' => 'required',
            'income_over_threshold' => 'required|in:yes,no', 'other_social_security' => 'required|in:yes,no',
            'is_pensioner' => 'nullable|boolean', 'multi_principal' => 'required|in:yes,no',
            'multi_principal_names' => 'required_if:multi_principal,yes|nullable|string|max:255',
        ];
        $messages = SiteController::MESSAGES + [
            'income_over_threshold.required' => 'Art. 12.1: indica se hai superato o no il reddito di 5.000 €.',
            'other_social_security.required' => 'Art. 12.1: indica se hai un\'altra posizione previdenziale obbligatoria.',
            'multi_principal.required' => 'Art. 12.2: indica se sei mono o plurimandatario.',
            'multi_principal_names.required_if' => 'Art. 12.2: scrivi per quali imprese operi.',
        ];
        foreach (IvdAcceptances::items() as $key => $item) {
            $rules['accept_'.$key] = $item['required'] ? 'accepted' : 'nullable|boolean';
            if ($item['required']) {
                $messages['accept_'.$key.'.accepted'] = 'Per firmare devi spuntare: '.rtrim(mb_substr($item['label'], 0, 90), ' .,:;').'…';
            }
        }
        $request->validate($rules, $messages);
        $declarations = [
            'income_over_threshold' => (string) $request->input('income_over_threshold'), 'other_social_security' => (string) $request->input('other_social_security'),
            'is_pensioner' => $request->boolean('is_pensioner'), 'multi_principal' => (string) $request->input('multi_principal'),
            'multi_principal_names' => $request->input('multi_principal') === 'yes' ? trim((string) $request->input('multi_principal_names')) : null,
        ];
        $accepted = collect(IvdAcceptances::items())->map(fn (array $i, string $key): bool => $request->boolean('accept_'.$key))->all();
        $contract = $this->contract($request);
        try {
            abort_if($contract === null, 404);
            $ivd->sign($contract, (string) $request->input('otp'), $request->ip(), (string) $request->userAgent(), $declarations, $accepted);
        } catch (OnboardingException $e) {
            return back()->withErrors(['otp' => $e->getMessage()]);
        }

        return redirect('/area')->with('status', 'Lettera firmata. La tua domanda è in attesa di accettazione da parte del Consorzio.');
    }

    public function onboarding(Request $request, OnboardingService $onboarding)
    {
        $consultant = $request->user()->consultant;
        abort_if($consultant === null || $consultant->status === 'terminated', 404);

        return view('site.area.onboarding', [
            'consultant' => $consultant, 'missing' => $onboarding->missing($consultant),
            'docs' => $onboarding->latestDocuments($consultant), 'types' => config('lamess.kyc_document_types'),
            'kycRequest' => app(KycService::class)->openRequest($consultant),
            'onlineKyc' => app(IdentityVerifier::class)->available(),
            'onlineVerified' => IdentityVerification::where('consultant_id', $consultant->id)->where('status', 'verified')->exists(),
        ]);
    }

    public function startOnlineKyc(Request $request, KycService $kyc, IdentityVerifier $verifier)
    {
        $consultant = $request->user()->consultant;
        abort_if($consultant === null || $consultant->status === 'terminated', 404);
        try {
            $start = $kyc->startOnline($consultant, $verifier, url('/area/onboarding?da=verifica'));
        } catch (OnboardingException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->away($start->url);
    }

    public function uploadDocument(Request $request, KycService $kyc): RedirectResponse
    {
        $consultant = $request->user()->consultant;
        abort_if($consultant === null, 404);
        $request->validate(['type' => 'required', 'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,pdf'], SiteController::MESSAGES + ['mimes' => 'Formati ammessi: JPG, PNG, PDF.', 'max' => 'File troppo grande (max 8 MB).']);
        try {
            $kyc->upload($consultant, (string) $request->input('type'), $request->file('file'));
        } catch (OnboardingException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return back()->with('status', 'Documento caricato: sarà verificato dal Consorzio.');
    }

    public function saveIban(Request $request, OnboardingService $onboarding): RedirectResponse
    {
        $consultant = $request->user()->consultant;
        abort_if($consultant === null, 404);
        $data = $request->validate(['iban' => 'required', 'iban_holder' => 'required'], SiteController::MESSAGES);
        try {
            $onboarding->saveBankDetails($consultant, $data['iban'], $data['iban_holder']);
        } catch (OnboardingException $e) {
            return back()->withErrors(['iban' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Dati bancari salvati.');
    }

    /** Download riservato al titolare: lettera firmata o tesserino. */
    public function download(Request $request, string $kind)
    {
        $user = $request->user();
        if ($kind === 'tesserino') {
            $badges = app(BadgeService::class);
            $consultant = $user->consultant;
            abort_if($consultant === null || ! $badges->isIssuable($consultant), 404);

            return $badges->response($consultant);
        }
        if ($kind === 'certificato') {
            $contract = $this->contract($request);
            abort_if($contract === null || $contract->signed_at === null || $contract->signature_provider === 'odoo', 404);

            return response(app(SignatureCertificate::class)->pdf($contract), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="lamess-certificato-firma.pdf"']);
        }
        $path = match ($kind) {
            'lettera' => $this->contract($request)?->pdf_path,
            default => null,
        };
        abort_if($path === null || ! Storage::exists($path), 404);
        if ($kind === 'lettera' && ($c = $this->contract($request)) !== null) {
            app(IvdService::class)->logEvent($c, 'downloaded', $request->ip(), (string) $request->userAgent());
        }

        return Storage::download($path, "lamess-{$kind}.pdf");
    }
}
