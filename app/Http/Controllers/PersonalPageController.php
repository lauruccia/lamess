<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pages\PageException;
use App\Domain\Pages\PersonalPageService;
use App\Models\Consultant;
use App\Models\ConsultantPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Pagina personale pubblica dell'incaricato (/i/{slug}) e la sua gestione dall'area riservata (/area/pagina). */
class PersonalPageController extends Controller
{
    private const URL_RULE = ['nullable', 'url:http,https', 'max:300'];

    public function show(string $slug, PersonalPageService $svc)
    {
        $page = $svc->findPublic($slug);
        abort_if($page === null, 404);
        ConsultantPage::whereKey($page->id)->increment('views');

        return $this->render($page, $svc, false);
    }

    private function render(ConsultantPage $page, PersonalPageService $svc, bool $preview)
    {
        $c = $page->consultant;
        $content = $svc->content($page, $preview);
        $wa = PersonalPageService::whatsappDigits($content['whatsapp']);

        return view('site.personal', [
            'page' => $page, 'c' => $c, 'd' => $content, 'preview' => $preview,
            'refUrl' => '/r/'.$c->ensureReferralCode(), 'wa' => $wa,
            'photoUrl' => $content['photo_path'] ? '/media/pagine/'.$page->id.'/'.basename((string) $content['photo_path']) : null,
            'shared' => (array) config('personal-page'),
            'socials' => array_filter(array_intersect_key($content, PersonalPageService::SOCIALS)),
        ]);
    }

    /** Foto della pagina: pubblica se la pagina è online, altrimenti solo per il proprietario e per chi in back-office controlla le pagine. */
    public function photo(Request $request, ConsultantPage $page, string $file, PersonalPageService $svc)
    {
        $paths = array_filter([$page->photo_path, $page->proposal['photo_path'] ?? null]);
        $path = collect($paths)->first(fn ($p) => basename((string) $p) === $file);
        abort_if($path === null || ! preg_match('/\.(jpe?g|png|webp)$/i', $file) || ! Storage::exists($path), 404);

        $user = $request->user();
        $isLive = $path === $page->photo_path && $svc->isPublic($page);
        $mayPreview = $user !== null && ($user->id === $page->consultant?->user_id || $user->can('compliance.view'));
        abort_unless($isLive || $mayPreview, 404);

        return Storage::response($path, null, ['Cache-Control' => $isLive ? 'public, max-age=3600' : 'private, no-store']);
    }

    // ------------------------------------------------------------ area riservata

    private function mine(Request $request, PersonalPageService $svc): Consultant
    {
        $c = $request->user()->consultant;
        abort_if($c === null || ! $svc->enabled() || ! $svc->eligible($c), 404);

        return $c;
    }

    public function edit(Request $request, PersonalPageService $svc)
    {
        $c = $this->mine($request, $svc);
        $page = $svc->forConsultant($c);

        return view('site.area.page', [
            'page' => $page, 'd' => $svc->content($page, true), 'svc' => $svc, 'c' => $c,
            'public' => $svc->isPublic($page), 'approval' => $svc->approvalRequired(),
            'url' => url('/i/'.$page->slug), 'defaultName' => $svc->defaultName($c),
        ]);
    }

    public function preview(Request $request, PersonalPageService $svc)
    {
        $c = $this->mine($request, $svc);

        return $this->render($svc->forConsultant($c), $svc, true);
    }

    public function save(Request $request, PersonalPageService $svc): RedirectResponse
    {
        $c = $this->mine($request, $svc);
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
        $c = $this->mine($request, $svc);
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
}
