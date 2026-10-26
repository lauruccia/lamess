<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Orders\OrderService;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avviso all'amministrazione: è arrivato un nuovo ordine (destinatari: LAMESS_ORDINI_MAIL_TO oppure admin e super admin). */
class NewOrderAdmin extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $o = $this->order;
        $method = OrderService::methodLabel((string) $o->payment_method).($o->kmoney_rest_method ? ' + '.OrderService::methodLabel((string) $o->kmoney_rest_method) : '');
        $mail = (new MailMessage)->subject("Nuovo ordine {$o->number}")
            ->greeting('Nuovo ordine ricevuto')
            ->line("**Ordine:** {$o->number}")
            ->line('**Cliente:** '.($o->buyer?->name ?? '—').($o->buyer?->email ? " ({$o->buyer->email})" : ''))
            ->line('**Totale:** € '.Money::eur($o->total))
            ->line("**Pagamento:** {$method}");
        foreach ($o->items->whereNull('parent_item_id') as $it) {
            $mail->line("• {$it->qty} × {$it->name}".($it->is_gift ? ' (omaggio)' : ''));
        }

        return $mail->action('Apri l\'ordine', OrderResource::getUrl('view', ['record' => $o]));
    }
}
