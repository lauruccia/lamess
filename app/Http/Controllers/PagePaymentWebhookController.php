<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pages\PersonalPagePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Avviso firmato di KMoney per il pagamento delle pagine personali (conto di KNM): segreto e conto diversi da quelli degli ordini del negozio. */
final class PagePaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PersonalPagePayment $payments): Response
    {
        [$status, $body] = $payments->handleWebhook($request->getContent(), $request->header('X-KMoney-Signature'));

        return response($body, $status);
    }
}
