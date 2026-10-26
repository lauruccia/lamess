<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dati per completare il pagamento di un ordine ancora in attesa: IBAN e causale per il bonifico,
 * oppure il link all'ordine per pagare con carta / confermare KMoney. Parte quando l'ordine con bonifico è registrato
 * e ogni volta che l'admin preme «Invia di nuovo i dati di pagamento».
 */
class OrderPaymentDetails extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public Order $order, public bool $reminder = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $o = $this->order;
        $url = url("/area/ordini/{$o->id}");
        $mail = (new MailMessage)->subject(($this->reminder ? 'Promemoria · ' : '')."Ordine {$o->number}: come completare il pagamento")
            ->greeting($this->reminder ? 'Il tuo ordine è ancora in attesa di pagamento' : 'Abbiamo registrato il tuo ordine')
            ->line("Ordine {$o->number}: l'ordine sarà confermato e preparato appena riceviamo il pagamento.");

        $kmoneyDue = $o->payment_method === 'kmoney' && ! $o->payments()->where('method', 'kmoney')->where('status', 'paid')->exists();
        $rest = $o->payment_method === 'kmoney' ? $o->restDue() : $o->total;
        $restMethod = $o->payment_method === 'kmoney' ? $o->kmoney_rest_method : $o->payment_method;
        $action = 'Vedi il tuo ordine';

        if ($kmoneyDue) {
            $mail->line('**Passo 1 · KMoney.** Conferma il pagamento di '.Money::ky($o->kmoney_amount).' KY dalla pagina dell\'ordine.');
            $action = 'Conferma il pagamento';
        }
        if ($o->payment_method === 'kmoney' && $restMethod === null && (float) $rest > 0.004) {
            $mail->line('Restano € '.Money::eur($rest).': dalla pagina dell\'ordine scegli se pagarli con carta o con bonifico.');
        } elseif ($restMethod === 'transfer') {
            $mail->line(($kmoneyDue ? '**Passo 2 · Bonifico.** ' : '**Bonifico.** ').'Fai un bonifico con questi dati:')
                ->line((string) config('lamess.bank_transfer_instructions'))
                ->line('**Importo:** € '.Money::eur($rest))
                ->line("**Causale:** {$o->number}");
        } elseif ($restMethod === 'card') {
            $mail->line(($kmoneyDue ? '**Passo 2 · Carta.** ' : '**Carta.** ').'Paga € '.Money::eur($rest).' con carta di credito o debito dalla pagina dell\'ordine: ci vuole un minuto.');
            $action = $kmoneyDue ? $action : 'Paga con carta';
        }

        return $mail->line('Per qualsiasi dubbio rispondi a questa email o apri un ticket dalla tua area.')->action($action, $url);
    }
}
