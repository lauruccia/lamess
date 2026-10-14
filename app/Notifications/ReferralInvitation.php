<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Customers\ReferralInviteService;
use App\Models\ReferralInvite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email di invito inviata dal portale a nome di un incaricato, con il link personale dell'invito. */
class ReferralInvitation extends Notification
{
    public function __construct(public readonly ReferralInvite $invite, public readonly bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->invite->consultant->name;
        $name = trim((string) $this->invite->recipient_name);

        return (new MailMessage)
            ->subject($this->reminder ? "Promemoria: l'invito di {$from} su Lamèss" : "{$from} ti invita su Lamèss")
            ->greeting($name !== '' ? "Ciao {$name}," : 'Ciao,')
            ->line("{$from} ti ha invitato a registrarti su Lamèss.")
            ->line('La registrazione è gratuita: dal link qui sotto crei il tuo account e potrai ordinare i prodotti dal negozio.')
            ->action('Registrati su Lamèss', app(ReferralInviteService::class)->url($this->invite))
            ->line('Se non conosci questa persona o non ti interessa, puoi ignorare questa email: non succede nulla.');
    }
}
