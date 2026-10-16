<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Codice monouso per firmare la Lettera di Incarico. */
class SignatureOtpCode extends Notification
{
    public function __construct(public readonly string $code, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Il tuo codice per firmare la Lettera di Incarico')
            ->line('Il codice per firmare la tua Lettera di Incarico Lamèss è:')
            ->line("**{$this->code}**")
            ->line("Vale {$this->minutes} minuti e si può usare una sola volta. Non comunicarlo a nessuno.")
            ->line('Se non hai chiesto tu di firmare, ignora questa email.');
    }
}
