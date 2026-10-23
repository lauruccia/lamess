<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Riepilogo giornaliero per l'amministrazione: addebiti falliti, conferme KMoney, pause, disdette e primi pagamenti fermi. */
class SubscriptionsAdminDigest extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @param array<string,list<array<string,string>>> $sections */
    public function __construct(public array $sections) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $n = array_sum(array_map('count', $this->sections));
        $mail = (new MailMessage)->subject("Lamèss: {$n} da seguire tra abbonamenti e pagamenti")->greeting('Riepilogo giornaliero');
        foreach ($this->sections as $title => $rows) {
            $mail->line('**'.$title.' ('.count($rows).')**');
            foreach ($rows as $r) {
                $mail->line("- {$r['cliente']} · {$r['metodo']} · {$r['dettaglio']}");
            }
        }

        return $mail->action('Apri gli abbonamenti', SubscriptionResource::getUrl('index'));
    }
}
