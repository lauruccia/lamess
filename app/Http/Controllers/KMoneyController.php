<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\FakeKMoneyProvider;
use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\KMoneyLink;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/** «Accedi con KMoney»: collegamento del conto del cliente e ritorno dalle conferme; più il webhook firmato degli incassi. */
final class KMoneyController extends Controller
{
    /** Avvia il collegamento: il cliente si autentica e concede il mandato su KMoney, non qui. */
    public function connect(Request $r, KMoneyProvider $kmoney)
    {
        // «?da=profilo»: il collegamento parte dal riquadro «Conto KMoney» del profilo e al ritorno si torna lì.
        $fromProfile = $r->query('da') === 'profilo';

        if ($kmoney instanceof FakeKMoneyProvider) {
            // Solo sviluppo: collegamento simulato, nessuna credenziale reale.
            KMoneyLink::updateOrCreate(['user_id' => $r->user()->id], [
                'access_token' => 'fake', 'refresh_token' => null, 'expires_at' => now()->addYear(),
                'mandate_uuid' => 'FAKE-MANDATE', 'account_number' => 'KY-FAKE-'.str_pad((string) $r->user()->id, 6, '0', STR_PAD_LEFT),
            ]);

            return redirect($fromProfile ? '/area/profilo#kmoney' : '/area/checkout/pagamento')->with('status', 'KMoney collegato (simulazione di sviluppo).');
        }
        if (! $kmoney instanceof KMoneyApiProvider || ! $kmoney->enabled()) {
            return $fromProfile
                ? redirect('/area/profilo#kmoney')->withErrors(['kmoney' => 'Il collegamento a KMoney non è disponibile al momento.'])
                : redirect('/area/checkout/pagamento')->withErrors(['order' => 'Il collegamento a KMoney non è disponibile al momento.']);
        }
        $auth = $kmoney->authorization();
        $r->session()->put('kmoney_oauth', ['state' => $auth['state'], 'verifier' => $auth['verifier']]);
        $fromProfile ? $r->session()->put('kmoney_from', 'profilo') : $r->session()->forget('kmoney_from');

        return redirect()->away($auth['url']);
    }

    /** Ritorno da KMoney: o il codice del collegamento, o l'esito di una conferma di pagamento. */
    public function back(Request $r, KMoneyProvider $kmoney, OrderService $orders)
    {
        $orderId = $r->session()->pull('kmoney_order');
        $fromProfile = $r->session()->pull('kmoney_from') === 'profilo';

        // Ritorno dalla pagina del mandato su KMoney: ?mandate=granted|denied.
        if ($r->query('mandate') !== null) {
            return $this->afterMandate($r, $kmoney, $orderId, $fromProfile);
        }

        if ($r->filled('code')) {
            $saved = (array) $r->session()->pull('kmoney_oauth');
            if (! $kmoney instanceof KMoneyApiProvider || ! hash_equals((string) ($saved['state'] ?? ''), (string) $r->query('state'))) {
                return $fromProfile
                    ? redirect('/area/profilo#kmoney')->withErrors(['kmoney' => 'Collegamento a KMoney non valido: riprova.'])
                    : redirect('/area/ordini')->withErrors(['order' => 'Collegamento a KMoney non valido: riprova.']);
            }
            try {
                $link = $kmoney->connect($r->user(), (string) $r->query('code'), (string) ($saved['verifier'] ?? ''));
            } catch (OrderException $e) {
                return $fromProfile
                    ? redirect('/area/profilo#kmoney')->withErrors(['kmoney' => $e->getMessage()])
                    : $this->toOrder($orderId, $r)->withErrors(['order' => $e->getMessage()]);
            }
            if (! $link->mandate_uuid) {
                // Collegato ma senza mandato: il consenso OAuth non lo concede, serve la pagina del mandato su KMoney.
                // Si ricordano ordine e provenienza perché il cliente torna da fuori.
                return $this->sendToMandate($r, $kmoney, $orderId, $fromProfile);
            }

            return $this->linked($r, $orderId, $fromProfile);
        }

        // Ritorno da una conferma manuale: l'esito vero arriva dal webhook firmato, qui si rimanda all'ordine.
        // Il cliente ha già confermato su KMoney: finché non arriva l'esito la pagina dell'ordine non rimostra «Conferma e paga».
        if ($orderId) {
            $r->session()->put('kmoney_confirming', ['id' => (int) $orderId, 'at' => time()]);
        }

        return $this->toOrder($orderId, $r)->with('status', 'Stiamo confermando il pagamento con KMoney: l\'ordine si aggiorna entro pochi istanti.');
    }

    /** «Autorizza i pagamenti»: manda il cliente collegato alla pagina del mandato su KMoney. */
    public function mandate(Request $r, KMoneyProvider $kmoney)
    {
        $fromProfile = $r->query('da') === 'profilo';

        if (! $kmoney instanceof KMoneyApiProvider || ! $kmoney->enabled() || ! KMoneyLink::where('user_id', $r->user()->id)->exists()) {
            return redirect('/area/profilo#kmoney')->withErrors(['kmoney' => 'Prima collega il tuo conto KMoney.']);
        }

        return $this->sendToMandate($r, $kmoney, null, $fromProfile);
    }

    private function sendToMandate(Request $r, KMoneyApiProvider $kmoney, mixed $orderId, bool $fromProfile)
    {
        $orderId ? $r->session()->put('kmoney_order', $orderId) : $r->session()->forget('kmoney_order');
        $fromProfile ? $r->session()->put('kmoney_from', 'profilo') : $r->session()->forget('kmoney_from');

        return redirect()->away($kmoney->mandateUrl());
    }

    /** Il cliente è tornato dalla pagina del mandato: si rilegge il mandato da KMoney (mai fidarsi del solo parametro). */
    private function afterMandate(Request $r, KMoneyProvider $kmoney, mixed $orderId, bool $fromProfile)
    {
        $fail = fn (string $msg) => $fromProfile
            ? redirect('/area/profilo#kmoney')->withErrors(['kmoney' => $msg])
            : $this->toOrder($orderId, $r)->withErrors(['order' => $msg]);

        if ($r->query('mandate') !== 'granted') {
            return $fail('Non hai autorizzato i pagamenti in un clic: senza, non possiamo addebitare il saldo KMoney. Puoi riprovare quando vuoi.');
        }
        $link = $kmoney instanceof KMoneyApiProvider ? $kmoney->refreshMandate($r->user()) : null;
        if (! $link || ! $link->mandate_uuid) {
            return $fail('Non risulta ancora nessuna autorizzazione ai pagamenti su KMoney: riprova.');
        }

        return $this->linked($r, $orderId, $fromProfile);
    }

    /** Collegamento completo (conto + mandato): torna da dove il cliente era partito. */
    private function linked(Request $r, mixed $orderId, bool $fromProfile)
    {
        if ($fromProfile) {
            return redirect('/area/profilo#kmoney')->with('status', 'KMoney collegato: ora puoi pagare col tuo saldo.');
        }
        if ($orderId && ($order = Order::where('id', $orderId)->where('buyer_user_id', $r->user()->id)->where('status', 'pending_payment')->first())) {
            return redirect("/area/ordini/{$order->id}")->with('status', 'KMoney collegato. Premi «Paga con KMoney» per completare l\'ordine.');
        }

        return redirect('/area/checkout/pagamento')->with('status', 'KMoney collegato: ora puoi pagare col tuo saldo.');
    }

    public function disconnect(Request $r, KMoneyProvider $kmoney)
    {
        if ($kmoney instanceof KMoneyApiProvider) {
            $kmoney->disconnect($r->user());
        } elseif ($kmoney instanceof FakeKMoneyProvider) {
            KMoneyLink::where('user_id', $r->user()->id)->delete();
        }

        return redirect('/area/profilo#kmoney')->with('status', 'Collegamento a KMoney rimosso.');
    }

    /** Notifica firmata di KMoney: payment_request.paid → incassa la quota KMoney dell'ordine. */
    public function webhook(Request $r, OrderService $orders): Response
    {
        $secret = (string) config('lamess.kmoney.webhook_secret');
        $payload = $r->getContent();
        $sig = (string) $r->header('X-KMoney-Signature');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $sig)) {
            return response('firma non valida', 400);
        }
        $event = json_decode($payload, true);
        $name = (string) ($r->header('X-KMoney-Event') ?: (is_array($event) ? ($event['event'] ?? '') : ''));
        $data = is_array($event) ? (array) ($event['payload'] ?? $event['data'] ?? $event) : [];
        // Storni, rimborsi o contestazioni comunicati da KMoney: il portale non cambia nulla da solo, lascia una nota e un avviso.
        if (preg_match('/refund|revers|chargeback|dispute/i', $name) === 1) {
            $ref = (string) ($data['external_order_uuid'] ?? $data['external_reference'] ?? '');
            $order = $ref !== '' ? Order::where('number', $ref)->first() : null;
            Log::warning('KMoney: storno o contestazione comunicati', ['event' => $name, 'order' => $ref]);
            if ($order !== null) {
                \App\Models\OrderEvent::log($order, 'kmoney_external', "KMoney ha comunicato «{$name}» per questo ordine: verifica su KMoney se i soldi sono stati restituiti al cliente; poi usa «Regolarizza rimborso» o «Reso e rimborso» per stornare fattura e compensi.", true);
            }

            return response('ok', 200);
        }
        if ($name !== 'payment_request.paid') {
            return response('ignorato', 200);
        }
        $ref = (string) ($data['external_order_uuid'] ?? $data['external_reference'] ?? '');
        $order = $ref !== '' ? Order::where('number', $ref)->where('payment_method', 'kmoney')->first() : null;
        if (! $order || $order->status !== 'pending_payment') {
            return response('ok', 200);
        }
        $cents = (int) round((float) $order->kmoney_amount * 100);
        if ((int) ($data['amount'] ?? -1) !== $cents) {
            Log::warning('KMoney: importo non coincidente, ordine NON confermato', ['order' => $order->number, 'amount' => $data['amount'] ?? null]);

            return response('importo non coincidente', 422);
        }
        try {
            $orders->settleKMoney($order, (string) ($data['transfer_uuid'] ?? $data['uuid'] ?? 'kmoney'));
        } catch (OrderException $e) {
            Log::warning('KMoney: conferma ordine non riuscita', ['order' => $order->number, 'error' => $e->getMessage()]);
        }
        // Mese di un abbonamento confermato dal cliente su KMoney: il calendario avanza subito, senza aspettare il passaggio notturno.
        try {
            app(SubscriptionService::class)->afterOrderSettled($order->refresh());
        } catch (\Throwable $e) {
            Log::warning('KMoney: abbonamento non aggiornato dopo la conferma', ['order' => $order->number, 'error' => $e->getMessage()]);
        }

        return response('ok', 200);
    }

    private function toOrder(mixed $orderId, Request $r)
    {
        $order = $orderId ? Order::where('id', $orderId)->where('buyer_user_id', $r->user()->id)->first() : null;

        return redirect($order ? "/area/ordini/{$order->id}" : '/area/ordini');
    }
}
