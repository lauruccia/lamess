<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avviso a un INCARICATO: lo statino del mese chiuso è disponibile. */
class StatementReady extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly string $periodCode) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Il tuo statino di {$this->periodCode} è pronto")
            ->greeting('Ciao!')
            ->line("Il mese {$this->periodCode} è stato chiuso e il tuo statino dei compensi è disponibile.")
            ->action('Vedi lo statino', url('/area/statini/'.$this->periodCode));
    }
}
