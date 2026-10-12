<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Consultant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeConsultant extends Notification
{
    public function __construct(public readonly Consultant $consultant) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Benvenuto in Lamèss')
            ->greeting('Benvenuto, '.$notifiable->name.'!')
            ->line('La tua domanda di incarico è stata accettata.')
            ->line('Il tuo codice attività è '.$this->consultant->code.' e il tuo link referral è '.url('/r/'.$this->consultant->referral_code).'.')
            ->line('Per completare l\'onboarding carica foto e documento d\'identità e inserisci il tuo IBAN dall\'area riservata.')
            ->action('Vai alla tua area', url('/area'));
    }
}
