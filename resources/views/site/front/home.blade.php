@extends('site.front.layout')
@section('title', 'Lamèss Cosmetic Coffee')
@section('description', 'LAMÈSS è il caffè cosmetico Arabica 100% AAA con collagene, acido ialuronico, coenzima Q10 e vitamine, a zero zuccheri. Stop Time, Drink Beauty.')
@section('headerclass', 'home-top')
@section('content')
@verbatim

<section class="top-hero" id="top">
 <canvas id="silk" class="scene" aria-hidden="true"></canvas>
 <canvas id="dust" class="scene" aria-hidden="true"></canvas>
 <div class="grain" aria-hidden="true"></div>
 <div class="wrap hero-body">
  <div class="cup-space" id="cupSpace" aria-hidden="true"></div>
  <div class="hero-copy">
   <div class="eyebrow">Cosmetic Coffee</div>
   <h1>Stop <em class="grad">Time</em></h1>
   <p class="lead">Il caffè che trasforma un gesto quotidiano in un rituale di bellezza.</p>
   <div class="hero-cta"><a class="hbtn gold" href="/negozio">Scegli la tua box</a><a class="hbtn line" href="/perche-lamess">Perché Lamèss</a></div>
   <p class="top-note">Lamèss è un integratore alimentare.</p>
  </div>
 </div>
 <a class="scroll" href="#prodotto">Scopri</a>
</section>

<section class="light band" id="prodotto">
  <div class="wrap">
    <div class="intro">
      <div><p class="kicker">Il prodotto</p><h2>Lamèss è un Cosmetic Coffee: un caffè pensato per andare oltre il gusto.</h2></div>
      <p>La base è un caffè istantaneo 100% Arabica di classe tripla AAA, con collagene VERISOL®, acido ialuronico e coenzima Q10. Una bustina, acqua calda o fredda: l’integrazione diventa parte di una pausa che ti piace.</p>
    </div>
    <div class="pillars">
      <div class="pillar"><div class="n">01</div><h3>Un solo gesto</h3><p>Caffè e integrazione nella stessa bustina, senza niente in più da ricordare.</p></div>
      <div class="pillar"><div class="n">02</div><h3>Arabica 100% AAA</h3><p>Miscela di tripla classe. Un vero caffè, con il suo gusto e il suo profumo.</p></div>
      <div class="pillar"><div class="n">03</div><h3>Dosi in etichetta</h3><p>2,5 g di VERISOL®, 190 mg di acido ialuronico, 20 mg di coenzima Q10.</p></div>
      <div class="pillar"><div class="n">04</div><h3>Zero zuccheri</h3><p>Gusto autentico, adatto a stili di vita salutari.</p></div>
    </div>
    <div class="row" style="margin-top:44px"><a class="hbtn dark" href="/perche-lamess">Tutti i motivi</a><a class="hbtn ghost" href="/il-prodotto">Ingredienti e dosi</a><a class="hbtn ghost" href="/la-scienza">La scienza</a></div>
  </div>
</section>

<section class="light band" style="padding-top:0">
  <div class="wrap">
    <div class="shelf-head"><div><p class="kicker">Il negozio</p><h2>Scegli il tuo rituale</h2></div><a class="more" href="/negozio">Tutti i prodotti →</a></div>
    <div class="pgrid">
      @endverbatim
@foreach($featured as $p)
@php($range = $p->hasVariants() ? $p->variantPriceRange(auth()->user()) : null)
@php($saving = $p->hasVariants() ? null : $p->savingPercent(auth()->user()))
<article class="pcard"><a class="pcard-img" href="{{ $p->shopUrl() }}" tabindex="-1" aria-hidden="true">@include('site.shop._thumb', ['product' => $p, 'alt' => $p->displayName()])@if($saving)<span class="tags"><span class="tag hot">−{{ $saving }}%</span></span>@endif @if(($km = $p->kmoneyPercent()) > 0)<span class="tags bottom"><span class="tag km">{{ $km }}% in KMoney</span></span>@endif</a>
<div class="pcard-body"><h3>{{ $p->displayName() }}</h3>
@if($range)<p class="price">@if($range[0] !== $range[1])<small>da</small> @endif<b>€ {{ \App\Support\Money::eur($range[0]) }}</b><small>IVA inclusa</small></p>
@else<p class="price"><b>€ {{ \App\Support\Money::eur($p->grossPriceFor(auth()->user())) }}</b>@if($p->compareAtGross()) <s>€ {{ \App\Support\Money::eur($p->compareAtGross()) }}</s>@endif<small>IVA inclusa</small></p>@endif
<a class="hbtn dark" href="{{ $p->shopUrl() }}">Acquista nel negozio</a></div></article>
@endforeach
@verbatim
    </div>
  </div>
</section>

<section class="philo band" id="filosofia">
  <div class="wrap philo-grid">
    <div>
      <p class="kicker">La nostra filosofia</p>
      <h2>Stop <span class="grad">Time</span></h2>
      <div class="state" aria-live="polite"><p id="stateText">Stop Time è un invito a rallentare e a vivere il tempo con maggiore consapevolezza. È la scelta di fermarsi, anche solo per un momento, e trasformare un gesto abituale in un rituale di bellezza personale.</p></div>
      <div class="hero-cta" style="margin-top:0"><button class="hbtn gold" id="stopBtn" type="button">Ferma il tempo</button><a class="hbtn line" href="/negozio">Entra in Lamèss</a></div>
      <p style="margin-top:26px;font-size:.95rem;color:rgba(252,246,243,.7)">“Stop Time” è il concept del progetto e non promette di fermare l’invecchiamento.</p>
    </div>
    <div class="clock" id="clock">
      <svg viewBox="0 0 400 400" role="img" aria-label="Orologio con le lancette in movimento. Il pulsante Ferma il tempo le blocca.">
        <circle cx="200" cy="200" r="192" fill="none" stroke="#E3AA8D" stroke-width="1.5"/>
        <circle cx="200" cy="200" r="178" fill="none" stroke="#BBB4AF" stroke-width=".8" opacity=".4"/>
        <g id="ticks"></g>
        <line class="hand" id="hourH" x1="200" y1="200" x2="200" y2="124" stroke="#FCF6F3" stroke-width="7" stroke-linecap="round"/>
        <line class="hand" id="minH" x1="200" y1="200" x2="200" y2="82" stroke="#FCF6F3" stroke-width="4.5" stroke-linecap="round"/>
        <line class="hand sec" id="secH" x1="200" y1="226" x2="200" y2="62"/>
        <circle cx="200" cy="200" r="8" fill="#C2663A"/>
        <text x="200" y="285" text-anchor="middle" fill="#E3AA8D" font-family="Marcellus,Georgia,serif" font-size="20" letter-spacing="4" id="clockLabel">LAMÈSS</text>
      </svg>
    </div>
  </div>
</section>

<section class="how band">
  <div class="wrap">
    <p class="kicker">Come funziona</p>
    <h2>Dal primo caffè al tuo spazio personale, in tre passi.</h2>
    <div class="how-steps">
      <div class="how-step"><div class="n">01</div><h3>Registrati</h3><p>Crei il tuo spazio in pochi minuti. È gratuito e non c’è nessun obbligo di acquisto.</p></div>
      <div class="how-step"><div class="n">02</div><h3>Scegli la tua box</h3><p>Ordina quando vuoi, oppure attiva l’abbonamento: lo metti in pausa o lo interrompi in qualsiasi momento.</p></div>
      <div class="how-step"><div class="n">03</div><h3>Vivi il rituale</h3><p>Segui ordini, spedizioni e abbonamenti dalla tua area personale, con assistenza diretta.</p></div>
    </div>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    <div class="mini">
      <div><b>1.200+</b><span>box distribuite dal debutto del 3 giugno 2026</span></div>
      <div><b>~600</b><span>iscritti tra clienti e Brand Partner</span></div>
      <div><b>3</b><span>Lamèss Point attivi a ottobre 2026</span></div>
      <div><b>~4 anni</b><span>di ricerca e sviluppo</span></div>
    </div>
  </div>
</section>

<section class="white band">
  <div class="wrap amb">
    <div>
      <p class="kicker">Oltre il prodotto, un’appartenenza</p>
      <h2>Che cos’è un incaricato Lamèss?</h2>
      <p>Chi sceglie di farsi interprete della filosofia Stop Time nella propria quotidianità, con una pagina personale, un link referral e un’area riservata.</p>
      <p>Per chi ha un’attività esiste anche il Lamèss Point: un riferimento locale dove conoscere e provare il prodotto.</p>
      <a class="hbtn dark" href="/lavora-con-noi">Scopri come lavorare con noi</a>
    </div>
    <div class="amb-card">
      <h3>Un progetto in continua evoluzione</h3>
      <ul><li>Costruito intorno a una visione condivisa del tempo, del gesto e della cura di sé.</li><li>Ogni relazione nasce dall’ascolto e dalla volontà di comprendere.</li><li>Si parte da una registrazione gratuita: il resto, quando e se lo desideri.</li></ul>
    </div>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    <div class="cta-band" style="margin-bottom:34px"><h2>Le domande più frequenti.</h2><a class="hbtn ghost" href="/domande">Tutte le domande</a></div>
    <div class="faq">
      <details><summary>Lamèss è un medicinale?</summary><p>No. È un integratore alimentare. Non è destinato a prevenire, trattare o curare malattie.</p></details>
      <details><summary>Come si prepara?</summary><p>Si scioglie una bustina in acqua calda o in acqua fredda, come preferisci.</p></details>
      <details><summary>Quante buste posso prendere al giorno?</summary><p>La dose massima giornaliera è di 2 buste. Segui sempre le indicazioni e le avvertenze in etichetta.</p></details>
    </div>
  </div>
</section>

<section class="final">
  <div class="wrap">
    <h2>Pronto a fermare il tempo?</h2>
    <p>Accedi al tuo spazio o creane uno nuovo: ordini, abbonamenti e assistenza in un unico posto.</p>
    <div class="hero-cta">@endverbatim
@auth<a class="hbtn gold" href="/area">La mia area</a><a class="hbtn line" href="/negozio">Vai al negozio</a>@else<a class="hbtn gold" href="/registrazione">Registrati</a><a class="hbtn line" href="/accedi">Accedi</a>@endauth
@verbatim</div>
    <p class="contact">Vuoi saperne di più o parlare con il team? <a href="/contatti">Scrivici</a>.</p>
  </div>
</section>

@endverbatim
@endsection
@push('scripts')
<script src="/js/lamess-front-home.js?v={{ @filemtime(public_path('js/lamess-front-home.js')) ?: 1 }}"></script>
@endpush
