<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Content\Audience;
use App\Domain\Content\EventService;
use App\Models\CorporateEvent;
use App\Models\Customer;
use App\Models\EventRegistration;
use App\Models\NewsPost;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** I numeri e le liste della panoramica dell'area utente: tutto in sola lettura, calcolato al volo. */
final class AreaDashboard
{
    public function __construct(private readonly EventService $events) {}

    /**
     * @return array{
     *   orders: array{count: int, spent: string, pending: Collection<int, Order>, recent: Collection<int, Order>},
     *   subs: array{active: int, next: ?CarbonInterface, failed: ?Subscription, list: Collection<int, Subscription>},
     *   tickets: array{open: int, answered: int},
     *   events: Collection<int, array{event: CorporateEvent, mine: ?EventRegistration}>,
     *   news: Collection<int, NewsPost>,
     *   profile: array{percent: int, missing: list<string>},
     * }
     */
    public function for(User $user): array
    {
        $orders = Order::where('buyer_user_id', $user->id);
        $subs = Subscription::where('user_id', $user->id)->whereIn('status', [Subscription::ACTIVE, Subscription::PAUSED])->orderBy('next_run_at')->get();
        $active = $subs->where('status', Subscription::ACTIVE);

        return [
            'orders' => [
                'count' => (clone $orders)->count(),
                'spent' => (string) (clone $orders)->whereIn('status', \App\Models\Order::PAID_STATES)->sum('total'),
                'pending' => (clone $orders)->where('status', 'pending_payment')->latest('id')->get(),
                'recent' => (clone $orders)->latest('id')->limit(3)->get(),
            ],
            'subs' => [
                'active' => $active->count(),
                'next' => $active->pluck('next_run_at')->filter()->sort()->first(),
                'failed' => $active->first(fn (Subscription $s) => filled($s->last_error)),
                'list' => $subs,
            ],
            'tickets' => [
                'open' => Ticket::where('user_id', $user->id)->where('status', Ticket::OPEN)->count(),
                'answered' => Ticket::where('user_id', $user->id)->where('status', Ticket::ANSWERED)->count(),
            ],
            'events' => $this->upcomingEvents($user),
            'news' => NewsPost::live()->whereIn('audience', Audience::visibleTo($user))->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get(),
            'profile' => $this->profileCompleteness($user),
        ];
    }

    /** @return Collection<int, array{event: CorporateEvent, mine: ?EventRegistration}> */
    private function upcomingEvents(User $user): Collection
    {
        return CorporateEvent::where('status', 'published')->where('starts_at', '>=', now())->orderBy('starts_at')->limit(12)->get()
            ->filter(fn (CorporateEvent $e) => Audience::allows($e->audience, $user))
            ->take(3)->values()
            ->map(fn (CorporateEvent $e) => ['event' => $e, 'mine' => $this->events->mine($e, $user)]);
    }

    /** @return array{percent: int, missing: list<string>} */
    private function profileCompleteness(User $user): array
    {
        $p = $user->profile;
        $wanted = ['phone' => 'telefono', 'address' => 'indirizzo', 'city' => 'città', 'zip' => 'CAP', 'province' => 'provincia', 'tax_code' => 'codice fiscale'];
        if ($p === null) {
            return ['percent' => 0, 'missing' => array_values($wanted)];
        }
        $wanted = $p->type === 'company'
            ? ['company_name' => 'ragione sociale', 'vat_number' => 'partita IVA'] + $wanted
            : ['first_name' => 'nome', 'last_name' => 'cognome'] + $wanted;
        $missing = [];
        foreach ($wanted as $field => $label) {
            if (blank($p->{$field})) {
                $missing[] = $label;
            }
        }

        return ['percent' => (int) round((count($wanted) - count($missing)) / count($wanted) * 100), 'missing' => $missing];
    }

    /**
     * Numeri in più per chi è incaricato.
     *
     * @return array{clients: int, network: int, direct: int, settlement: ?Settlement}
     */
    public function forConsultant(int $consultantId): array
    {
        $paths = DB::table('network_paths')->where('ancestor_id', $consultantId)->where('depth', '>', 0);

        return [
            'clients' => Customer::where('assigned_consultant_id', $consultantId)->count(),
            'network' => (clone $paths)->count(),
            'direct' => (clone $paths)->where('depth', 1)->count(),
            'settlement' => Settlement::with('period')->where('consultant_id', $consultantId)->get()->sortByDesc(fn (Settlement $r) => $r->period->code)->first(),
        ];
    }
}
