<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Customers\ApplicationService;
use App\Models\MembershipApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Candidatura approvata: link personale per registrarsi. */
class MembershipApplicationApproved extends Notification implements ShouldQueueAfterCommit
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
            ->subject('La tua candidatura su Lamèss è stata approvata')
            ->greeting("Ciao {$a->first_name},")
            ->line('abbiamo valutato il tuo profilo: sei dei nostri. Dal link qui sotto crei il tuo account Lamèss.');
        if (filled($a->decision_note)) {
            $mail->line($a->decision_note);
        }
        if ($a->interest === 'consultant') {
            $mail->line('Hai detto di essere interessato a diventare incaricato alla vendita: una volta registrato troverai nella tua area la sezione per candidarti come incaricato.');
        }

        return $mail
            ->action('Crea il tuo account', app(ApplicationService::class)->url($a))
            ->line('Il link è personale e vale '.ApplicationService::LINK_DAYS.' giorni: usalo solo tu.');
    }
}
