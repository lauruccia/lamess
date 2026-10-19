<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Addebito mensile dell'abbonamento non riuscito: avvisa il cliente e gli indica come sistemare (carta o saldo KMoney). */
class SubscriptionChargeFailed extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Subscription $subscription, public bool $paused, public int $retryDays) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $byCard = $this->subscription->payment_method !== 'kmoney';
        $mail = (new MailMessage)->subject($this->paused ? 'Il tuo abbonamento Lamèss è in pausa: addebito non riuscito' : 'Addebito del tuo abbonamento Lamèss non riuscito')
            ->greeting('Ciao!')
            ->line($byCard
                ? 'Non siamo riusciti ad addebitare il mese del tuo abbonamento sulla tua carta. Può succedere se la carta è scaduta, il plafond è esaurito o la banca ha chiesto una conferma.'
                : 'Non siamo riusciti ad addebitare il mese del tuo abbonamento dal tuo conto KMoney. Controlla che il saldo sia sufficiente e che il conto sia ancora collegato.');
        if ($this->paused) {
            $mail->line('Dopo diversi tentativi l\'abbonamento è stato messo in pausa: non ti verrà addebitato nulla finché non lo riprendi.')
                ->action('Riprendi l\'abbonamento', url('/area/abbonamenti'));
        } else {
            $mail->line("Riproveremo automaticamente tra {$this->retryDays} ".($this->retryDays === 1 ? 'giorno' : 'giorni').'.')
                ->action($byCard ? 'Aggiorna la carta' : 'Controlla l\'abbonamento', url('/area/abbonamenti'));
        }

        return $mail->line($byCard ? 'Dalla pagina dell\'abbonamento puoi cambiare carta in modo sicuro: dopo il cambio riproviamo subito l\'addebito.' : 'Se il saldo è stato ricaricato, l\'addebito riparte al prossimo tentativo.');
    }
}
