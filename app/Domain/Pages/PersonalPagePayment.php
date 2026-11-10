<?php

declare(strict_types=1);

namespace App\Domain\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Models\Consultant;
use App\Models\ConsultantPage;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pagamento automatico della pagina personale con KMoney.
 *
 * «Paga con KMoney» crea su KMoney una richiesta di pagamento da 150 KY sul conto di KNM (token API del conto KNM: il
 * checkout del portale pagherebbe Kadeo Group, qui non si usa). L'incaricato la paga su KMoney (anche aprendo il conto lì)
 * e KMoney avvisa il portale con `payment_request.paid`; questo servizio attiva la pagina con `PersonalPageService::activate`.
 *
 * Tutto passa da `settle()`, chiamata sia dall'avviso firmato sia dal controllo al ritorno dell'incaricato: lo stesso
 * pagamento visto due volte (o da due strade insieme) attiva una volta sola.
 */
final class PersonalPagePayment
{
    public const ACTIVATED = 'activated';

    public const ALREADY = 'already';

    public const IGNORED = 'ignored';

    public const MISMATCH = 'mismatch';

    public const UNKNOWN = 'unknown';

    public function __construct(private readonly PersonalPageService $pages) {}

    /** Il pulsante «Paga con KMoney» c'è solo con l'interruttore acceso e le credenziali inserite. */
    public function enabled(): bool
    {
        return (bool) config('lamess.personal_pages.kmoney.enabled')
            && filled(config('lamess.kmoney.base_url'))
            && filled(config('lamess.personal_pages.kmoney.api_token'))
            && filled(config('lamess.personal_pages.kmoney.webhook_secret'));
    }

    /** L'incaricato può pagare adesso (prima attivazione, scaduta, o rinnovo nel periodo di preavviso)? */
    public function canPay(ConsultantPage $p): bool
    {
        return $this->enabled() && $this->pages->activationRequired() && (! $this->pages->isActivated($p) || $this->pages->renewalDue($p));
    }

    /** Importo in centesimi di KY (1 KY = 1 €), calcolato dal costo impostato: mai con i decimali in virgola mobile. */
    public function amountCents(): int
    {
        return Num::money($this->pages->price())->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** Crea (o riprende) la richiesta di pagamento e restituisce l'indirizzo di KMoney a cui mandare l'incaricato. */
    public function start(Consultant $c): string
    {
        $page = $this->pages->forConsultant($c);
        if (! $this->canPay($page)) {
            throw new PageException($this->pages->isActivated($page) ? 'La pagina è già attiva.' : 'Il pagamento con KMoney non è disponibile al momento.');
        }
        $profile = $c->user?->profile;
        $payer = array_filter([
            'name' => $this->pages->defaultName($c),
            'email' => $c->user?->email,
            'phone' => $profile?->phone,
            'fiscal_code' => $profile?->tax_code,
        ], fn ($v) => filled($v));

        try {
            $res = $this->api()->post($this->url('/api/v1/payment-requests'), [
                'amount' => $this->amountCents(),
                'description' => 'Pagina Incaricato Lamèss — '.$c->code,
                'external_reference' => $c->code,
                'return_url' => url('/area/pagina/pagamento?esito=ok'),
                'cancel_url' => url('/area/pagina/pagamento?esito=annullato'),
                'expires_in_minutes' => 1440,
                'payer' => $payer,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('KMoney pagine: richiesta di pagamento non creata', ['consultant' => $c->code, 'error' => $e->getMessage()]);
            throw new PageException('KMoney non risponde in questo momento: riprova tra poco.');
        }
        $uuid = (string) $res->json('data.uuid');
        $checkout = (string) ($res->json('data.checkout_url') ?: $res->json('data.pay_url'));
        // Si manda l'incaricato solo su un indirizzo di KMoney: una risposta anomala non deve portarlo altrove.
        if (! $res->successful() || $uuid === '' || ! $this->isKMoneyUrl($checkout)) {
            Log::warning('KMoney pagine: risposta non valida', ['consultant' => $c->code, 'status' => $res->status()]);
            throw new PageException('Non siamo riusciti ad aprire il pagamento su KMoney: riprova, oppure usa la procedura a mano.');
        }
        $page->forceFill(['kmoney_request_uuid' => $uuid])->save();
        AuditLog::record('page.payment_started', "Pagina personale di {$c->code}: aperta la richiesta di pagamento su KMoney", $page, [], $this->account());

        return $checkout;
    }

    /**
     * Lavora un pagamento ricevuto (dall'avviso o dal controllo al ritorno). Idempotente.
     *
     * @param  array<string, mixed>  $data  dati della richiesta di pagamento di KMoney
     * @return self::ACTIVATED|self::ALREADY|self::IGNORED|self::MISMATCH|self::UNKNOWN
     */
    public function settle(ConsultantPage $page, array $data): string
    {
        $uuid = (string) ($data['uuid'] ?? '');
        if ($uuid === '') {
            return self::UNKNOWN;
        }

        return DB::transaction(function () use ($page, $data, $uuid): string {
            $p = ConsultantPage::with('consultant')->whereKey($page->id)->lockForUpdate()->first();
            if ($p === null) {
                return self::UNKNOWN;
            }
            if ($p->kmoney_paid_uuid === $uuid) {
                return self::ALREADY;
            }
            if ($p->kmoney_request_uuid !== $uuid) {
                return self::UNKNOWN;
            }
            if ((string) ($data['status'] ?? 'paid') !== 'paid') {
                return self::IGNORED;
            }
            if ((int) ($data['amount'] ?? -1) !== $this->amountCents() || (string) ($data['currency'] ?? 'KY') !== 'KY') {
                AuditLog::record('page.payment_mismatch', "Pagina personale di {$p->consultant->code}: pagamento KMoney con importo diverso dal costo, pagina NON attivata", $p, ['importo' => ['atteso' => $this->amountCents(), 'ricevuto' => $data['amount'] ?? null]], $uuid);

                return self::MISMATCH;
            }
            $ref = 'Pagato con KMoney'.(filled($this->account()) ? ' (conto '.$this->account().')' : '').', richiesta '.$uuid.(filled($data['transfer_uuid'] ?? null) ? ', movimento '.$data['transfer_uuid'] : '');
            $this->pages->activate($p, 'paid', $ref);
            $p->forceFill(['kmoney_paid_uuid' => $uuid, 'kmoney_request_uuid' => null])->save();

            return self::ACTIVATED;
        });
    }

    /**
     * Al ritorno dell'incaricato: se l'avviso non è ancora arrivato, chiede a KMoney com'è andata la richiesta e,
     * se risulta pagata, la lavora subito. Restituisce l'esito di settle(), o null se non c'è nulla in corso.
     */
    public function confirmOnReturn(ConsultantPage $page): ?string
    {
        $uuid = (string) $page->kmoney_request_uuid;
        if ($uuid === '') {
            return null;
        }
        try {
            $res = $this->api()->get($this->url('/api/v1/payment-requests/'.$uuid));
        } catch (ConnectionException) {
            return null;
        }
        $data = (array) $res->json('data');
        if (! $res->successful() || (string) ($data['uuid'] ?? '') !== $uuid || (string) ($data['status'] ?? '') !== 'paid') {
            return null;
        }

        return $this->settle($page, $data);
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        $secret = (string) config('lamess.personal_pages.kmoney.webhook_secret');

        return $secret !== '' && $signature !== null && hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }

    /**
     * Avviso firmato di KMoney. Risponde con (codice, testo): 400 firma non valida, 422 importo diverso (KMoney riprova e
     * il caso resta nel registro), 200 per tutto il resto.
     *
     * @return array{0: int, 1: string}
     */
    public function handleWebhook(string $payload, ?string $signature): array
    {
        if (! $this->verifySignature($payload, $signature)) {
            return [400, 'firma non valida'];
        }
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return [400, 'payload non valido'];
        }
        if ((string) ($event['event'] ?? '') !== 'payment_request.paid') {
            return [200, 'ignorato'];
        }
        $data = (array) ($event['payload'] ?? []);
        $consultant = filled($data['external_reference'] ?? null) ? Consultant::where('code', (string) $data['external_reference'])->first() : null;
        $page = $consultant !== null ? ConsultantPage::where('consultant_id', $consultant->id)->first() : null;
        $outcome = $page !== null ? $this->settle($page, $data) : self::UNKNOWN;

        if ($outcome === self::MISMATCH) {
            return [422, 'importo non coincidente'];
        }
        if ($outcome === self::UNKNOWN) {
            // Soldi arrivati su KNM senza una richiesta nostra corrispondente: nessun automatismo, lo vede la titolare.
            Log::warning('KMoney pagine: pagamento non abbinato', ['reference' => $data['external_reference'] ?? null, 'uuid' => $data['uuid'] ?? null]);
            AuditLog::record('page.payment_unmatched', 'Pagamento KMoney per una pagina personale non abbinato a nessuna richiesta: controlla su KMoney e, se è giusto, attiva la pagina a mano', $page, [], (string) ($data['uuid'] ?? ''));
        }

        return [200, 'ok'];
    }

    private function account(): string
    {
        return trim((string) config('lamess.personal_pages.kmoney.account'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('lamess.kmoney.base_url'), '/').$path;
    }

    private function isKMoneyUrl(string $url): bool
    {
        $base = parse_url((string) config('lamess.kmoney.base_url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        return $base !== null && $host !== null && $url !== '' && str_starts_with($url, 'http') && strcasecmp((string) $base, (string) $host) === 0;
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) config('lamess.personal_pages.kmoney.api_token'))->acceptJson()->timeout(20);
    }
}
