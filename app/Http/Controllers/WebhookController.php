<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\KycService;
use App\Domain\Orders\StripeWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Ingresso delle notifiche di Stripe: una sola URL per pagamenti e verifica identità. */
final class WebhookController extends Controller
{
    public function stripe(Request $request, StripeWebhook $payments, KycService $kyc): Response
    {
        $payload = $request->getContent();
        if (! StripeWebhook::verify($payload, $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'))) {
            return response('firma non valida', 400);
        }
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response('payload non valido', 400);
        }
        $type = (string) ($event['type'] ?? '');
        $obj = $event['data']['object'] ?? [];

        if (str_starts_with($type, 'identity.verification_session.')) {
            $status = match ($type) {
                'identity.verification_session.verified' => 'verified',
                'identity.verification_session.requires_input' => 'requires_input',
                'identity.verification_session.canceled' => 'canceled',
                default => null,
            };
            if ($status !== null) {
                $kyc->applyOnlineResult((string) ($obj['id'] ?? ''), $status, $obj['last_error']['code'] ?? null);
            }
        } else {
            $payments->handle($event);
        }

        return response('ok', 200);
    }
}
