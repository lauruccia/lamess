<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractTerminated extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lettera di Incarico risolta')
            ->line('La tua Lettera di Incarico è stata risolta. Motivo: '.$this->reason.'.')
            ->line('Il tuo account prosegue come Cliente.');
    }
}
