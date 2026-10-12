<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Support\SupportException;
use App\Domain\Support\TicketService;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Assistenza per FAN e incaricati: ogni utente vede e scrive solo sui propri ticket. */
class SupportController extends Controller
{
    private function mine(Request $request, Ticket $ticket): Ticket
    {
        abort_unless((int) $ticket->user_id === (int) $request->user()->id, 404);

        return $ticket;
    }

    public function index(Request $request)
    {
        $tickets = Ticket::where('user_id', $request->user()->id)->orderByDesc('last_message_at')->get();

        return view('site.area.tickets', ['tickets' => $tickets, 'categories' => array_intersect_key(Ticket::CATEGORIES, array_flip(Ticket::USER_CATEGORIES))]);
    }

    public function store(Request $request, TicketService $svc): RedirectResponse
    {
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', Ticket::USER_CATEGORIES), 'subject' => 'required|string|max:190', 'body' => 'required|string|max:5000',
        ], SiteController::MESSAGES);
        try {
            $t = $svc->open($request->user(), $data['category'], $data['subject'], $data['body']);
        } catch (SupportException $e) {
            return back()->withErrors(['body' => $e->getMessage()])->withInput();
        }

        return redirect("/area/assistenza/{$t->id}")->with('status', 'Richiesta inviata. Ti risponderemo da questa pagina.');
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->mine($request, $ticket);

        return view('site.area.ticket', ['ticket' => $ticket, 'messages' => $ticket->messages()->where('is_internal', false)->with('user')->get()]);
    }

    public function reply(Request $request, Ticket $ticket, TicketService $svc): RedirectResponse
    {
        $this->mine($request, $ticket);
        $data = $request->validate(['body' => 'required|string|max:5000'], SiteController::MESSAGES);
        try {
            $svc->reply($ticket, $request->user(), $data['body'], false);
        } catch (SupportException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Messaggio inviato.');
    }

    public function close(Request $request, Ticket $ticket, TicketService $svc): RedirectResponse
    {
        $this->mine($request, $ticket);
        // un reclamo su estratto lo chiude solo l'assistenza con una decisione
        abort_if($ticket->category === 'statement_dispute' && $ticket->status !== Ticket::CLOSED, 403);
        $svc->close($ticket);

        return back()->with('status', 'Richiesta chiusa.');
    }
}
