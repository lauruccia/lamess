<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPagePayment;
use App\Domain\Pages\PersonalPageService;
use App\Filament\Resources\ConsultantPages\ConsultantPageResource;
use App\Models\Consultant;
use App\Models\ConsultantPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** Pagina personale pubblica dell'incaricato (/i/{slug}) e la sua gestione dall'area riservata (/area/pagina). */
class PersonalPageController extends Controller
{
    private const URL_RULE = ['nullable', 'url:http,https', 'max:300'];

    public function show(string $slug, PersonalPageService $svc)
    {
        $page = $svc->findPublic($slug);
        abort_if($page === null, 404);
        ConsultantPage::whereKey($page->id)->increment('views');
        session(['pp_back' => $page->slug]);

        return $this->render($page, $svc, false);
    }

    private function render(ConsultantPage $page, PersonalPageService $svc, bool $preview, string $backUrl = '/area/pagina', string $backLabel = '← Torna alla modifica', ?\DateTimeInterface $photoExpires = null)
    {
        $c = $page->consultant;
        $content = $svc->content($page, $preview);
        $wa = PersonalPageService::whatsappDigits($content['whatsapp']);

        return view('site.personal', [
            'page' => $page, 'c' => $c, 'd' => $content, 'preview' => $preview, 'backUrl' => $backUrl, 'backLabel' => $backLabel,
            'refUrl' => '/r/'.$c->ensureReferralCode(), 'wa' => $wa,
            'photoUrl' => $content['photo_path'] ? $this->photoUrl($page, basename((string) $content['photo_path']), $photoExpires) : null,
            'shared' => (array) config('personal-page'),
            'socials' => array_filter(array_intersect_key($content, PersonalPageService::SOCIALS)),
        ]);
    }

    /** Indirizzo della foto; nell'anteprima condivisa è firmato, con la stessa scadenza del link, così la vede anche chi non è collegato. */
    private function photoUrl(ConsultantPage $page, string $file, ?\DateTimeInterface $expires): string
    {
        return $expires !== null
            ? URL::temporarySignedRoute('personal-page.photo', $expires, ['page' => $page->id, 'file' => $file])
            : '/media/pagine/'.$page->id.'/'.$file;
    }

    /** Foto della pagina: pubblica se la pagina è online, altrimenti solo per il proprietario e per chi in back-office controlla le pagine. */
    public function photo(Request $request, ConsultantPage $page, string $file, PersonalPageService $svc)
    {
        $paths = array_filter([$page->photo_path, $page->proposal['photo_path'] ?? null]);
        $path = collect($paths)->first(fn ($p) => basename((string) $p) === $file);
        abort_if($path === null || ! preg_match('/\.(jpe?g|png|webp)$/i', $file) || ! Storage::exists($path), 404);

        $user = $request->user();
        $isLive = $path === $page->photo_path && $svc->isPublic($page);
        $mayPreview = $request->hasValidSignature() || ($user !== null && ($user->id === $page->consultant?->user_id || $user->can('compliance.view')));
        abort_unless($isLive || $mayPreview, 404);

        return Storage::response($path, null, ['Cache-Control' => $isLive ? 'public, max-age=3600' : 'private, no-store']);
    }

    // ------------------------------------------------------------ area riservata

    private function mine(Request $request, PersonalPageService $svc): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || ! $svc->areaAvailable($request->user()) || ! $svc->eligible($c), 404);

        return $c;
    }

    /** Come mine(), ma la pagina deve anche essere attivata: serve a chi modifica, vede l'anteprima o la mette online. */
    private function activeMine(Request $request, PersonalPageService $svc): Consultant
    {
        $c = $this->mine($request, $svc);
        $page = $svc->activationRequired() ? $svc->find($c) : $svc->forConsultant($c);
        abort_unless($page !== null && $svc->isActivated($page), 403, 'La pagina non è attiva: chiedi l\'attivazione dall\'area riservata.');

        return $c;
    }

    public function edit(Request $request, PersonalPageService $svc, PersonalPagePayment $payments)
    {
        $c = $this->mine($request, $svc);
        $page = $svc->find($c) ?? ($svc->activationRequired() ? new ConsultantPage : $svc->forConsultant($c));
        if (! $svc->isActivated($page)) {
            return view('site.area.page-activation', ['page' => $page, 'svc' => $svc, 'c' => $c, 'autoPay' => $payments->canPay($page)]);
        }

        return view('site.area.page', [
            'page' => $page, 'd' => $svc->content($page, true), 'svc' => $svc, 'c' => $c,
            'public' => $svc->isPublic($page), 'approval' => $svc->approvalRequired(),
            'url' => url('/i/'.$page->slug), 'defaultName' => $svc->defaultName($c),
            'activation' => ['state' => $svc->activationState($page), 'left' => $svc->daysLeft($page), 'expires' => $page->activation_expires_at, 'price' => $svc->price(), 'payUrl' => (string) config('lamess.personal_pages.pay_url'), 'required' => $svc->activationRequired(), 'autoPay' => $payments->canPay($page)],
        ]);
    }

    public function preview(Request $request, PersonalPageService $svc)
    {
        $c = $this->activeMine($request, $svc);

        return $this->render($svc->forConsultant($c), $svc, true);
    }

    /** Anteprima per il Consorzio della pagina di un incaricato, anche non approvata o non online (con le modifiche in attesa). */
    public function adminPreview(Request $request, ConsultantPage $page, PersonalPageService $svc)
    {
        abort_unless($request->user()?->can('compliance.view') && $svc->enabled() && $page->consultant !== null, 404);

        return $this->render($page, $svc, true, ConsultantPageResource::getUrl('index'), '← Torna all\'elenco');
    }

    /** Anteprima condivisa dal Consorzio con un link firmato a scadenza: non richiede accesso né permessi. */
    public function sharedPreview(Request $request, ConsultantPage $page, PersonalPageService $svc)
    {
        abort_unless($svc->enabled() && $page->consultant !== null, 404);

        return $this->render($page, $svc, true, '', '', Carbon::createFromTimestamp((int) $request->query('expires')));
    }

    public function save(Request $request, PersonalPageService $svc): RedirectResponse
    {
        $c = $this->activeMine($request, $svc);
        $page = $svc->forConsultant($c);
        $media = [];
        $rules = [
            'display_name' => 'required|string|max:120', 'headline' => 'nullable|string|max:160',
            'bio' => 'nullable|string|max:900', 'story' => 'nullable|string|max:3000',
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{6,30}$/'], 'email_public' => 'nullable|email|max:190',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_photo' => 'nullable|boolean',
            'slug' => 'required|string|max:40',
            'media' => 'nullable|array|max:'.PersonalPageService::MEDIA_SLOTS,
            'media.*.kind' => 'nullable|in:'.implode(',', array_keys(PersonalPageService::MEDIA_KINDS)),
            'media.*.title' => 'nullable|string|max:120', 'media.*.description' => 'nullable|string|max:300', 'media.*.url' => self::URL_RULE,
        ];
        foreach (array_keys(PersonalPageService::SOCIALS) as $s) {
            $rules[$s] = self::URL_RULE;
        }
        $data = $request->validate($rules, SiteController::MESSAGES + [
            'url' => 'Il link deve iniziare con https:// ', 'image' => 'Carica un\'immagine JPG, PNG o WebP.', 'photo.max' => 'La foto può pesare al massimo 4 MB.',
            'max' => 'Il campo «:attribute» è troppo lungo.', 'whatsapp.regex' => 'Il numero di telefono non è valido.',
        ], ['display_name' => 'nome da mostrare', 'headline' => 'qualifica', 'bio' => 'presentazione', 'story' => 'la mia storia', 'whatsapp' => 'WhatsApp', 'email_public' => 'email', 'slug' => 'indirizzo']);
        foreach ((array) ($data['media'] ?? []) as $m) {
            if (filled($m['url'] ?? null) xor filled($m['title'] ?? null)) {
                return back()->withInput()->withErrors(['media' => 'Per ogni contenuto servono sia il titolo sia il link (oppure lascia vuota la riga).']);
            }
            $media[] = $m;
        }
        try {
            $svc->setSlug($page, (string) $data['slug']);
            $svc->save($page->refresh(), ['media' => $media] + $data, $request->file('photo'), $request->boolean('remove_photo'));
        } catch (PageException $e) {
            return back()->withInput()->withErrors(['slug' => $e->getMessage()]);
        }

        return back()->with('status', $svc->approvalRequired()
            ? 'Modifiche salvate e inviate al Consorzio per l\'approvazione. Finché non sono approvate, il pubblico vede la versione precedente.'
            : 'Modifiche salvate: la pagina è aggiornata.');
    }

    public function online(Request $request, PersonalPageService $svc): RedirectResponse
    {
        $c = $this->activeMine($request, $svc);
        $page = $svc->forConsultant($c);
        $online = $request->boolean('online');
        try {
            $svc->setOnline($page, $online);
        } catch (PageException $e) {
            return back()->withErrors(['online' => $e->getMessage()]);
        }
        $page->refresh();

        return back()->with('status', ! $online ? 'La pagina non è più visibile al pubblico.' : ($svc->isPublic($page) ? 'La pagina è online.' : 'Richiesta registrata: la pagina andrà online appena il Consorzio approva i contenuti.'));
    }

    /** L'incaricato chiede l'attivazione (o il rinnovo) della pagina dopo aver pagato su kmoney.it. */
    public function requestActivation(Request $request, PersonalPageService $svc): RedirectResponse
    {
        $c = $this->mine($request, $svc);
        $data = $request->validate(['kmoney_ref' => 'nullable|string|max:120']);
        try {
            $svc->requestActivation($svc->forConsultant($c), $data['kmoney_ref'] ?? null);
        } catch (PageException $e) {
            return back()->withErrors(['activation' => $e->getMessage()]);
        }

        return back()->with('status', 'Richiesta inviata: appena il Consorzio verifica il pagamento la pagina viene attivata e ricevi una email.');
    }
    /** «Paga con KMoney»: apre la richiesta di pagamento sul conto di KNM e manda l'incaricato alla pagina di pagamento di KMoney. */
    public function pay(Request $request, PersonalPageService $svc, PersonalPagePayment $payments)
    {
        $c = $this->mine($request, $svc);
        try {
            return redirect()->away($payments->start($c));
        } catch (PageException $e) {
            return back()->withErrors(['activation' => $e->getMessage()]);
        }
    }

    /**
     * Ritorno da KMoney (dopo il pagamento o l'annullamento). L'esito vero non sta nell'indirizzo: se l'avviso non è
     * ancora arrivato si chiede a KMoney com'è andata la richiesta.
     */
    public function payReturn(Request $request, PersonalPageService $svc, PersonalPagePayment $payments): RedirectResponse
    {
        $c = $this->mine($request, $svc);
        $page = $svc->find($c);
        if ($page === null) {
            return redirect('/area/pagina');
        }
        if ($request->query('esito') === 'annullato') {
            return redirect('/area/pagina')->with('status', 'Pagamento annullato: non è stato addebitato nulla. Puoi riprovare quando vuoi.');
        }
        $page->refresh();
        if ($svc->isActivated($page) && ! $svc->renewalDue($page)) {
            return redirect('/area/pagina')->with('status', 'Pagamento ricevuto: la tua pagina è attiva. Ti abbiamo inviato una email.');
        }
        $payments->confirmOnReturn($page);
        $page->refresh();
        if ($svc->isActivated($page) && ! $svc->renewalDue($page)) {
            return redirect('/area/pagina')->with('status', 'Pagamento ricevuto: la tua pagina è attiva. Ti abbiamo inviato una email.');
        }

        return redirect('/area/pagina')->with('status', 'Stiamo aspettando la conferma da KMoney: la pagina si attiva da sola appena arriva, di solito in pochi istanti. Ricarica questa pagina tra poco.');
    }
}
