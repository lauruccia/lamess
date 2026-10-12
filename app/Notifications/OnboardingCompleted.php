<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Consultant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingCompleted extends Notification
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
            ->subject('Onboarding completato')
            ->line('Hai completato l\'onboarding: da ora puoi maturare tutte le commissioni previste e richiedere i pagamenti.');
    }
}
