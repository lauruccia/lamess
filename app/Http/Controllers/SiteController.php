<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\ReferralResolver;
use App\Models\Product;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Pagine pubbliche: referral, registrazione FAN/Clienti, accesso. */
class SiteController extends Controller
{
    public const MESSAGES = [
        'required' => 'Il campo «:attribute» è obbligatorio.', 'email' => 'Inserisci un indirizzo email valido.',
        'unique' => 'Questo :attribute è già registrato.', 'confirmed' => 'Le due password non coincidono.',
        'min' => 'Il campo «:attribute» è troppo corto.', 'in' => 'Valore non valido per «:attribute».',
        'date' => 'Data non valida.', 'required_if' => 'Il campo «:attribute» è obbligatorio.', 'accepted' => 'Devi accettare per proseguire.',
    ];

    public const ATTRIBUTES = [
        'first_name' => 'nome', 'last_name' => 'cognome', 'company_name' => 'ragione sociale', 'vat_number' => 'partita IVA',
        'sdi_code' => 'codice SDI', 'pec' => 'PEC', 'email' => 'email', 'password' => 'password', 'sponsor_code' => 'codice incaricato', 'privacy' => 'privacy',
    ];

    /** Home pubblica: presentazione del marchio, con accesso e registrazione (chi è già dentro trova il link alla sua area). */
    public function home(): \Illuminate\Contracts\View\View
    {
        $featured = Product::onShelf()->with(['category', 'variants'])->orderBy('id')->limit(3)->get();

        return view('site.home', ['featured' => $featured]);
    }

    public function referral(string $code, ReferralResolver $resolver): RedirectResponse
    {
        $consultant = $resolver->find($code);
        if ($consultant === null) {
            return redirect('/registrazione');
        }
        $days = (int) config('lamess.referral_cookie_days');

        return redirect('/registrazione')->withCookie(Cookie::make((string) config('lamess.referral_cookie_name'), (string) $consultant->referral_code, $days * 24 * 60));
    }

    public function registerForm(Request $request, ReferralResolver $resolver)
    {
        [$sponsor] = $resolver->resolve($request->query('ref'), $request->cookie((string) config('lamess.referral_cookie_name')), null);

        return view('site.register', ['sponsor' => $sponsor]);
    }

    public function register(Request $request, CustomerService $customers, ReferralResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'type' => 'required|in:private,company', 'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(10)],
            'first_name' => 'required_if:type,private', 'last_name' => 'required_if:type,private',
            'company_name' => 'required_if:type,company', 'vat_number' => 'required_if:type,company',
            'sdi_code' => 'nullable', 'pec' => 'nullable|email', 'tax_code' => 'nullable', 'phone' => 'nullable',
            'sponsor_code' => 'nullable', 'privacy' => 'accepted',
        ], self::MESSAGES, self::ATTRIBUTES);
        if ($data['type'] === 'company' && blank($data['sdi_code'] ?? null) && blank($data['pec'] ?? null)) {
            return back()->withInput()->withErrors(['sdi_code' => 'Per le aziende serve il codice SDI o la PEC.']);
        }
        $customer = $customers->register($data, $request->input('ref'), $request->cookie((string) config('lamess.referral_cookie_name')), $data['sponsor_code'] ?? null);
        Auth::login($customer->user);
        $request->session()->regenerate();

        // Chi era arrivato dal carrello torna subito al checkout, senza passare dall'area.
        $hasCart = $request->session()->get('cart', []) !== [] || $request->session()->get('cart_others', []) !== [];

        return redirect()->intended($hasCart ? '/area/checkout' : '/area');
    }

    public function loginForm()
    {
        return view('site.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required'], self::MESSAGES);
        if (! Auth::attempt($cred, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Credenziali non corrette.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/area');
    }

    public function forgotForm()
    {
        return view('site.forgot');
    }

    /** Risponde sempre allo stesso modo, anche se l'email non esiste (nessuna enumerazione degli account). */
    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email'], self::MESSAGES);
        PasswordBroker::sendResetLink(['email' => $data['email']]);

        return back()->with('status', 'Se esiste un account con questa email, ti abbiamo inviato il link per scegliere una nuova password.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('site.reset', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', Password::min(10)],
        ], self::MESSAGES);
        $status = PasswordBroker::reset($data, function ($user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Il link non è valido o è scaduto: richiedine uno nuovo.']);
        }

        return redirect('/accedi')->with('status', 'Password aggiornata: ora puoi accedere.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/accedi');
    }
}
