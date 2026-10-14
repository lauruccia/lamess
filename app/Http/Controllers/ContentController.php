<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Content\Audience;
use App\Domain\Content\ContentException;
use App\Domain\Content\DownloadService;
use App\Domain\Content\EventService;
use App\Domain\Content\ReferralQr;
use App\Domain\Customers\ReferralInviteException;
use App\Domain\Customers\ReferralInviteService;
use App\Models\CorporateEvent;
use App\Models\Download;
use App\Models\NewsPost;
use App\Models\ReferralInvite;
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

    public function referral(Request $request, ReferralInviteService $invites)
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active', 404);

        $invites->expireOverdue($c);
        $filter = (string) $request->query('stato', 'tutti');
        $rows = ReferralInvite::query()->where('consultant_id', $c->id)->withFirstPaidAt()
            ->when($filter === 'attesa', fn ($q) => $q->whereIn('status', ['created', 'sent', 'opened']))
            ->when($filter === 'registrati', fn ($q) => $q->where('status', 'registered'))
            ->when($filter === 'scaduti', fn ($q) => $q->whereIn('status', ['expired', 'failed']))
            ->latest('id')->get();

        return view('site.area.referral', [
            'url' => ReferralQr::url($c), 'pretty' => ReferralQr::pretty($c), 'svg' => ReferralQr::svg($c, 280), 'code' => $c->referral_code,
            'rows' => $rows, 'stats' => $invites->stats($c), 'filter' => $filter, 'invites' => $invites,
        ]);
    }

    public function inviteStore(Request $request, ReferralInviteService $invites): RedirectResponse
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active', 404);
        $data = $request->validate([
            'channel' => 'required|in:email,whatsapp,link',
            'recipient_name' => 'nullable|string|max:120',
            'email' => 'required_if:channel,email|nullable|email|max:190',
            'phone' => ['required_if:channel,whatsapp', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{6,30}$/'],
        ], [
            'channel.required' => 'Scegli come vuoi invitare la persona.', 'email.required_if' => 'Inserisci l\'email della persona.', 'email.email' => 'L\'email non è valida.',
            'phone.required_if' => 'Inserisci il numero di telefono per WhatsApp.', 'phone.regex' => 'Il numero di telefono non è valido.',
        ]);
        try {
            $invite = $invites->create($c, $data);
        } catch (ReferralInviteException $e) {
            return back()->withInput()->withErrors(['invite' => $e->getMessage()]);
        }
        $back = redirect('/area/referral')->with('invite_link', $invites->url($invite));
        if ($invite->channel === 'whatsapp') {
            return $back->with('status', 'Invito creato. Apri WhatsApp per mandare il messaggio.')->with('invite_wa', $invites->whatsappUrl($invite));
        }
        if ($invite->channel === 'link') {
            return $back->with('status', 'Invito creato. Copia il link personale e mandalo alla persona.');
        }
        if ($invite->status === 'failed' || $invite->failure_reason !== null) {
            return $back->withErrors(['invite' => 'Invito creato ma l\'email non è partita: '.($invite->failure_reason ?? 'errore di invio').' Puoi mandare tu il link personale.']);
        }

        return $back->with('status', 'Invito inviato a '.$invite->email.'.');
    }

    public function inviteRemind(Request $request, ReferralInvite $invite, ReferralInviteService $invites): RedirectResponse
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active' && $invite->consultant_id === $c->id, 404);
        try {
            $sent = $invites->remind($invite);
        } catch (ReferralInviteException $e) {
            return back()->withErrors(['invite' => $e->getMessage()]);
        }
        if (! $sent) {
            return back()->withErrors(['invite' => 'L\'email non è partita: '.($invite->refresh()->failure_reason ?? 'errore di invio').' Puoi mandare tu il link personale.'])->with('invite_link', $invites->url($invite));
        }
        $back = back()->with('status', $invite->channel === 'email' ? 'Promemoria inviato a '.$invite->email.'.' : 'Invito segnato come rimandato.');

        return $invite->channel === 'whatsapp' ? $back->with('invite_wa', $invites->whatsappUrl($invite)) : $back;
    }

    public function referralQr(Request $request): Response
    {
        $c = $request->user()->consultant;
        abort_unless($c !== null && $c->status === 'active', 404);
        $c->ensureReferralCode();
        $h = ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=300'];
        if ($request->boolean('scarica')) {
            $h['Content-Disposition'] = 'attachment; filename="qr-referral-'.$c->referral_code.'.svg"';
        }

        return new Response(ReferralQr::svg($c, 600), 200, $h);
    }
}
