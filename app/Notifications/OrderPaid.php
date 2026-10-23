<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\Subscription;
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
            ->line("Abbiamo ricevuto il pagamento di € {$this->order->total} per l'ordine {$this->order->number}.");
        // Ordine di un abbonamento: dice quando sarà il prossimo addebito, così il cliente non ha sorprese.
        $sub = $this->order->subscription;
        if ($sub !== null && $sub->status !== Subscription::CANCELLED) {
            $done = $sub->orders()->whereIn('status', Order::PAID_STATES)->count();
            $mail->line('Il prossimo addebito del tuo abbonamento sarà il '.$sub->nextChargeAfter($done)->setTimezone('Europe/Rome')->format('d/m/Y').'. Puoi saltare un mese, cambiare indirizzo o carta, mettere in pausa o interrompere quando vuoi dalla tua area.');
        }

        return $mail->action('Vedi i tuoi ordini', url('/area/ordini'));
    }
}
