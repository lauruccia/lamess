<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ConsultantPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pagina personale: attivata dal Consorzio oppure attivazione in scadenza. */
class PersonalPageActivation extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @param  'activated'|'expiring'  $kind */
    public function __construct(public readonly ConsultantPage $page, public readonly string $kind) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $until = $this->page->activation_expires_at?->setTimezone('Europe/Rome')->format('d/m/Y');
        if ($this->kind === 'activated') {
            return (new MailMessage)->subject('La tua pagina personale è attiva')
                ->line('Il Consorzio ha attivato la tua pagina personale'.($until ? ' fino al '.$until : '').'.')
                ->line('Dalla tua area riservata puoi scrivere i testi, caricare la foto e metterla online.')
                ->action('Apri «La mia pagina»', url('/area/pagina'));
        }

        return (new MailMessage)->subject('La tua pagina personale sta per scadere')
            ->line('L\'attivazione della tua pagina personale scade il '.$until.'. Dopo quella data la pagina non sarà più visibile.')
            ->line('Per rinnovarla (un anno, '.number_format((float) config('lamess.personal_pages.price'), 2, ',', '.').' €) ricarica il conto KMoney se serve e acquista di nuovo il prodotto «Pagina Incaricato Lamèss» su KMoney, poi richiedi il rinnovo dalla tua area riservata.')
            ->action('Rinnova la pagina', url('/area/pagina'));
    }
}
