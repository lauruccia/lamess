<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification
{
    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Ordine {$this->order->number}: spedito")
            ->greeting('Il tuo ordine è in viaggio!')
            ->line("L'ordine {$this->order->number} è stato affidato al corriere".($this->order->carrier ? " {$this->order->carrier}" : '').'.');
        if (filled($this->order->tracking_code)) {
            $mail->line("Codice di tracciamento: {$this->order->tracking_code}");
        }

        return $mail->action('Vedi il tuo ordine', url("/area/ordini/{$this->order->id}"));
    }
}
