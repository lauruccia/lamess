<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\KMoneyLink;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pagamento con KMoney tramite kmoney-app: «Accedi con KMoney» (OAuth2 + PKCE) e addebito col mandato del cliente.
 * Le credenziali del cliente non passano mai di qui: il cliente le inserisce solo su KMoney.
 */
final class KMoneyApiProvider implements KMoneySubscriptionSync, PartialKMoneyRefund
{
    public const SCOPES = 'profile account.read mandate';

    public function name(): string
    {
        return 'kmoney';
    }

    public function enabled(): bool
    {
        $c = (array) config('lamess.kmoney');

        return filled($c['base_url'] ?? null) && filled($c['client_id'] ?? null) && filled($c['client_secret'] ?? null) && filled($c['seller_account'] ?? null);
    }

    public static function redirectUri(): string
    {
        return url('/area/kmoney/ritorno');
    }

    /**
     * Il cliente che non concede il mandato può pagare lo stesso, confermando ogni pagamento su KMoney:
     * serve il token API del conto venditore, con cui si crea la richiesta di pagamento.
     */
    public static function confirmsEachPayment(): bool
    {
        return config('lamess.kmoney_driver') === 'api' && filled(config('lamess.kmoney.api_token'));
    }

    /** @return array{url: string, state: string, verifier: string} */
    public function authorization(): array
    {
        $verifier = Str::random(64);
        $state = Str::random(40);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = config('lamess.kmoney.base_url').'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('lamess.kmoney.client_id'), 'redirect_uri' => self::redirectUri(),
            'scope' => self::SCOPES, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Pagina di KMoney dove il cliente concede il mandato «un clic e paghi». Non fa parte del consenso OAuth:
     * è una rotta a sé (dietro step-up) a cui l'applicazione deve mandare il cliente, con il venditore a cui il
     * mandato resta limitato e l'indirizzo a cui tornare (uno di quelli registrati per il client).
     */
    public function mandateUrl(): string
    {
        return $this->url('/oauth/mandate').'?'.http_build_query(array_filter([
            'client_id' => config('lamess.kmoney.client_id'),
            'return_url' => self::redirectUri(),
            'seller' => config('lamess.kmoney.seller_account'),
            // Tetto per singolo acquisto proposto al cliente (KY): perché un ordine come una box da 1.500 KY si paghi in un clic.
            'limit' => config('lamess.kmoney.mandate_limit_ky'),
        ]));
    }

    /** Rilegge da KMoney il mandato del cliente (dopo che lo ha concesso) e lo salva; null se il collegamento non è più valido. */
    public function refreshMandate(User $user): ?KMoneyLink
    {
        $link = KMoneyLink::where('user_id', $user->id)->first();
        if (! $link || ! $this->freshToken($link)) {
            return null;
        }
        $this->syncMandate($link);

        return $link->refresh();
    }

    /** Scambia il codice di autorizzazione coi token e salva il collegamento del cliente. */
    public function connect(User $user, string $code, string $verifier): KMoneyLink
    {
        $res = Http::asForm()->timeout(15)->post($this->url('/api/oauth/token'), [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
            'client_id' => config('lamess.kmoney.client_id'), 'client_secret' => config('lamess.kmoney.client_secret'), 'code_verifier' => $verifier,
        ]);
        if (! $res->successful() || ! $res->json('access_token')) {
            Log::warning('KMoney: scambio del codice non riuscito', ['status' => $res->status()]);
            throw new OrderException('Collegamento a KMoney non riuscito: riprova.');
        }
        $link = KMoneyLink::updateOrCreate(['user_id' => $user->id], $this->tokenFields($res->json()));
        $this->syncMandate($link);

        return $link->refresh();
    }

    public function disconnect(User $user): void
    {
        $link = KMoneyLink::where('user_id', $user->id)->first();
        if ($link) {
            Http::asForm()->timeout(10)->post($this->url('/api/oauth/token/revoke'), [
                'client_id' => config('lamess.kmoney.client_id'), 'client_secret' => config('lamess.kmoney.client_secret'), 'token' => $link->access_token,
            ]);
            $link->delete();
        }
    }

    public function charge(Order $order, User $buyer): PaymentResult
    {
        $link = KMoneyLink::where('user_id', $buyer->id)->first();
        if (! $link || ! $this->freshToken($link)) {
            return new PaymentResult(false, null, 'not_linked');
        }
        $cents = $this->kmoneyCents($order);
        $mandate = $link->mandate_uuid ?: $this->syncMandate($link);
        if (! $mandate) {
            // Senza mandato («preferisco confermare ogni volta»): l'ordine singolo si paga con una richiesta da confermare su KMoney.
            // Un abbonamento no: gli addebiti dei mesi successivi partono da soli e non possono aspettare una conferma.
            return $order->subscription_id === null && self::confirmsEachPayment() && $cents >= 1
                ? $this->requestPayment($order, $cents)
                : new PaymentResult(false, null, 'not_linked');
        }
        if ($cents < 1) {
            return new PaymentResult(false, null, 'Nessun importo da addebitare in KMoney.');
        }
        try {
            $res = $this->api($link)->post($this->url("/api/v1/mandates/{$mandate}/charge"), [
                'seller_account_number' => config('lamess.kmoney.seller_account'), 'amount' => $cents, 'external_order_uuid' => (string) $order->number,
                'order_title' => "Ordine {$order->number} · Lamèss", 'quantity' => 1,
                // Stabile sui tentativi: lo stesso ordine e importo non può mai essere addebitato due volte.
                'idempotency_key' => "lamess-{$order->number}-{$cents}", 'return_url' => self::redirectUri(),
            ] + ($order->subscription_id ? ['subscription_id' => (string) $order->subscription_id] : []));
        } catch (\Throwable $e) {
            Log::error('KMoney: errore di rete nell\'addebito', ['order' => $order->number, 'error' => $e->getMessage()]);

            return new PaymentResult(false, null, 'KMoney non risponde: riprova tra poco. Il tuo conto non è stato addebitato.');
        }
        if ($res->status() === 200 && $res->json('status') === 'booked') {
            return new PaymentResult(true, (string) ($res->json('transfer_uuid') ?? 'kmoney'));
        }
        if ($res->status() === 402) {
            // Il motivo della richiesta di conferma resta scritto: nei log e nella cronologia (nota interna) dell'ordine.
            $reason = (string) ($res->json('reason') ?? 'confirmation_required');
            Log::warning('KMoney: addebito con conferma richiesta (402)', [
                'order' => $order->number, 'user' => $buyer->id, 'mandate' => $mandate, 'amount_cents' => $cents,
                'subscription' => $order->subscription_id, 'reason' => $reason, 'message' => $res->json('message'),
            ]);
            OrderEvent::log($order, 'kmoney_confirmation', 'KMoney chiede la conferma del cliente (402) · motivo: '.$reason.($res->json('message') ? ' · '.$res->json('message') : '').' · mandato '.$mandate.' · importo € '.number_format($cents / 100, 2, ',', '.'), true);

            return new PaymentResult(false, null, $reason, $res->json('confirmation_url'));
        }
        // Il cliente ha bloccato questo abbonamento su KMoney: non è un errore da ritentare né una conferma da chiedere.
        if ($res->status() === 403 && $res->json('reason') === 'subscription_blocked') {
            return new PaymentResult(false, null, 'subscription_blocked');
        }
        if ($res->status() === 404 && $res->json('reason') === 'mandate_not_found') {
            $link->update(['mandate_uuid' => null]);

            return new PaymentResult(false, null, 'not_linked');
        }
        Log::warning('KMoney: addebito rifiutato', ['order' => $order->number, 'status' => $res->status(), 'reason' => $res->json('reason')]);

        return new PaymentResult(false, null, (string) ($res->json('message') ?? 'Addebito KMoney non riuscito.'));
    }

    /**
     * Riferimento del pagamento se il cliente ha già pagato su KMoney la richiesta di quest'ordine; null se non risulta
     * (o se KMoney non risponde). Serve al ritorno dalla conferma, senza dipendere dall'arrivo dell'avviso (webhook).
     */
    public function paidReference(Order $order): ?string
    {
        if (! self::confirmsEachPayment()) {
            return null;
        }
        try {
            return $this->paidRequest($order, $this->kmoneyCents($order))['reference'];
        } catch (\Throwable $e) {
            Log::warning('KMoney: controllo del pagamento al ritorno non riuscito', ['order' => $order->number, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Pagamento senza mandato: crea (o riprende) sul conto venditore una richiesta di pagamento per l'ordine e manda
     * il cliente a confermarla su KMoney. L'esito arriva dal webhook `payment_request.paid` e dal controllo al ritorno.
     */
    private function requestPayment(Order $order, int $cents): PaymentResult
    {
        $busy = 'KMoney non risponde: riprova tra poco. Il tuo conto non è stato addebitato.';
        try {
            // Prima si guarda se la richiesta di quest'ordine è già stata pagata: mai una seconda richiesta per lo stesso ordine.
            $paid = $this->paidRequest($order, $cents);
            if (! $paid['checked']) {
                return new PaymentResult(false, null, $busy);
            }
            if ($paid['reference'] !== null) {
                return new PaymentResult(true, $paid['reference']);
            }
            $res = $this->sellerApi()->post($this->url('/api/v1/payment-requests'), [
                'amount' => $cents, 'description' => "Ordine {$order->number} · Lamèss", 'external_reference' => (string) $order->number,
                'return_url' => self::redirectUri(), 'cancel_url' => self::redirectUri(), 'expires_in_minutes' => 60,
            ]);
        } catch (\Throwable $e) {
            Log::error('KMoney: errore di rete nella richiesta di pagamento', ['order' => $order->number, 'error' => $e->getMessage()]);

            return new PaymentResult(false, null, $busy);
        }
        $url = (string) ($res->json('data.checkout_url') ?: $res->json('data.pay_url'));
        // Il cliente si manda solo su un indirizzo di KMoney: una risposta anomala non deve portarlo altrove.
        if (! $res->successful() || ! $this->isKMoneyUrl($url)) {
            Log::warning('KMoney: richiesta di pagamento non creata', ['order' => $order->number, 'status' => $res->status(), 'error' => $res->json('error')]);

            return new PaymentResult(false, null, 'Non siamo riusciti ad aprire il pagamento su KMoney: riprova tra poco. Il tuo conto non è stato addebitato.');
        }
        OrderEvent::log($order, 'kmoney_confirmation', 'Pagamento senza mandato: il cliente conferma su KMoney la richiesta '.$res->json('data.uuid').' · importo € '.number_format($cents / 100, 2, ',', '.'), true);

        return new PaymentResult(false, null, 'confirmation_required', $url);
    }

    /**
     * Cerca fra le richieste incassate dal conto venditore quella di quest'ordine (stesso riferimento e importo).
     *
     * @return array{checked: bool, reference: ?string} `checked` false = KMoney non ha risposto, l'esito non è noto
     */
    private function paidRequest(Order $order, int $cents): array
    {
        $res = $this->sellerApi()->get($this->url('/api/v1/payment-requests'), ['direction' => 'incoming', 'status' => 'paid', 'per_page' => 100]);
        if (! $res->successful()) {
            return ['checked' => false, 'reference' => null];
        }
        $hit = collect($res->json('data') ?? [])->first(fn ($p) => ($p['status'] ?? null) === 'paid'
            && (string) ($p['external_reference'] ?? '') === (string) $order->number && (int) ($p['amount'] ?? -1) === $cents);

        return ['checked' => true, 'reference' => $hit === null ? null : (string) ($hit['transfer_uuid'] ?? $hit['uuid'] ?? 'kmoney')];
    }

    private function kmoneyCents(Order $order): int
    {
        return BigDecimal::of((string) $order->kmoney_amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** Chiamate col token API del conto venditore (richieste di pagamento, rimborsi). */
    private function sellerApi(): PendingRequest
    {
        return Http::withToken((string) config('lamess.kmoney.api_token'))->acceptJson()->timeout(20);
    }

    private function isKMoneyUrl(string $url): bool
    {
        $base = parse_url((string) config('lamess.kmoney.base_url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($base) && is_string($host) && str_starts_with($url, 'http') && strcasecmp($base, $host) === 0;
    }

    /**
     * Manda a KMoney lo stato dell'abbonamento: da lì il cliente vede importo, prossimo addebito e stato, e può bloccarlo.
     * Mai bloccante: senza collegamento, senza rete o con KMoney che risponde male si registra l'avviso e si va avanti.
     */
    public function syncSubscription(Subscription $subscription): void
    {
        try {
            $link = KMoneyLink::where('user_id', $subscription->user_id)->first();
            if (! $link || ! $this->freshToken($link)) {
                return;
            }
            $lines = (array) $subscription->lines;
            $names = Product::whereIn('id', array_keys($lines))->pluck('name', 'id');
            $items = [];
            foreach ($lines as $productId => $qty) {
                $items[] = Str::limit($qty.' × '.($names[$productId] ?? 'prodotto'), 250, '');
            }
            $qty = (int) array_sum($lines);
            $kmoneyAmount = $subscription->orders()->where('payment_method', 'kmoney')->latest('id')->value('kmoney_amount');
            $res = $this->api($link)->timeout(8)->put($this->url('/api/v1/subscriptions/'.$subscription->id), [
                'title' => $qty.' '.($qty === 1 ? 'prodotto' : 'prodotti').' '.$subscription->cadenceLabel(),
                'items' => $items,
                'seller_account_number' => config('lamess.kmoney.seller_account'),
                'amount' => $kmoneyAmount === null ? null : BigDecimal::of((string) $kmoneyAmount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt(),
                'interval_months' => $subscription->intervalMonths(),
                'status' => match ($subscription->status) {
                    Subscription::ACTIVE => 'active',
                    Subscription::CANCELLED => 'cancelled',
                    default => 'paused',
                },
                'next_charge_at' => $subscription->next_run_at?->toIso8601String(),
                'last_error' => $subscription->last_error ? Str::limit($subscription->last_error, 250, '') : null,
            ]);
            if (! $res->successful()) {
                Log::warning('KMoney: copia dell\'abbonamento non accettata', ['subscription' => $subscription->id, 'status' => $res->status(), 'reason' => $res->json('reason')]);
            }
        } catch (\Throwable $e) {
            Log::warning('KMoney: copia dell\'abbonamento non inviata', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }

    /** Rimborso: trasferimento dal conto venditore al conto KMoney del cliente (API con token del venditore). */
    public function refund(Order $order, User $buyer): PaymentResult
    {
        $cents = BigDecimal::of((string) $order->kmoney_amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $this->refundAmount($order, $buyer, (string) $order->kmoney_amount, "lamess-refund-{$order->number}-{$cents}");
    }

    /** Riaccredita un importo (anche parziale) sul conto KMoney del cliente. */
    public function refundAmount(Order $order, User $buyer, string $amount, string $idempotencyKey): PaymentResult
    {
        $token = (string) config('lamess.kmoney.api_token');
        $account = KMoneyLink::where('user_id', $buyer->id)->value('account_number');
        if ($token === '' || ! $account) {
            return new PaymentResult(false, null, 'Impossibile rimborsare in automatico (manca il token API KMoney o il conto del cliente): rimborsa a mano dal portale KMoney.');
        }
        $cents = BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(20)->post($this->url('/api/v1/transfers'), [
                'to_account' => $account, 'amount' => $cents, 'description' => "Rimborso ordine {$order->number} · Lamèss",
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('KMoney: errore di rete nel rimborso', ['order' => $order->number, 'error' => $e->getMessage()]);

            return new PaymentResult(false, null, 'KMoney non risponde: riprova tra poco (il rimborso non è stato eseguito).');
        }
        if ($res->successful()) {
            return new PaymentResult(true, (string) ($res->json('data.uuid') ?? $res->json('uuid') ?? 'kmoney-refund'));
        }
        Log::warning('KMoney: rimborso rifiutato', ['order' => $order->number, 'status' => $res->status(), 'body' => $res->json()]);

        return new PaymentResult(false, null, (string) ($res->json('error') ?? $res->json('message') ?? 'Rimborso KMoney rifiutato.'));
    }

    private function url(string $path): string
    {
        return config('lamess.kmoney.base_url').$path;
    }

    private function api(KMoneyLink $link): PendingRequest
    {
        return Http::withToken($link->access_token)->acceptJson()->timeout(20);
    }

    /** @param  array<string,mixed>  $t */
    private function tokenFields(array $t): array
    {
        return [
            'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 3600) - 30),
        ];
    }

    /** Token valido (rinnovato se scaduto); false se il collegamento non è più utilizzabile. */
    private function freshToken(KMoneyLink $link): bool
    {
        if ($link->expires_at && $link->expires_at->isFuture()) {
            return true;
        }
        if (! $link->refresh_token) {
            $link->delete();

            return false;
        }
        $res = Http::asForm()->timeout(15)->post($this->url('/api/oauth/token'), [
            'grant_type' => 'refresh_token', 'refresh_token' => $link->refresh_token,
            'client_id' => config('lamess.kmoney.client_id'), 'client_secret' => config('lamess.kmoney.client_secret'),
        ]);
        if (! $res->successful() || ! $res->json('access_token')) {
            $link->delete();

            return false;
        }
        $link->update($this->tokenFields($res->json()));

        return true;
    }

    /** Legge il mandato attivo del cliente e lo salva; null se non ne ha concesso uno. */
    private function syncMandate(KMoneyLink $link): ?string
    {
        $res = $this->api($link)->get($this->url('/api/v1/mandates'));
        $mandate = collect($res->json('mandates') ?? [])->first(fn ($m) => empty($m['suspended']));
        $info = $this->api($link)->get($this->url('/api/v1/userinfo'));
        $link->update(['mandate_uuid' => $mandate['uuid'] ?? null, 'account_number' => $info->json('account.number')]);

        return $mandate['uuid'] ?? null;
    }
}
