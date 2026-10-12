<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationRejected extends Notification
{
    public function __construct() {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Esito della tua domanda')
            ->line('Non è stato possibile accettare la tua domanda di incarico.')
            ->line('Il tuo account resta attivo come Cliente: puoi continuare ad acquistare e a usufruire delle convenzioni.');
    }
}
