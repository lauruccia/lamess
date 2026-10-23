<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** La carta salvata per l'abbonamento sta per scadere (o è già scaduta): il cliente la cambia prima che un addebito fallisca. */
class SubscriptionCardExpiring extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Subscription $subscription, public int $month, public int $year, public bool $expired = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = sprintf('%02d/%d', $this->month, $this->year);
        $next = $this->subscription->next_run_at?->setTimezone('Europe/Rome')->format('d/m/Y');

        return (new MailMessage)->subject($this->expired ? 'La carta del tuo abbonamento Lamèss è scaduta' : 'La carta del tuo abbonamento Lamèss sta per scadere')
            ->greeting('Ciao!')
            ->line($this->expired
                ? "La carta che hai salvato per l'abbonamento è scaduta ({$when}): il prossimo addebito non andrebbe a buon fine."
                : "La carta che hai salvato per l'abbonamento scade a {$when}.".($next ? " Il prossimo addebito è il {$next}." : ''))
            ->line('Aggiornala adesso in pochi secondi, in modo sicuro, per non interrompere le tue box.')
            ->action('Aggiorna la carta', url('/area/abbonamenti'));
    }
}
