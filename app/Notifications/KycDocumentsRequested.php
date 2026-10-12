<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KycRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycDocumentsRequested extends Notification
{
    public function __construct(public readonly KycRequest $request, public readonly bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->reminder ? 'Promemoria: ' : '').'documenti richiesti per la verifica del tuo profilo')
            ->line('Per completare la verifica del tuo profilo ci servono questi documenti:');
        foreach ($this->request->labels() as $label) {
            $mail->line('• '.$label);
        }
        if (filled($this->request->message)) {
            $mail->line('Messaggio dell’amministrazione: '.$this->request->message);
        }
        if ($this->request->due_at !== null) {
            $mail->line('Ti chiediamo di caricarli entro il '.$this->request->due_at->setTimezone('Europe/Rome')->format('d/m/Y').'.');
        }

        return $mail->action('Carica i documenti', url('/area/onboarding'));
    }
}
