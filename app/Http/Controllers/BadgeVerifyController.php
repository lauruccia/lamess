<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\BadgeService;
use App\Models\Consultant;
use Illuminate\Http\Response;

/** Pagina pubblica a cui porta il QR del tesserino: dice se il tesserino è valido, senza esporre altri dati. */
class BadgeVerifyController extends Controller
{
    public function show(BadgeService $badges, string $code, string $signature): Response
    {
        $consultant = $badges->hasValidSignature($code, $signature) ? Consultant::where('code', $code)->first() : null;
        $validUntil = $consultant !== null ? $badges->validity($consultant) : null;

        return response()->view('site.verify-badge', [
            'consultant' => $validUntil !== null ? $consultant : null,
            'validUntil' => $validUntil,
            'company' => config('lamess.company'),
        ], $validUntil === null ? 404 : 200)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
