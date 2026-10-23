<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Promemoria prima dell'addebito dell'abbonamento: data, importo indicativo e cosa controllare per evitare un errore di pagamento. */
class SubscriptionChargeReminder extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Subscription $subscription, public ?string $amount = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $byCard = $this->subscription->payment_method !== Subscription::METHOD_KMONEY;
        $date = $this->subscription->next_run_at?->setTimezone('Europe/Rome')->format('d/m/Y');
        $mail = (new MailMessage)->subject('Tra pochi giorni il prossimo addebito del tuo abbonamento Lamèss')
            ->greeting('Ciao!')
            ->line("Il {$date} ti addebiteremo il prossimo ordine del tuo abbonamento".($this->amount !== null ? ' (importo indicativo: € '.$this->amount.', IVA inclusa).' : '.'));
        $mail->line($byCard
            ? 'Controlla che la carta salvata sia valida e abbia disponibilità sufficiente, così l\'addebito va a buon fine.'
            : 'Controlla che sul tuo conto KMoney ci sia saldo sufficiente e che il conto sia ancora collegato.');

        // Con questo ordine si completa l'impegno (es. 12 mesi): da qui l'abbonamento prosegue finché il cliente non lo interrompe.
        $sub = $this->subscription;
        if ((int) $sub->commitment_months > 0 && (int) $sub->cycles_done === $sub->commitmentCycles()) {
            $mail->line("Hai completato i {$sub->commitment_months} mesi di abbonamento, grazie! L'abbonamento prosegue ".$sub->cadenceLabel().' e puoi interromperlo quando vuoi, senza costi.');
        }

        return $mail->line('Se vuoi saltare questo mese, cambiare indirizzo o carta, o mettere in pausa l\'abbonamento, puoi farlo prima della data di addebito.')
            ->action('Gestisci il tuo abbonamento', url('/area/abbonamenti'));
    }
}
