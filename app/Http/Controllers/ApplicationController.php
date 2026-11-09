<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customers\ApplicationService;
use App\Models\MembershipApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Pagina pubblica «Candidati»: chi non ha un invito lascia i suoi dati e attende la risposta del Consorzio. */
class ApplicationController extends Controller
{
    private const MESSAGES = [
        'required' => 'Compila questo campo.', 'email' => 'Inserisci un indirizzo email valido.', 'max' => 'Testo troppo lungo.',
        'in' => 'Scelta non valida.', 'accepted' => 'Per candidarti devi acconsentire al trattamento dei dati.',
    ];

    public function form()
    {
        return view('site.apply');
    }

    public function store(Request $request, ApplicationService $applications): RedirectResponse
    {
        // trappola per i robot: un campo nascosto che una persona non compila
        if ($request->filled('website')) {
            return redirect('/candidati/grazie');
        }
        $data = $request->validate([
            'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'email' => 'required|email:rfc|max:190', 'phone' => 'required|string|max:40',
            'city' => 'required|string|max:100',
            'interest' => 'required|in:'.implode(',', array_keys(MembershipApplication::INTERESTS)),
            'privacy' => 'accepted',
        ], self::MESSAGES, [
            'first_name' => 'nome', 'last_name' => 'cognome', 'phone' => 'telefono', 'city' => 'città', 'interest' => 'interesse',
        ]);
        // una sola candidatura aperta per email: la risposta è la stessa, senza dire se esisteva già
        if (! $applications->hasOpen($data['email'])) {
            $applications->submit($data, $request->ip());
        }

        return redirect('/candidati/grazie');
    }

    public function thanks()
    {
        return view('site.apply-thanks');
    }
}
