<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** Invito all'incaricato creato dall'admin: il link porta alla pagina pubblica /password-reset/{token} per scegliere la password. */
final class InvitoAccessoIt extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Lamèss — il tuo accesso al portale')
            ->greeting('Benvenuto in Lamèss!')
            ->line('È stato creato il tuo accesso al portale degli incaricati. Per entrare scegli la tua password.')
            ->action('Scegli la password', $this->resetUrl($notifiable))
            ->line("Il link vale {$minutes} minuti. Se è scaduto, vai su «Password dimenticata» nella pagina di accesso e ne ricevi uno nuovo.")
            ->salutation('Lamèss');
    }
}
