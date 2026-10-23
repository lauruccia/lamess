<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Addebito dell'abbonamento non riuscito: avvisa il cliente e gli indica come sistemare (carta, saldo KMoney o conferma su KMoney).
 * Il messaggio cambia a ogni tentativo: «non riuscito», poi «secondo avviso», poi «ultimo avviso prima della pausa», infine «in pausa».
 */
class SubscriptionChargeFailed extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @param  int  $retryDays  tra quanti giorni si riprova (0 = nessun nuovo tentativo programmato)
     * @param  ?string  $confirmUrl  link di KMoney per confermare l'addebito (se KMoney chiede la conferma del cliente)
     * @param  int  $attempt  quanti addebiti sono falliti finora
     * @param  int  $maxAttempts  fallimenti dopo i quali l'abbonamento va in pausa
     */
    public function __construct(public Subscription $subscription, public bool $paused, public int $retryDays, public ?string $confirmUrl = null, public int $attempt = 1, public int $maxAttempts = 3) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Ultimo avviso: il prossimo tentativo è l'ultimo, se fallisce l'abbonamento va in pausa. */
    private function isLastWarning(): bool
    {
        return ! $this->paused && $this->retryDays > 0 && $this->attempt >= max(1, $this->maxAttempts - 1) && $this->attempt > 1;
    }

    private function subject(): string
    {
        if ($this->paused) {
            return 'Il tuo abbonamento Lamèss è in pausa: addebito non riuscito';
        }
        if ($this->confirmUrl !== null && $this->attempt <= 1) {
            return 'Conferma l\'addebito del tuo abbonamento Lamèss';
        }
        if ($this->isLastWarning()) {
            return 'Ultimo avviso: il tuo abbonamento Lamèss andrà in pausa';
        }
        if ($this->attempt > 1 && $this->retryDays > 0) {
            return 'Secondo avviso: addebito del tuo abbonamento Lamèss ancora non riuscito';
        }

        return 'Addebito del tuo abbonamento Lamèss non riuscito';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $byCard = $this->subscription->payment_method !== 'kmoney';
        $mail = (new MailMessage)->subject($this->subject())->greeting('Ciao!');

        if ($this->confirmUrl !== null) {
            $mail->line('KMoney ha bisogno della tua conferma per addebitare il mese del tuo abbonamento: senza la tua conferma l\'addebito automatico non è possibile.')
                ->action('Conferma l\'addebito', $this->confirmUrl)
                ->line('Dopo la conferma l\'ordine si completa da solo, non devi fare altro.');
        } else {
            $mail->line($byCard
                ? 'Non siamo riusciti ad addebitare il mese del tuo abbonamento sulla tua carta. Può succedere se la carta è scaduta, il plafond è esaurito o la banca ha chiesto una conferma.'
                : 'Non siamo riusciti ad addebitare il mese del tuo abbonamento dal tuo conto KMoney. Controlla che il saldo sia sufficiente e che il conto sia ancora collegato.');
        }

        if ($this->paused) {
            return $mail->line('Dopo diversi tentativi l\'abbonamento è stato messo in pausa: non ti verrà addebitato nulla finché non lo riprendi.')
                ->action('Riprendi l\'abbonamento', url('/area/abbonamenti'));
        }

        if ($this->retryDays > 0) {
            $when = "Riproveremo automaticamente tra {$this->retryDays} ".($this->retryDays === 1 ? 'giorno' : 'giorni').'.';
            $mail->line($this->isLastWarning()
                ? $when.' Se anche quel tentativo non riesce, l\'abbonamento verrà messo in pausa e non ti addebiteremo più nulla finché non lo riprendi.'
                : $when);
        }
        if ($this->confirmUrl === null) {
            $mail->action($byCard ? 'Aggiorna la carta' : 'Controlla l\'abbonamento', url('/area/abbonamenti'));
        }

        return $mail->line($byCard ? 'Dalla pagina dell\'abbonamento puoi cambiare carta in modo sicuro: dopo il cambio riproviamo subito l\'addebito.' : 'Se il saldo è stato ricaricato o hai confermato l\'addebito, l\'abbonamento riparte al prossimo tentativo.');
    }
}
