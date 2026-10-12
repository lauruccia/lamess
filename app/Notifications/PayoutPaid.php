<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payout;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avviso a un INCARICATO: il bonifico dei suoi compensi è stato effettuato. */
class PayoutPaid extends Notification
{
    public function __construct(public readonly Payout $payout) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Bonifico dei compensi effettuato')
            ->line("Abbiamo pagato € {$this->payout->amount} con bonifico (provvigioni fino a {$this->payout->period->code}).")
            ->action('Vedi i tuoi compensi', url('/area/statini'));
    }
}
