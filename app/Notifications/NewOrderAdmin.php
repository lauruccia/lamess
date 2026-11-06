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

/** Avviso all'amministrazione: nuovo ordine o esito del pagamento (A: LAMESS_ORDINI_MAIL_TO oppure admin e super admin; Cc: LAMESS_ORDINI_MAIL_CC; Ccn: LAMESS_ORDINI_MAIL_BCC). */
class NewOrderAdmin extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @param string|null $paymentStatus esito noto (es. «Pagamento rifiutato»); se nullo lo stato si ricava dall'ordine */
    public function __construct(public Order $order, public ?string $paymentStatus = null) {}

    /** Stato del pagamento in chiaro per l'amministrazione. */
    public function paymentStatusLabel(): string
    {
        if ($this->paymentStatus !== null) {
            return $this->paymentStatus;
        }
        $o = $this->order;

        return match (true) {
            in_array($o->status, ['paid', 'processing', 'shipped', 'delivered'], true) => 'Pagamento accettato',
            $o->status === 'cancelled' => 'Ordine annullato',
            $o->payment_method === 'transfer' || $o->kmoney_rest_method === 'transfer' => 'In attesa di bonifico',
            $o->payments()->where('status', 'failed')->exists() => 'Pagamento rifiutato',
            default => 'In attesa di pagamento',
        };
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $o = $this->order;
        $method = OrderService::methodLabel((string) $o->payment_method).($o->kmoney_rest_method ? ' + '.OrderService::methodLabel((string) $o->kmoney_rest_method) : '');
        $status = $this->paymentStatusLabel();
        $mail = (new MailMessage)->subject($this->paymentStatus === null ? "Nuovo ordine {$o->number} - {$status}" : "Ordine {$o->number} - {$status}")
            ->greeting($this->paymentStatus === null ? 'Nuovo ordine ricevuto' : 'Aggiornamento sul pagamento')
            ->line("**Ordine:** {$o->number}")
            ->line('**Cliente:** '.($o->buyer?->name ?? '—').($o->buyer?->email ? " ({$o->buyer->email})" : ''))
            ->line('**Totale:** € '.Money::eur($o->total))
            ->line("**Pagamento:** {$method}")
            ->line("**Stato del pagamento:** {$status}");
        if (($cc = OrderService::mailList('lamess.orders.admin_mail_cc')) !== []) {
            $mail->cc($cc);
        }
        if (($bcc = OrderService::mailList('lamess.orders.admin_mail_bcc')) !== []) {
            $mail->bcc($bcc);
        }
        foreach ($o->items->whereNull('parent_item_id') as $it) {
            $mail->line("• {$it->qty} × {$it->name}".($it->is_gift ? ' (omaggio)' : ''));
        }

        return $mail->action('Apri l\'ordine', OrderResource::getUrl('view', ['record' => $o]));
    }
}
