<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaid extends Notification
{
    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Ordine {$this->order->number}: pagamento ricevuto")
            ->greeting('Grazie per il tuo ordine!')
            ->line("Abbiamo ricevuto il pagamento di € {$this->order->total} per l'ordine {$this->order->number}.")
            ->action('Vedi i tuoi ordini', url('/area/ordini'));
    }
}
