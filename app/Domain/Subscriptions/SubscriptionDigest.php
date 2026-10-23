<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use App\Domain\Access\Roles;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionsAdminDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Riepilogo giornaliero degli abbonamenti per l'amministrazione: parte solo se c'è qualcosa da seguire.
 * Destinatari: LAMESS_ABBONAMENTI_MAIL_TO (elenco separato da virgole) oppure, se vuoto, gli utenti admin e super admin.
 */
final class SubscriptionDigest
{
    /** @return array<string,list<array<string,string>>> sezioni con almeno una riga */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $day = $now->subDay();
        $row = fn (Subscription $s, string $detail) => [
            'cliente' => (string) ($s->user?->name ?? '—').($s->user?->email ? ' ('.$s->user->email.')' : ''),
            'metodo' => $s->payment_method === Subscription::METHOD_KMONEY ? 'KMoney' : 'Carta',
            'dettaglio' => $detail,
        ];
        $when = fn ($d) => $d === null ? '—' : $d->setTimezone('Europe/Rome')->format('d/m/Y H:i');
        $max = (int) config('lamess.subscription.max_retries');

        $sections = [
            'Addebiti non riusciti (si riprova da soli)' => Subscription::where('status', Subscription::ACTIVE)->where('retry_count', '>', 0)
                ->where(fn ($q) => $q->whereNull('last_error')->orWhere('last_error', '!=', SubscriptionService::CONFIRM))->with('user')->orderBy('id')->get()
                ->map(fn (Subscription $s) => $row($s, "tentativo {$s->retry_count}/{$max} · prossimo ".$when($s->next_run_at).' · '.($s->last_error ?? '—')))->all(),
            'In attesa della conferma del cliente su KMoney' => Subscription::where('status', Subscription::ACTIVE)->where('last_error', SubscriptionService::CONFIRM)->with('user')->orderBy('id')->get()
                ->map(fn (Subscription $s) => $row($s, "avvisi inviati {$s->retry_count} · prossimo tentativo ".$when($s->next_run_at).' · puoi rimandare l\'email dalla scheda abbonamenti'))->all(),
            'Messi in pausa nelle ultime 24 ore' => Subscription::where('status', Subscription::PAUSED)->where('paused_at', '>=', $day)->with('user')->orderBy('id')->get()
                ->map(fn (Subscription $s) => $row($s, 'in pausa dal '.$when($s->paused_at).' · '.($s->last_error ?? 'sospeso')))->all(),
            'Disdette nelle ultime 24 ore' => Subscription::where('status', Subscription::CANCELLED)->where('cancelled_at', '>=', $day)->with('user')->orderBy('id')->get()
                ->map(fn (Subscription $s) => $row($s, ($s->early_cancel ? 'DISDETTA ANTICIPATA, ' : '').$s->cycles_done.' cicli pagati · '.($s->cancel_reason ?? '—')))->all(),
            'In attesa del primo pagamento da oltre 48 ore' => Subscription::where('status', Subscription::PENDING)->where('created_at', '<=', $now->subHours(48))->with('user')->orderBy('id')->get()
                ->map(fn (Subscription $s) => $row($s, 'creato il '.$when($s->created_at).' · verifica su Stripe dal dettaglio ordine'))->all(),
        ];

        return array_filter($sections, fn (array $rows) => $rows !== []);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) config('lamess.subscription.admin_mail_to')))));
        if ($list !== []) {
            return $list;
        }

        return User::role([Roles::ADMIN, Roles::SUPER_ADMIN])->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    /** @return int 1 se l'email è partita, 0 se non c'era nulla da segnalare o nessun destinatario */
    public function send(?CarbonImmutable $now = null): int
    {
        $sections = $this->build($now);
        $to = $this->recipients();
        if ($sections === [] || $to === []) {
            return 0;
        }
        Notification::route('mail', $to)->notify(new SubscriptionsAdminDigest($sections));

        return 1;
    }
}
