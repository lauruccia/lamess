<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** Email di recupero password in italiano (il link porta alla pagina pubblica /password-reset/{token}). */
final class ResetPasswordIt extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Lamèss — recupero della password')
            ->greeting('Ciao!')
            ->line('Hai chiesto di reimpostare la password del tuo account Lamèss.')
            ->action('Scegli una nuova password', $this->resetUrl($notifiable))
            ->line("Il link vale {$minutes} minuti. Se non l'hai chiesto tu, ignora questo messaggio: la tua password non cambia.")
            ->salutation('Lamèss');
    }
}
