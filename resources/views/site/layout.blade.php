<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Lamèss')</title>
@stack('head')
<link rel="icon" href="/favicon.ico?v=2">
<link rel="stylesheet" href="/css/lamess-tokens.css?v={{ @filemtime(public_path('css/lamess-tokens.css')) ?: 1 }}">
<link rel="stylesheet" href="/css/lamess-site.css?v={{ @filemtime(public_path('css/lamess-site.css')) ?: 1 }}">
<link rel="stylesheet" href="/css/lamess-area.css?v={{ @filemtime(public_path('css/lamess-area.css')) ?: 1 }}">
<link rel="stylesheet" href="/css/lamess-shop.css?v={{ @filemtime(public_path('css/lamess-shop.css')) ?: 1 }}">
@stack('styles')
<script src="/js/lamess-shop.js?v={{ @filemtime(public_path('js/lamess-shop.js')) ?: 1 }}" defer></script>
</head>
@php($isAuth = trim($__env->yieldContent('width')) === 'auth')
@php($minimalHeader = trim($__env->yieldContent('header')) === 'minimal')
@php($isFull = trim($__env->yieldContent('width')) === 'full')
<body class="{{ $isAuth ? 'is-auth' : '' }} {{ $isFull ? 'is-full' : '' }} @yield('bodyclass')">
@php($nav = request()->path())
@php($me = auth()->user())
@php($isConsultant = $me?->consultant && $me->consultant->status !== 'terminated')
@php(\App\Domain\Orders\PendingCart::pending(request()))
@php($cartCount = array_sum((array) session('cart', [])) + array_sum(array_map('array_sum', (array) session('cart_others', []))))
<header class="site-header">
 <div class="bar">
  <a class="brand" href="/" aria-label="Lamèss">
   <img src="/img/lamess-logo.png" width="38" height="38" alt="">
   <b>Lamèss</b>
  </a>
  @if($minimalHeader)
  <nav class="top-links">
   <span class="muted" style="display:inline-flex;align-items:center;gap:6px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Pagamento sicuro</span>
   <a href="/carrello">← Torna al carrello</a>
  </nav>
  @else
  <nav class="top-links">
   @hasSection('nav_extra')
    @yield('nav_extra')
   @else
    @guest
     <a class="hide-md" href="/#stop-time">Stop Time</a><a class="hide-md" href="/#prodotto">Prodotto</a><a class="hide-md" href="/#ambassador">Ambassador</a>
    @endguest
   @endif
   <a href="/negozio" class="{{ str_starts_with($nav, 'negozio') ? 'on' : '' }}">Negozio</a>
   <a class="cart-btn" href="/carrello" data-cart-open aria-label="Carrello, {{ $cartCount }} prodotti">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
    <span class="cart-count" data-cart-count @if($cartCount < 1) hidden @endif>{{ $cartCount }}</span>
   </a>
   @auth
    <a class="hide-sm" href="/area">La mia area</a>
    <form method="post" action="/esci">@csrf<button class="link">Esci</button></form>
   @else
    <a href="/accedi">Accedi</a><a class="cta" href="/registrazione">Registrati</a>
   @endauth
  </nav>
  @endif
 </div>
 @unless($minimalHeader)
 @if(request()->is('area', 'area/*', 'piano-compensi'))
 @auth
 <div class="subnav" aria-label="Area personale">
  @php($items = array_filter([
    ['/area', 'Panoramica', fn ($p) => $p === 'area'],
    $isConsultant ? ['/area/rete', 'Il mio ramo', fn ($p) => str_starts_with($p, 'area/rete')] : null,
    $isConsultant ? ['/area/clienti', 'I miei clienti', fn ($p) => str_starts_with($p, 'area/clienti')] : null,
    $isConsultant ? ['/area/statini', 'Statini', fn ($p) => str_starts_with($p, 'area/statini')] : null,
    $isConsultant && \App\Domain\Wallet\WithdrawalService::enabled() ? ['/area/wallet', 'Tasca', fn ($p) => str_starts_with($p, 'area/wallet')] : null,
    $isConsultant && (config('lamess.fiscal.receipts_enabled') || \App\Models\CommissionReceipt::where('consultant_id', $me->consultant->id)->exists()) ? ['/area/ricevute', 'Ricevute', fn ($p) => str_starts_with($p, 'area/ricevute')] : null,
    ['/area/ordini', 'Ordini', fn ($p) => str_starts_with($p, 'area/ordini')],
    ['/area/abbonamenti', 'Abbonamenti', fn ($p) => str_starts_with($p, 'area/abbonamenti')],
    ['/area/novita', 'Novità', fn ($p) => str_starts_with($p, 'area/novita')],
    $isConsultant ? ['/area/materiali', 'Materiali', fn ($p) => str_starts_with($p, 'area/materiali')] : null,
    ['/area/eventi', 'Eventi', fn ($p) => str_starts_with($p, 'area/eventi')],
    $isConsultant ? ['/area/conformita', 'Conformità', fn ($p) => str_starts_with($p, 'area/conformita')] : null,
    $isConsultant ? ['/area/referral', 'Referral', fn ($p) => str_starts_with($p, 'area/referral')] : null,
    $isConsultant ? ['/piano-compensi', 'Il piano compensi', fn ($p) => $p === 'piano-compensi'] : null,
    ['/area/assistenza', 'Assistenza', fn ($p) => str_starts_with($p, 'area/assistenza')],
    ['/area/profilo', 'I miei dati', fn ($p) => str_starts_with($p, 'area/profilo')],
  ]))
  @foreach($items as [$href, $label, $on])<a href="{{ $href }}" class="{{ $on($nav) ? 'on' : '' }}">{{ $label }}</a>@endforeach
 </div>
 @endauth
 @endif
 @endunless
</header>
<main class="@yield('width')">
@if($isAuth)
<div class="auth-wrap">
 <aside class="auth-side">
  <h2>@yield('side_title', 'Il tuo spazio Lamèss')</h2>
  <p>@yield('side_text', 'Ordini, abbonamenti e, se sei incaricato, rete e compensi: tutto in un unico posto.')</p>
  <ul><li>Registrazione gratuita, nessun obbligo di acquisto</li><li>I tuoi dati restano tuoi e sono protetti</li><li>Assistenza diretta dall'area personale</li></ul>
 </aside>
 <section class="auth-form"><div class="auth-inner">
  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @yield('content')
 </div></section>
</div>
@else
@if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
@yield('content')
@endif
</main>
<div class="drawer" id="cart-drawer" hidden>
 <div class="drawer-overlay" data-drawer-close></div>
 <aside class="drawer-panel" role="dialog" aria-modal="true" aria-label="Il tuo carrello" tabindex="-1"><div class="drawer-body" id="cart-drawer-body"></div></aside>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
@if($isAuth || $minimalHeader)
<footer class="site-footer">© {{ date('Y') }} Lamèss &nbsp;·&nbsp; <a href="/negozio">Negozio</a>@auth · <a href="/area/profilo">I miei dati</a>@endauth &nbsp;·&nbsp; <a href="/privacy">Privacy</a> · <a href="/termini">Termini e Condizioni</a> · <a href="/politiche-di-reso">Politiche di Reso</a> · <a href="/piano-compensi">Il piano compensi</a> · <a href="/regolamento">Regolamento</a>
 @php($co = config('lamess.company'))
 <br><small>© {{ date('Y') }} {{ $co['brand_owner'] }} – Tutti i diritti riservati. {{ $co['name'] }} - {{ $co['address'] }} - P.IVA {{ $co['vat'] }} ● PEC: <a href="mailto:{{ $co['pec'] }}">{{ $co['pec'] }}</a> ● Designed by <a href="https://gruppokosmos.it" target="_blank" rel="noopener">Gruppo Kosmos</a></small>
</footer>
@else
<footer class="site-footer rich">
 <div class="foot-grid">
  <div class="foot-brand">
   <a class="brand" href="/" aria-label="Lamèss"><img src="/img/lamess-logo.png" width="34" height="34" alt=""><b>Lamèss</b></a>
   <p>I prodotti Lamèss, spediti a casa tua.</p>
  </div>
  <nav aria-label="Negozio"><h4>Negozio</h4><a href="/negozio">Tutti i prodotti</a><a href="/carrello">Carrello</a></nav>
  <nav aria-label="Il tuo spazio"><h4>Il tuo spazio</h4>
   @auth<a href="/area">La mia area</a><a href="/area/ordini">I miei ordini</a><a href="/area/abbonamenti">Abbonamenti</a><a href="/area/assistenza">Assistenza</a>
   @else<a href="/accedi">Accedi</a><a href="/registrazione">Registrati</a>@endauth
  </nav>
  <nav aria-label="Collegamenti utili"><h4>Collegamenti utili</h4><a href="/privacy">Privacy Policy</a><a href="/politiche-di-reso">Politiche di Reso</a><a href="/termini">Termini e Condizioni</a></nav>
  <div><h4>Acquisti sicuri</h4><p>Pagamento con carta, bonifico o KMoney. I tuoi dati restano tuoi e sono protetti.</p></div>
 </div>
 @php($co = config('lamess.company'))
 <p class="foot-copy">© {{ date('Y') }} {{ $co['brand_owner'] }} – Tutti i diritti riservati. {{ $co['name'] }} - {{ $co['address'] }} - P.IVA {{ $co['vat'] }} ● PEC: <a href="mailto:{{ $co['pec'] }}">{{ $co['pec'] }}</a> ● <a href="mailto:{{ $co['email'] }}">{{ $co['email'] }}</a> ● Designed by <a href="https://gruppokosmos.it" target="_blank" rel="noopener">Gruppo Kosmos</a></p>
</footer>
@endif
</body></html>
