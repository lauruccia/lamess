<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light">
<title>@yield('title', 'Lamèss')</title>
<meta name="description" content="@yield('description', 'LAMÈSS Cosmetic Coffee')">
<meta name="theme-color" content="#13110E">
<link rel="icon" href="/favicon.ico?v=2">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Marcellus&display=swap">
<link rel="stylesheet" href="/css/lamess-front.css?v={{ @filemtime(public_path('css/lamess-front.css')) ?: 1 }}">
</head>
<body>
<header class="site-header @yield('headerclass')">
  <div class="wrap bar">
    <a class="brand" href="/" aria-label="Lamèss, home"><img src="/img/lamess-logo.png" alt="" width="38" height="38"><b>Lamèss</b></a>
    <button class="menu-btn" id="menuBtn" aria-expanded="false" aria-controls="nav">Menu</button>
    <nav id="nav" aria-label="Principale"><ul><li><a href="/"{{ request()->is('/') ? ' aria-current=page' : '' }}>Home</a></li><li><a href="/il-prodotto"{{ request()->is('il-prodotto') ? ' aria-current=page' : '' }}>Prodotto</a></li><li><a href="/perche-lamess"{{ request()->is('perche-lamess') ? ' aria-current=page' : '' }}>Perché Lamèss</a></li><li><a href="/la-scienza"{{ request()->is('la-scienza') ? ' aria-current=page' : '' }}>La scienza</a></li><li><a href="/negozio"{{ request()->is('negozio', 'negozio/*') ? ' aria-current=page' : '' }}>Shop</a></li><li><a href="/lavora-con-noi"{{ request()->is('lavora-con-noi') ? ' aria-current=page' : '' }}>Lavora con noi</a></li><li><a href="/chi-siamo"{{ request()->is('chi-siamo') ? ' aria-current=page' : '' }}>Chi siamo</a></li><li><a href="/domande"{{ request()->is('domande') ? ' aria-current=page' : '' }}>Domande</a></li>
      <li><a href="/carrello">Carrello</a></li>
      @auth
      <li><a href="/area">La mia area</a></li>
      <li><form method="post" action="/esci" style="margin:0">@csrf<button type="submit" class="linkbtn">Esci</button></form></li>
      @else
      <li><a href="/accedi">Accedi</a></li><li><a class="hbtn gold sm" href="/registrazione">Registrati</a></li>
      @endauth
    </ul></nav>
  </div>
</header>
<main>
@yield('content')
</main>
<footer>
  <div class="wrap">
    <div class="foot">
      <div>
        <div class="logo" style="font-size:2rem">LAMÈSS</div>
        <p style="margin-top:14px;color:var(--taupe)">Il caffè che non hai mai bevuto.<br>Stop Time, Drink Beauty.</p>
      </div>
      <div>
        <h4>Il sito</h4>
        <ul><li><a href="/perche-lamess">Perché Lamèss</a></li><li><a href="/il-prodotto">Prodotto e ingredienti</a></li><li><a href="/la-scienza">La scienza</a></li><li><a href="/negozio">Shop</a></li><li><a href="/lavora-con-noi">Lavora con noi</a></li><li><a href="/chi-siamo">Chi siamo</a></li><li><a href="/domande">Domande frequenti</a></li><li><a href="/contatti">Contatti</a></li></ul>
      </div>
      <div>
        <h4>Il tuo spazio</h4>
        <ul>@auth<li><a href="/area">La mia area</a></li><li><a href="/area/ordini">I miei ordini</a></li>@else<li><a href="/accedi">Accedi</a></li><li><a href="/registrazione">Registrati</a></li>@endauth<li><a href="/negozio">Negozio</a></li><li><a href="/carrello">Carrello</a></li></ul>
      </div>
      <div>
        <h4>Contatti</h4>
        <ul><li>Numero verde 800 911 377</li><li><a href="mailto:info@lamess.it">info@lamess.it</a></li><li><a href="/politiche-di-reso">Spedizione e resi</a></li><li><a href="/termini">Termini e condizioni</a></li><li><a href="/privacy">Privacy</a></li></ul>
      </div>
    </div>
    @verbatim<p class="legal">KADEO GROUP – Tutti i diritti riservati. CONSORZIO KADEO GROUP – VIA G FERRARIS 63 – AREZZO – P.IVA 02530190517 ● PEC: kadeogroup@pec.it<br>Lamèss Cosmetic Coffee è un integratore alimentare e non un medicinale. Non è destinato a prevenire, trattare o curare malattie e non sostituisce una dieta varia ed equilibrata e uno stile di vita sano. Gli integratori vanno utilizzati nel rispetto delle indicazioni e delle avvertenze in etichetta. Gli studi citati riguardano i singoli ingredienti e non garantiscono risultati individuali né dimostrano l’efficacia del prodotto finito.</p>@endverbatim
  </div>
</footer>
<script src="/js/lamess-front.js?v={{ @filemtime(public_path('js/lamess-front.js')) ?: 1 }}"></script>
<script>var __h=document.querySelector(".site-header.home-top");if(__h){var __f=function(){__h.classList.toggle("solid",scrollY>24)};__f();addEventListener("scroll",__f,{passive:true})}</script>
@stack('scripts')
</body>
</html>
