<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customers\ReferralResolver;
use App\Domain\Customers\ShippingAddressBook;
use App\Domain\Onboarding\BadgeService;
use App\Domain\Onboarding\IdentityVerifier;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\SignatureCertificate;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Orders\FakeKMoneyProvider;
use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Payouts\StatementService;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'user' => $user, 'contract' => $this->contract($request), 'month' => $user->consultant ? $this->monthFigures($user->consultant) : null,
            // I riquadri di avanzamento e compensi non devono mai impedire di aprire la panoramica.
            'progress' => $live ? $this->safely(static fn () => app(\App\Domain\Career\CareerProgress::class)->forConsultant($live->id)) : null,
            'earn' => $live ? $this->safely(static fn () => app(\App\Domain\Career\EarningsSummary::class)->for($live->id)) : null,
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
        $data = app(\App\Domain\Career\NetworkOverview::class)->for($me);
        $careers = app(\App\Domain\Career\CareerProgress::class);
        $progress = $this->safely(static fn () => $careers->forConsultant($me->id));
        $tree = $data['total']['persons'] > 0 ? $this->safely(static fn () => app(\App\Domain\Career\NetworkTree::class)->for($data, $careers->all())) : null;

        return view('site.area.network', $data + ['progress' => $progress, 'tree' => $tree, 'kosmos' => $data['plan']?->kosmos !== null]);
    }

    /** Requisiti di una persona del proprio ramo (o i propri), caricati al clic sull'albero. Chi è fuori dal ramo non esiste: 404. */
    public function networkDetail(Request $request, int $id)
    {
        $me = $this->activeConsultant($request);
        abort_unless($id === (int) $me->id || DB::table('network_paths')->where('ancestor_id', $me->id)->where('descendant_id', $id)->where('depth', '>', 0)->exists(), 404);
        $p = $this->safely(static fn () => app(\App\Domain\Career\CareerProgress::class)->forConsultant($id));

        return response()->json(app(\App\Domain\Career\NetworkTree::class)->detail($p));
    }

    /** I clienti assegnati all’incaricato. */
    public function customers(Request $request)
    {
        $me = $this->activeConsultant($request);
        $rows = Customer::query()->where('assigned_consultant_id', $me->id)->with('user.profile')->orderByDesc('registered_at')->get();
        $orders = DB::table('orders')->whereIn('buyer_user_id', $rows->pluck('user_id'))->where('status', 'paid')
            ->selectRaw('buyer_user_id, count(*) as n, min(paid_at) as first_paid, max(paid_at) as last_paid, sum(total) as spent, max(total) as biggest')->groupBy('buyer_user_id')->get()->keyBy('buyer_user_id');
        $period = Period::query()->orderByDesc('code')->first();
        $kosmos = $period?->planVersion->plan()->kosmos;
        $progress = $kosmos !== null ? $this->safely(static fn () => app(\App\Domain\Career\CareerProgress::class)->forConsultant($me->id)) : null;

        return view('site.area.customers', ['rows' => $rows, 'orders' => $orders, 'kosmos' => $kosmos, 'progress' => $progress]);
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
        $d = $request->validate(['label' => 'nullable|string|max:60', 'recipient' => 'nullable|string|max:190', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120', 'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'phone' => 'nullable|string|max:30'], SiteController::MESSAGES);
        try {
            $book->add($request->user(), $d);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['shipping' => $e->getMessage()]);
        }

        return back()->with('status', 'Indirizzo di spedizione salvato.');
    }

    public function updateAddress(Request $request, ShippingAddress $address, ShippingAddressBook $book): RedirectResponse
    {
        $d = $request->validate(['label' => 'nullable|string|max:60', 'recipient' => 'nullable|string|max:190', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120', 'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'phone' => 'nullable|string|max:30'], SiteController::MESSAGES);
        try {
            $book->update($request->user(), $address, $d);
        } catch (OrderException $e) {
            abort(404);
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

    public function saveProfile(Request $request): RedirectResponse
    {
        $profile = $this->ensureProfile($request->user());
        $rules = [
            'phone' => 'nullable|string|max:30', 'address' => 'required|string|max:190', 'city' => 'required|string|max:120',
            'zip' => 'required|string|max:10', 'province' => 'nullable|string|max:3', 'pec' => 'nullable|email|max:190', 'sdi_code' => 'nullable|string|size:7',
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

    public function sign(Request $request, IvdService $ivd): RedirectResponse
    {
        $request->validate(['otp' => 'required'], SiteController::MESSAGES);
        $contract = $this->contract($request);
        try {
            abort_if($contract === null, 404);
            $ivd->sign($contract, (string) $request->input('otp'), $request->ip(), (string) $request->userAgent());
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
