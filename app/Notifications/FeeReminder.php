<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AnnualFee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeeReminder extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly AnnualFee $fee, public readonly int $daysLeft) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Canone annuale in scadenza')
            ->line('Il canone amministrativo annuale di '.number_format((float) $this->fee->amount, 2, ',', '.').' € scade tra '.$this->daysLeft.' giorni (il '.$this->fee->due_at->setTimezone('Europe/Rome')->format('d/m/Y').').')
            ->line('Se non viene pagato, la Lettera di Incarico si risolve automaticamente.');
    }
}
