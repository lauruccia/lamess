<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\KycDocument;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycReviewed extends Notification
{
    public function __construct(public readonly KycDocument $document) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifica documenti: '.($this->document->status === 'approved' ? 'approvato' : 'da correggere'))
            ->line('Documento: '.(config('lamess.kyc_document_types')[$this->document->type] ?? $this->document->type))
            ->line($this->document->status === 'approved' ? 'Il documento è stato approvato.' : 'Il documento è stato rifiutato: '.($this->document->notes ?: 'ricaricalo seguendo le indicazioni.'))
            ->action('Vai alla tua area', url('/area/onboarding'));
    }
}
