<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\IvdLetterSigning;
use App\Domain\Onboarding\IvdLetterTemplate;
use App\Domain\Onboarding\OnboardingException;
use App\Models\IvdLetterSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Firma della Lettera di Incarico aggiornata da parte di chi è già incaricato (richiesta dall'amministratore con «Invia da firmare»). */
class LetterSignatureController extends Controller
{
    public function show(Request $request, IvdLetterSigning $signing)
    {
        $sig = $signing->pendingFor($request->user());
        if ($sig === null) {
            return redirect('/area')->with('status', 'Non hai nessuna lettera da firmare.');
        }
        if ($sig->viewed_at === null) {
            $sig->update(['viewed_at' => now()]);
        }
        $user = $request->user()->load('profile');

        return view('site.area.letter-sign', [
            'sig' => $sig, 'version' => $sig->version, 'user' => $user, 'items' => $signing->items($sig->version),
            'hint' => session('signature_hint'),
            'regolamentoHtml' => app(\App\Domain\Onboarding\RegolamentoDocument::class)->html(), 'regolamentoVersion' => app(\App\Domain\Onboarding\RegolamentoDocument::class)->version(),
        ]);
    }

    public function code(Request $request, IvdLetterSigning $signing): RedirectResponse
    {
        $sig = $signing->pendingFor($request->user()) ?? abort(404);
        try {
            $req = $signing->requestCode($sig);
        } catch (OnboardingException $e) {
            return redirect('/area/lettera')->withErrors(['otp' => $e->getMessage()]);
        }

        return redirect('/area/lettera')->with('signature_hint', $req->hint);
    }

    public function sign(Request $request, IvdLetterSigning $signing): RedirectResponse
    {
        $sig = $signing->pendingFor($request->user()) ?? abort(404);
        $rules = [
            'otp' => 'required',
            'income_over_threshold' => 'required|in:yes,no', 'other_social_security' => 'required|in:yes,no',
            'is_pensioner' => 'nullable|boolean', 'multi_principal' => 'required|in:yes,no',
            'multi_principal_names' => 'required_if:multi_principal,yes|nullable|string|max:255',
        ];
        $messages = SiteController::MESSAGES + [
            'income_over_threshold.required' => 'Art. 12.1: indica se hai superato o no il reddito di 5.000 €.',
            'other_social_security.required' => 'Art. 12.1: indica se hai un\'altra posizione previdenziale obbligatoria.',
            'multi_principal.required' => 'Art. 12.2: indica se sei mono o plurimandatario.',
            'multi_principal_names.required_if' => 'Art. 12.2: scrivi per quali imprese operi.',
        ];
        $items = $signing->items($sig->version);
        foreach ($items as $key => $item) {
            $rules['accept_'.$key] = 'accepted';
            $messages['accept_'.$key.'.accepted'] = 'Per firmare devi spuntare: '.rtrim(mb_substr($item['label'], 0, 90), ' .,:;').'…';
        }
        $request->validate($rules, $messages);
        $declarations = [
            'income_over_threshold' => (string) $request->input('income_over_threshold'), 'other_social_security' => (string) $request->input('other_social_security'),
            'is_pensioner' => $request->boolean('is_pensioner'), 'multi_principal' => (string) $request->input('multi_principal'),
            'multi_principal_names' => $request->input('multi_principal') === 'yes' ? trim((string) $request->input('multi_principal_names')) : null,
        ];
        $accepted = collect($items)->map(fn (array $i, string $key): bool => $request->boolean('accept_'.$key))->all();
        try {
            $signing->sign($sig, (string) $request->input('otp'), $request->ip(), (string) $request->userAgent(), $declarations, $accepted);
        } catch (OnboardingException $e) {
            return back()->withErrors(['otp' => $e->getMessage()]);
        }

        return redirect('/area')->with('status', 'Lettera firmata: grazie. Trovi la copia firmata tra i tuoi documenti.');
    }

    /** Copia firmata, riservata al titolare. */
    public function pdf(Request $request, IvdLetterSignature $signature)
    {
        abort_unless($signature->user_id === $request->user()->id && $signature->status === 'signed' && $signature->pdf_path && Storage::exists($signature->pdf_path), 404);

        return Storage::response($signature->pdf_path, 'lettera-di-incarico-v'.$signature->version->version.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
