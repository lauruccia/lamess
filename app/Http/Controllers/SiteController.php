<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\ReferralInviteService;
use App\Domain\Customers\ReferralResolver;
use App\Models\Product;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Pagine pubbliche: referral, registrazione FAN/Clienti, accesso. */
class SiteController extends Controller
{
    /** Accessi falliti ammessi per coppia email+IP e per sola email, nella finestra di blocco. */
    private const LOGIN_MAX_PER_PAIR = 5;

    private const LOGIN_MAX_PER_EMAIL = 15;

    private const LOGIN_LOCK_SECONDS = 900;

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
    public function home(): View
    {
        $featured = Product::onShelf()->with(['category', 'variants'])->orderBy('id')->limit(3)->get();

        return view('site.home', ['featured' => $featured]);
    }

    public function referral(Request $request, string $code, ReferralResolver $resolver, ReferralInviteService $invites): RedirectResponse
    {
        $consultant = $resolver->find($code);
        if ($consultant === null) {
            return redirect('/registrazione');
        }
        $minutes = (int) config('lamess.referral_cookie_days') * 24 * 60;
        $response = redirect('/registrazione')->withCookie(Cookie::make((string) config('lamess.referral_cookie_name'), (string) $consultant->referral_code, $minutes));

        // link personale di un invito (?i=TOKEN): il click è tracciato e il token resta nel browser fino alla registrazione
        $invite = $invites->recordOpen($request->query('i'), $consultant, $request->userAgent());
        if ($invite !== null) {
            $response->withCookie(Cookie::make((string) config('lamess.referral_invite_cookie_name'), $invite->token, $minutes));
        }

        return $response;
    }

    public function registerForm(Request $request, ReferralResolver $resolver)
    {
        [$sponsor] = $resolver->resolve($request->query('ref'), $request->cookie((string) config('lamess.referral_cookie_name')), null);

        return view('site.register', ['sponsor' => $sponsor]);
    }

    public function register(Request $request, CustomerService $customers, ReferralResolver $resolver): RedirectResponse
    {
        // spazi e minuscole non contano: si salva sempre in maiuscolo, senza spazi
        if ($request->filled('tax_code')) {
            $request->merge(['tax_code' => strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('tax_code')))]);
        }
        $data = $request->validate([
            'type' => 'required|in:private,company', 'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(10)],
            'first_name' => 'required_if:type,private', 'last_name' => 'required_if:type,private',
            'company_name' => 'required_if:type,company', 'vat_number' => 'required_if:type,company',
            'sdi_code' => 'nullable', 'pec' => 'nullable|email', 'tax_code' => ['nullable', 'regex:/^([A-Z0-9]{16}|[0-9]{11})$/'], 'phone' => 'nullable',
            'sponsor_code' => 'nullable', 'privacy' => 'accepted',
        ], self::MESSAGES + ['tax_code.regex' => 'Il codice fiscale deve avere 16 caratteri (11 cifre per le aziende). Se non lo hai a portata di mano, lascia il campo vuoto.'], self::ATTRIBUTES);
        if ($data['type'] === 'company' && blank($data['sdi_code'] ?? null) && blank($data['pec'] ?? null)) {
            return back()->withInput()->withErrors(['sdi_code' => 'Per le aziende serve il codice SDI o la PEC.']);
        }
        $customer = $customers->register($data, $request->input('ref'), $request->cookie((string) config('lamess.referral_cookie_name')), $data['sponsor_code'] ?? null, $request->cookie((string) config('lamess.referral_invite_cookie_name')));
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

        // Blocco per account oltre al limite per IP: un attacco distribuito su una sola email si ferma comunque.
        $email = Str::lower($cred['email']);
        $byPair = 'login:pair:'.sha1($email.'|'.$request->ip());
        $byEmail = 'login:email:'.sha1($email);
        if (RateLimiter::tooManyAttempts($byPair, self::LOGIN_MAX_PER_PAIR) || RateLimiter::tooManyAttempts($byEmail, self::LOGIN_MAX_PER_EMAIL)) {
            $minutes = (int) ceil(max(RateLimiter::availableIn($byPair), RateLimiter::availableIn($byEmail)) / 60);

            return back()->withInput($request->only('email'))->withErrors(['email' => "Troppi tentativi di accesso. Riprova tra {$minutes} minuti oppure usa «Password dimenticata»."]);
        }
        if (! Auth::attempt($cred, $request->boolean('remember'))) {
            RateLimiter::hit($byPair, self::LOGIN_LOCK_SECONDS);
            RateLimiter::hit($byEmail, self::LOGIN_LOCK_SECONDS);

            return back()->withInput($request->only('email'))->withErrors(['email' => 'Credenziali non corrette.']);
        }
        RateLimiter::clear($byPair);
        RateLimiter::clear($byEmail);
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
