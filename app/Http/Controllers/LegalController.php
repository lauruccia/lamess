<?php

namespace App\Http\Controllers;

use App\Models\CompPlanVersion;

/** Pagine legali pubbliche (testi ripresi da lamess.it). */
class LegalController extends Controller
{
    public const PAGES = [
        'privacy' => ['title' => 'Privacy Policy', 'view' => 'site.legal.privacy'],
        'termini' => ['title' => 'Termini e Condizioni', 'view' => 'site.legal.termini'],
        'politiche-di-reso' => ['title' => 'Politiche di Reso', 'view' => 'site.legal.resi'],
        'piano-compensi' => ['title' => 'Il piano compensi', 'view' => 'site.legal.piano-compensi'],
    ];

    public function show(string $page)
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        $extra = [];
        if ($page === 'piano-compensi') {
            $version = CompPlanVersion::inForce();
            $extra = ['version' => $version, 'plan' => $version?->data, 'kosmos' => ($version?->data['settings']['engine'] ?? null) === 'kosmos_v1'];
        }

        return view('site.legal.show', ['page' => $page] + self::PAGES[$page] + $extra);
    }
}
