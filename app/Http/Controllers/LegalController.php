<?php

namespace App\Http\Controllers;

/** Pagine legali pubbliche (testi ripresi da lamess.it). */
class LegalController extends Controller
{
    public const PAGES = [
        'privacy' => ['title' => 'Privacy Policy', 'view' => 'site.legal.privacy'],
        'termini' => ['title' => 'Termini e Condizioni', 'view' => 'site.legal.termini'],
        'politiche-di-reso' => ['title' => 'Politiche di Reso', 'view' => 'site.legal.resi'],
    ];

    public function show(string $page)
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        return view('site.legal.show', ['page' => $page] + self::PAGES[$page]);
    }
}
