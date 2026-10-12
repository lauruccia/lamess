<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Content\Audience;
use App\Domain\Content\ContentException;
use App\Domain\Content\DownloadService;
use App\Domain\Content\EventService;
use App\Domain\Content\ReferralQr;
use App\Models\CorporateEvent;
use App\Models\Download;
use App\Models\NewsPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Novità, materiali, eventi e referral nell'area personale. */
class ContentController extends Controller
{
    public function news(Request $request)
    {
        $posts = NewsPost::live()->whereIn('audience', Audience::visibleTo($request->user()))->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->get();

        return view('site.area.news', ['posts' => $posts]);
    }

    public function newsShow(Request $request, string $slug)
    {
        $post = NewsPost::live()->where('slug', $slug)->firstOrFail();
        abort_unless(Audience::allows($post->audience, $request->user()), 404);

        return view('site.area.news-show', ['post' => $post]);
    }

    public function downloads(Request $request, DownloadService $svc)
    {
        $user = $request->user();
        $items = $svc->visibleTo($user)->map(fn (Download $d) => ['d' => $d, 'ok' => $svc->canDownload($d, $user)]);

        return view('site.area.downloads', ['items' => $items->groupBy(fn ($i) => $i['d']->category ?: 'Altro')]);
    }

    public function download(Request $request, Download $download, DownloadService $svc)
    {
        try {
            $f = $svc->prepare($download, $request->user(), $request->ip());
        } catch (ContentException $e) {
            return redirect('/area/materiali')->withErrors(['download' => $e->getMessage()]);
        }

        return new Response($f['content'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $f['name']).'"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function events(Request $request, EventService $svc)
    {
        $user = $request->user();
        $events = CorporateEvent::whereIn('status', ['published', 'cancelled'])->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->get()
            ->filter(fn (CorporateEvent $e) => Audience::allows($e->audience, $user))->values();

        return view('site.area.events', ['events' => $events, 'svc' => $svc, 'user' => $user]);
    }

    public function register(Request $request, CorporateEvent $event, EventService $svc): RedirectResponse
    {
        try {
            $r = $svc->register($event, $request->user());
        } catch (ContentException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return back()->with('status', $r->status === 'waitlist' ? 'I posti sono esauriti: sei in lista d\'attesa e ti iscriviamo se si libera un posto.' : 'Iscrizione registrata.');
    }

    public function unregister(Request $request, CorporateEvent $event, EventService $svc): RedirectResponse
    {
        try {
            $svc->cancel($event, $request->user());
        } catch (ContentException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return back()->with('status', 'Iscrizione annullata.');
    }

    public function referral(Request $request)
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active' && $c->referral_code, 404);

        return view('site.area.referral', ['url' => ReferralQr::url($c), 'svg' => ReferralQr::svg($c, 280), 'code' => $c->referral_code]);
    }

    public function referralQr(Request $request): Response
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active' && $c->referral_code, 404);
        $h = ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=300'];
        if ($request->boolean('scarica')) {
            $h['Content-Disposition'] = 'attachment; filename="qr-referral-'.$c->referral_code.'.svg"';
        }

        return new Response(ReferralQr::svg($c, 600), 200, $h);
    }
}
