<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\KMoneyLink;
use App\Models\Order;
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
final class KMoneyApiProvider implements PartialKMoneyRefund
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
        $mandate = $link->mandate_uuid ?: $this->syncMandate($link);
        if (! $mandate) {
            return new PaymentResult(false, null, 'not_linked');
        }
        $cents = BigDecimal::of((string) $order->kmoney_amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        if ($cents < 1) {
            return new PaymentResult(false, null, 'Nessun importo da addebitare in KMoney.');
        }
        try {
            $res = $this->api($link)->post($this->url("/api/v1/mandates/{$mandate}/charge"), [
                'seller_account_number' => config('lamess.kmoney.seller_account'), 'amount' => $cents, 'external_order_uuid' => (string) $order->number,
                'order_title' => "Ordine {$order->number} · Lamèss", 'quantity' => 1,
                // Stabile sui tentativi: lo stesso ordine e importo non può mai essere addebitato due volte.
                'idempotency_key' => "lamess-{$order->number}-{$cents}", 'return_url' => self::redirectUri(),
            ]);
        } catch (\Throwable $e) {
            Log::error('KMoney: errore di rete nell\'addebito', ['order' => $order->number, 'error' => $e->getMessage()]);

            return new PaymentResult(false, null, 'KMoney non risponde: riprova tra poco. Il tuo conto non è stato addebitato.');
        }
        if ($res->status() === 200 && $res->json('status') === 'booked') {
            return new PaymentResult(true, (string) ($res->json('transfer_uuid') ?? 'kmoney'));
        }
        if ($res->status() === 402) {
            return new PaymentResult(false, null, (string) ($res->json('reason') ?? 'confirmation_required'), $res->json('confirmation_url'));
        }
        if ($res->status() === 404 && $res->json('reason') === 'mandate_not_found') {
            $link->update(['mandate_uuid' => null]);

            return new PaymentResult(false, null, 'not_linked');
        }
        Log::warning('KMoney: addebito rifiutato', ['order' => $order->number, 'status' => $res->status(), 'reason' => $res->json('reason')]);

        return new PaymentResult(false, null, (string) ($res->json('message') ?? 'Addebito KMoney non riuscito.'));
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
