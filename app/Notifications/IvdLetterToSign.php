<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\IvdLetterVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Richiesta di firmare la Lettera di Incarico (nuova versione o prima firma sul portale). */
class IvdLetterToSign extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly IvdLetterVersion $version, public readonly bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->reminder ? 'Promemoria: ' : '').'Firma la Lettera di Incarico Lamèss')
            ->greeting('Ciao '.$notifiable->name.',')
            ->line('devi firmare la Lettera di Incarico (versione '.$this->version->version.') con i documenti allegati.');
        if (filled($this->version->change_summary)) {
            $mail->line('**Cosa cambia:** '.$this->version->change_summary);
        }

        return $mail
            ->line('Basta leggerla, spuntare le accettazioni e inserire il codice che ti arriva per email: ci vogliono pochi minuti.')
            ->action('Leggi e firma', url('/area/lettera'));
    }
}
