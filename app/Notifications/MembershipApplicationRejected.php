<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MembershipApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Candidatura non accettata: risposta cortese, con il motivo se l'amministrazione lo ha scritto. */
class MembershipApplicationRejected extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly MembershipApplication $application) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->application;
        $mail = (new MailMessage)
            ->subject('La tua candidatura su Lamèss')
            ->greeting("Ciao {$a->first_name},")
            ->line('grazie per l\'interesse verso Lamèss. Abbiamo valutato il tuo profilo e, per ora, non possiamo accoglierlo.');
        if (filled($a->decision_note)) {
            $mail->line($a->decision_note);
        }

        return $mail->line('Se vuoi saperne di più o credi che manchi qualcosa, rispondi pure a questa email o scrivici dalla pagina Contatti.');
    }
}
