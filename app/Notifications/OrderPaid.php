<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaid extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Ordine {$this->order->number}: pagamento ricevuto")
            ->greeting('Grazie per il tuo ordine!')
            ->line("Abbiamo ricevuto il pagamento di {$this->paidText()} per l'ordine {$this->order->number}.")
            ->line('Stiamo preparando la tua spedizione: appena il pacco sarà affidato al corriere riceverai un\'altra email con il codice di tracciamento.');
        // Ordine di un abbonamento: dice quando sarà il prossimo addebito, così il cliente non ha sorprese.
        $sub = $this->order->subscription;
        if ($sub !== null && $sub->status !== Subscription::CANCELLED) {
            $done = $sub->orders()->whereIn('status', Order::PAID_STATES)->count();
            $mail->line('Il prossimo addebito del tuo abbonamento sarà il '.$sub->nextChargeAfter($done)->setTimezone('Europe/Rome')->format('d/m/Y').'. Puoi saltare un mese, cambiare indirizzo o carta, mettere in pausa o interrompere quando vuoi dalla tua area.');
        }

        return $mail->action('Vedi i tuoi ordini', url('/area/ordini'));
    }

    /** Importo pagato nella valuta scelta: «1.500 KY» per KMoney (senza decimali se interi), «€ 150,00» per carta e bonifico. */
    public function paidText(): string
    {
        $o = $this->order;
        if ($o->payment_method !== 'kmoney') {
            return '€ '.Money::eur($o->total);
        }
        $ky = Money::ky($o->kmoney_amount).' KY';

        return (float) $o->restDue() > 0.004 ? "{$ky} (KMoney) più € ".Money::eur($o->restDue()).' '.($o->kmoney_rest_method === 'transfer' ? 'con bonifico' : 'con carta') : $ky;
    }
}
