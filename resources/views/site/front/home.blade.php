@extends('site.front.layout')
@section('title', __('Lamèss Cosmetic Coffee'))
@section('description', __('LAMÈSS è il caffè cosmetico Arabica 100% AAA con collagene, acido ialuronico, coenzima Q10 e vitamine, a zero zuccheri. Stop Time, Drink Beauty.'))
@section('headerclass', 'home-top')
@section('content')

<section class="top-hero" id="top">
 <canvas id="silk" class="scene" aria-hidden="true"></canvas>
 <canvas id="dust" class="scene" aria-hidden="true"></canvas>
 <div class="grain" aria-hidden="true"></div>
 <div class="wrap hero-body">
  <div class="cup-space" id="cupSpace" aria-hidden="true"></div>
  <div class="hero-copy">
   <div class="eyebrow">{{ __('Cosmetic Coffee') }}</div>
   <h1>{{ __('Stop') }} <em class="grad">{{ __('Time') }}</em></h1>
   <p class="lead">{{ __('Il caffè che trasforma un gesto quotidiano in un rituale di bellezza.') }}</p>
   <div class="hero-cta">@if(\App\Support\ShopAccess::canBuy())<a class="hbtn gold" href="/negozio">{{ __('Scegli la tua box') }}</a>@else<a class="hbtn gold" href="/accedi">{{ __('Accedi per acquistare') }}</a>@endif@feature('site.front.perche')<a class="hbtn line" href="/perche-lamess">{{ __('Perché Lamèss') }}</a>@endfeature</div>
   <p class="top-note">{{ __('Lamèss è un integratore alimentare.') }}</p>
  </div>
 </div>
 <a class="scroll" href="#prodotto">{{ __('Scopri') }}</a>
</section>

<section class="light band" id="prodotto">
  <div class="wrap">
    <div class="intro">
      <div><p class="kicker">{{ __('Il prodotto') }}</p><h2>{{ __('Lamèss è un Cosmetic Coffee: un caffè pensato per andare oltre il gusto.') }}</h2></div>
      <p>{{ __('La base è un caffè istantaneo 100% Arabica di classe tripla AAA, con collagene VERISOL®, acido ialuronico e coenzima Q10. Una bustina, acqua calda o fredda: l’integrazione diventa parte di una pausa che ti piace.') }}</p>
    </div>
    <div class="pillars">
      <div class="pillar"><div class="n">01</div><h3>{{ __('Un solo gesto') }}</h3><p>{{ __('Caffè e integrazione nella stessa bustina, senza niente in più da ricordare.') }}</p></div>
      <div class="pillar"><div class="n">02</div><h3>{{ __('Arabica 100% AAA') }}</h3><p>{{ __('Miscela di tripla classe. Un vero caffè, con il suo gusto e il suo profumo.') }}</p></div>
      <div class="pillar"><div class="n">03</div><h3>{{ __('Dosi in etichetta') }}</h3><p>{{ __('2,5 g di VERISOL®, 190 mg di acido ialuronico, 20 mg di coenzima Q10.') }}</p></div>
      <div class="pillar"><div class="n">04</div><h3>{{ __('Zero zuccheri') }}</h3><p>{{ __('Gusto autentico, adatto a stili di vita salutari.') }}</p></div>
    </div>
    <div class="row" style="margin-top:44px">@feature('site.front.perche')<a class="hbtn dark" href="/perche-lamess">{{ __('Tutti i motivi') }}</a>@endfeature @feature('site.front.prodotto')<a class="hbtn ghost" href="/il-prodotto">{{ __('Ingredienti e dosi') }}</a>@endfeature @feature('site.front.scienza')<a class="hbtn ghost" href="/la-scienza">{{ __('La scienza') }}</a>@endfeature</div>
  </div>
</section>

<section class="light band" style="padding-top:0">
  <div class="wrap">
    <div class="shelf-head"><div><p class="kicker">{{ __('Il negozio') }}</p><h2>{{ __('Scegli il tuo rituale') }}</h2></div><a class="more" href="/negozio">{{ __('Tutti i prodotti →') }}</a></div>
    <div class="pgrid">
@foreach($featured as $p)
@php($showPrices = \App\Support\ShopAccess::canBuy())
@php($range = $showPrices && $p->hasVariants() ? $p->variantPriceRange(auth()->user()) : null)
@php($saving = $showPrices && ! $p->hasVariants() ? $p->savingPercent(auth()->user()) : null)
<article class="pcard"><a class="pcard-img" href="{{ $p->shopUrl() }}" tabindex="-1" aria-hidden="true">@include('site.shop._thumb', ['product' => $p, 'alt' => $p->displayName()])@if($saving)<span class="tags"><span class="tag hot">−{{ $saving }}%</span></span>@endif @if(($km = $p->kmoneyPercent()) > 0)<span class="tags bottom"><span class="tag km">{{ __(':km% in KMoney', ['km' => $km]) }}</span></span>@endif</a>
<div class="pcard-body"><h3>{{ $p->displayName() }}</h3>
@if(! $showPrices)@elseif($range)<p class="price">@if($range[0] !== $range[1])<small>{{ __('da') }}</small> @endif<b>€ {{ \App\Support\Money::eur($range[0]) }}</b><small>{{ __('IVA inclusa') }}</small></p>
@else<p class="price"><b>€ {{ \App\Support\Money::eur($p->grossPriceFor(auth()->user())) }}</b>@if($p->compareAtGross()) <s>€ {{ \App\Support\Money::eur($p->compareAtGross()) }}</s>@endif<small>{{ __('IVA inclusa') }}</small></p>@endif
<a class="hbtn dark" href="{{ $p->shopUrl() }}">{{ $showPrices ? 'Acquista nel negozio' : 'Scopri il prodotto' }}</a></div></article>
@endforeach
    </div>
  </div>
</section>

<section class="philo band" id="filosofia">
  <div class="wrap philo-grid">
    <div>
      <p class="kicker">{{ __('La nostra filosofia') }}</p>
      <h2>{{ __('Stop') }} <span class="grad">{{ __('Time') }}</span></h2>
      <div class="state" aria-live="polite"><p id="stateText">{{ __('Stop Time è un invito a rallentare e a vivere il tempo con maggiore consapevolezza. È la scelta di fermarsi, anche solo per un momento, e trasformare un gesto abituale in un rituale di bellezza personale.') }}</p></div>
      <div class="hero-cta" style="margin-top:0"><button class="hbtn gold" id="stopBtn" type="button">{{ __('Ferma il tempo') }}</button>@if(\App\Support\ShopAccess::canBuy())<a class="hbtn line" href="/negozio">{{ __('Entra in Lamèss') }}</a>@else<a class="hbtn line" href="/accedi">{{ __('Accedi') }}</a>@endif</div>
      <p style="margin-top:26px;font-size:.95rem;color:rgba(252,246,243,.7)">{{ __('“Stop Time” è il concept del progetto e non promette di fermare l’invecchiamento.') }}</p>
    </div>
    <div class="clock" id="clock">
      <svg viewBox="0 0 400 400" role="img" aria-label="{{ __('Orologio con le lancette in movimento. Il pulsante Ferma il tempo le blocca.') }}">
        <circle cx="200" cy="200" r="192" fill="none" stroke="#E3AA8D" stroke-width="1.5"/>
        <circle cx="200" cy="200" r="178" fill="none" stroke="#BBB4AF" stroke-width=".8" opacity=".4"/>
        <g id="ticks"></g>
        <line class="hand" id="hourH" x1="200" y1="200" x2="200" y2="124" stroke="#FCF6F3" stroke-width="7" stroke-linecap="round"/>
        <line class="hand" id="minH" x1="200" y1="200" x2="200" y2="82" stroke="#FCF6F3" stroke-width="4.5" stroke-linecap="round"/>
        <line class="hand sec" id="secH" x1="200" y1="226" x2="200" y2="62"/>
        <circle cx="200" cy="200" r="8" fill="#C2663A"/>
        <text x="200" y="285" text-anchor="middle" fill="#E3AA8D" font-family="Marcellus,Georgia,serif" font-size="20" letter-spacing="4" id="clockLabel">{{ __('LAMÈSS') }}</text>
      </svg>
    </div>
  </div>
</section>

<section class="how band">
  <div class="wrap">
    <p class="kicker">{{ __('Come funziona') }}</p>
    <h2>{{ __('Dal primo caffè al tuo spazio personale, in tre passi.') }}</h2>
    <div class="how-steps">
      <div class="how-step"><div class="n">01</div><h3>{{ __('Registrati') }}</h3><p>{{ __('Crei il tuo spazio in pochi minuti. È gratuito e non c’è nessun obbligo di acquisto.') }}</p></div>
      <div class="how-step"><div class="n">02</div><h3>{{ __('Scegli la tua box') }}</h3><p>{{ __('Ordina quando vuoi, oppure attiva l’abbonamento: lo metti in pausa o lo interrompi in qualsiasi momento.') }}</p></div>
      <div class="how-step"><div class="n">03</div><h3>{{ __('Vivi il rituale') }}</h3><p>{{ __('Segui ordini, spedizioni e abbonamenti dalla tua area personale, con assistenza diretta.') }}</p></div>
    </div>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    <div class="mini">
      <div><b>1.200+</b><span>{{ __('box distribuite dal debutto del 3 giugno 2026') }}</span></div>
      <div><b>~600</b><span>{{ __('iscritti tra clienti e Brand Partner') }}</span></div>
      <div><b>3</b><span>{{ __('Lamèss Point attivi a ottobre 2026') }}</span></div>
      <div><b>{{ __('~4 anni') }}</b><span>{{ __('di ricerca e sviluppo') }}</span></div>
    </div>
  </div>
</section>

<section class="white band">
  <div class="wrap amb">
    <div>
      <p class="kicker">{{ __('Oltre il prodotto, un’appartenenza') }}</p>
      <h2>{{ __('Che cos’è un incaricato Lamèss?') }}</h2>
      <p>{{ __('Chi sceglie di farsi interprete della filosofia Stop Time nella propria quotidianità, con una pagina personale, un link referral e un’area riservata.') }}</p>
      <p>{{ __('Per chi ha un’attività esiste anche il Lamèss Point: un riferimento locale dove conoscere e provare il prodotto.') }}</p>
      @feature('site.front.lavora')<a class="hbtn dark" href="/lavora-con-noi">{{ __('Scopri come lavorare con noi') }}</a>@endfeature
    </div>
    <div class="amb-card">
      <h3>{{ __('Un progetto in continua evoluzione') }}</h3>
      <ul><li>{{ __('Costruito intorno a una visione condivisa del tempo, del gesto e della cura di sé.') }}</li><li>{{ __('Ogni relazione nasce dall’ascolto e dalla volontà di comprendere.') }}</li><li>{{ __('Si parte da una registrazione gratuita: il resto, quando e se lo desideri.') }}</li></ul>
    </div>
  </div>
</section>

<section class="light band">
  <div class="wrap">
    @feature('site.front.faq')<div class="cta-band" style="margin-bottom:34px"><h2>{{ __('Le domande più frequenti.') }}</h2><a class="hbtn ghost" href="/domande">{{ __('Tutte le domande') }}</a></div>@endfeature
    <div class="faq">
      <details><summary>{{ __('Lamèss è un medicinale?') }}</summary><p>{{ __('No. È un integratore alimentare. Non è destinato a prevenire, trattare o curare malattie.') }}</p></details>
      <details><summary>{{ __('Come si prepara?') }}</summary><p>{{ __('Si scioglie una bustina in acqua calda o in acqua fredda, come preferisci.') }}</p></details>
      <details><summary>{{ __('Quante buste posso prendere al giorno?') }}</summary><p>{{ __('La dose massima giornaliera è di 2 buste. Segui sempre le indicazioni e le avvertenze in etichetta.') }}</p></details>
    </div>
  </div>
</section>

<section class="final">
  <div class="wrap">
    <h2>{{ __('Pronto a fermare il tempo?') }}</h2>
    <p>{{ __('Accedi al tuo spazio o creane uno nuovo: ordini, abbonamenti e assistenza in un unico posto.') }}</p>
    <div class="hero-cta">@auth<a class="hbtn gold" href="/area">{{ __('La mia area') }}</a><a class="hbtn line" href="/negozio">{{ __('Vai al negozio') }}</a>@else
@if(\App\Domain\Customers\RegistrationGate::linksVisible())<a class="hbtn gold" href="/registrazione">{{ __('Registrati') }}</a>@endif<a class="hbtn line" href="/accedi">{{ __('Accedi') }}</a>@endauth
</div>
    @feature('site.front.contatti')<p class="contact">{!! __('Vuoi saperne di più o parlare con il team? <a href="/contatti">Scrivici</a>.') !!}</p>@endfeature
  </div>
</section>

@endsection
@push('scripts')
<script src="/js/lamess-front-home.js?v={{ @filemtime(public_path('js/lamess-front-home.js')) ?: 1 }}"></script>
@endpush
