<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KycDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycReviewed extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly KycDocument $document, public readonly bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $doc = $this->document;
        $label = config('lamess.kyc_document_types')[$doc->type] ?? $doc->type;
        if ($doc->status === 'approved') {
            return (new MailMessage)
                ->subject('Verifica documenti: approvato')
                ->line('Documento: '.$label)
                ->line('Il documento è stato approvato.')
                ->action('Vai alla tua area', url('/area/onboarding'));
        }

        $mail = (new MailMessage)
            ->subject(($this->reminder ? 'Promemoria: ' : '').'documento da correggere: '.$label)
            ->line($this->reminder
                ? 'Il documento «'.$label.'» che ci hai inviato non è stato accettato e non lo abbiamo ancora ricevuto di nuovo.'
                : 'Il documento «'.$label.'» che ci hai inviato non è stato accettato.')
            ->line('Motivo: '.($doc->notes ?: 'ricaricalo seguendo le indicazioni.'));
        $hint = (string) (config('lamess.kyc_reject_reasons')[$doc->reject_reason]['hint'] ?? '');
        if ($hint !== '') {
            $mail->line('Cosa fare: '.$hint);
        }

        return $mail
            ->line('Come ricaricarlo: accedi alla tua area, apri «Onboarding» e carica il file al posto di quello rifiutato (JPG, PNG o PDF). Finché non è approvato non puoi ricevere pagamenti.')
            ->action('Ricarica il documento', url('/area/onboarding'));
    }
}
