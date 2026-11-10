@extends('site.layout')
@section('title', __('LAMÈSS — Stop Time, il primo caffè cosmetico brevettato'))
@section('width', 'home')
@section('bodyclass', 'is-home')
@push('head')
<meta name="description" content="LAMÈSS è il primo caffè cosmetico brevettato: Arabica 100% AAA con acido ialuronico, zero zuccheri. Accedi o registrati gratis per ordinare e vivere il rituale Stop Time.">
<meta name="theme-color" content="#13110E">
<script src="/js/lamess-home.js?v={{ @filemtime(public_path('js/lamess-home.js')) ?: 1 }}" defer></script>
@endpush
@push('styles')
<link rel="stylesheet" href="/css/lamess-home.css?v={{ @filemtime(public_path('css/lamess-home.css')) ?: 1 }}">
@endpush
@section('nav_extra')
<a class="hide-md" href="#stop-time">{{ __('Stop Time') }}</a><a class="hide-md" href="#prodotto">{{ __('Prodotto') }}</a><a class="hide-md" href="#ambassador">{{ __('Incaricato') }}</a>
@endsection
@section('content')
@php($co = config('lamess.company'))
@php($logged = auth()->check())

<header class="hero" id="top">
 <canvas id="silk" class="scene" aria-hidden="true"></canvas>
 <canvas id="dust" class="scene" aria-hidden="true"></canvas>
 <div class="grain" aria-hidden="true"></div>
 <div class="wrap hero-body">
  <div class="cup-space" id="cupSpace" aria-hidden="true"></div>
  <div class="hero-copy">
   <div class="eyebrow">{{ __('Cosmetic Coffee') }}</div>
   <h1>{{ __('Stop') }} <em>{{ __('Time') }}</em></h1>
   <p class="lead">{{ __('Il caffè che trasforma un gesto quotidiano in un rituale di bellezza.') }}</p>
   <div class="hero-cta">
    @if($logged)
     <a class="hbtn gold" href="/area">{{ __('La mia area') }}</a>
     <a class="hbtn line" href="/negozio">{{ __('Vai al negozio') }}</a>
    @else
     <a class="hbtn gold" href="/registrazione">{{ __('Registrati') }}</a>
     <a class="hbtn line" href="/accedi">{{ __('Accedi') }}</a>
    @endif
   </div>
   @unless($logged)<p class="hero-note">{!! __('Registrazione gratuita, nessun obbligo di acquisto. Vuoi solo dare un’occhiata? <a href="/negozio">Guarda il negozio</a>.') !!}</p>@endunless
  </div>
 </div>
 <a class="scroll" href="#prodotto">{{ __('Scopri') }}</a>
</header>

<section id="prodotto">
 <div class="wrap">
  <div class="intro reveal">
   <div>
    <p class="kicker">{{ __('Il prodotto') }}</p>
    <h2>{{ __('LAMÈSS è un Cosmetic Coffee: un caffè pensato per andare oltre il gusto') }}</h2>
   </div>
   <p>{{ __('La sua formulazione nasce dall’incontro tra la qualità della miscela e ingredienti ispirati al mondo della cosmetica. L’acido ialuronico, noto per il suo ruolo nella cura della pelle, diventa parte di un’esperienza quotidiana che unisce piacere sensoriale e attenzione al corpo.') }}</p>
  </div>
  <div class="pillars reveal">
   <div class="pillar"><div class="n">01</div><h3>{{ __('Primo caffè cosmetico brevettato') }}</h3><p>{{ __('Formula esclusiva che garantisce la stabilità dei principi attivi.') }}</p></div>
   <div class="pillar"><div class="n">02</div><h3>{{ __('Acido ialuronico') }}</h3><p>{{ __('Entra nell’esperienza di ogni giorno, tra piacere sensoriale e cura di sé.') }}</p></div>
   <div class="pillar"><div class="n">03</div><h3>{{ __('Arabica 100% AAA') }}</h3><p>{{ __('Miscela di tripla classe selezionata dai migliori produttori colombiani.') }}</p></div>
   <div class="pillar"><div class="n">04</div><h3>{{ __('Zero zuccheri') }}</h3><p>{{ __('Gusto autentico senza alterazioni, adatto a stili di vita salutari.') }}</p></div>
  </div>
 </div>
</section>

@if($featured->isNotEmpty())
<section class="shelf">
 <div class="wrap reveal">
  <div class="shelf-head">
   <div><p class="kicker">{{ __('Il negozio') }}</p><h2>{{ __('Scegli il tuo rituale') }}</h2></div>
   <a class="more" href="/negozio">{{ __('Tutti i prodotti →') }}</a>
  </div>
  <div class="pgrid">
   @foreach($featured as $product)@include('site.shop._card', ['product' => $product])@endforeach
  </div>
 </div>
</section>
@endif

<section class="quote" id="stop-time">
 <div class="wrap reveal">
  <p class="kicker">{{ __('La nostra filosofia') }}</p>
  <h2>{{ __('Stop Time') }}</h2>
  <p>{{ __('Stop Time è un invito a rallentare e a vivere il tempo con maggiore consapevolezza. È la scelta di fermarsi, anche solo per un momento, e trasformare un gesto abituale in un rituale di bellezza personale.') }}</p>
  <p style="margin-top:14px">{{ __('LAMÈSS è pensato per entrare nella quotidianità con discrezione: un rituale che si sceglie quando il tempo lo consente, senza urgenze né forzature.') }}</p>
  @unless($logged)<a class="hbtn line" href="/registrazione">{{ __('Entra in LAMÈSS') }}</a>@endunless
 </div>
</section>

<section class="steps-sec">
 <div class="wrap reveal">
  <p class="kicker">{{ __('Come funziona') }}</p>
  <h2>{{ __('Dal primo caffè al tuo spazio personale, in due passi') }}</h2>
  <div class="how-steps">
   <div class="how-step"><div class="n">01</div><h3>{{ __('Registrati') }}</h3><p>{{ __('Crei il tuo spazio in pochi minuti. È gratuito e non c’è nessun obbligo di acquisto.') }}</p></div>
   <div class="how-step"><div class="n">02</div><h3>{{ __('Vivi il rituale') }}</h3><p>{{ __('Segui ordini, spedizioni e abbonamenti dalla tua area personale, con assistenza diretta.') }}</p></div>
  </div>
 </div>
</section>

<section class="ambassador" id="ambassador">
 <div class="wrap">
  <div class="amb reveal">
   <div>
    <p class="kicker">{{ __('Oltre il prodotto, un’appartenenza') }}</p>
    <h2>{{ __('Che cos’è un incaricato LAMÈSS?') }}</h2>
    <p>{{ __('È appartenenza: è chi sceglie di farsi interprete della filosofia Stop Time nella propria quotidianità, rappresentando l’unione tra scienza e piacere sensoriale.') }}</p>
    <p>{{ __('Un percorso di partecipazione consapevole, pensato per chi desidera contribuire alla diffusione di una visione di benessere unica al mondo.') }}</p>
    @unless($logged)<a class="hbtn dark" href="/registrazione">{{ __('Scopri il percorso da incaricato') }}</a>@else<a class="hbtn dark" href="/area/diventa-incaricato">{{ __('Scopri il percorso da incaricato') }}</a>@endunless
   </div>
   <div class="amb-card">
    <h3>{{ __('Un progetto in continua evoluzione') }}</h3>
    <ul>
     <li>{{ __('Costruito attorno a una visione condivisa del tempo, del gesto e della cura di sé.') }}</li>
     <li>{{ __('Ogni relazione nasce dall’ascolto e dalla volontà di comprendere.') }}</li>
     <li>{{ __('Si parte da una registrazione gratuita: il resto, quando e se lo desideri.') }}</li>
    </ul>
   </div>
  </div>
 </div>
</section>

<section class="final">
 <div class="wrap reveal">
  <h2>{{ __('Pronto a fermare il tempo?') }}</h2>
  <p>{{ __('Accedi al tuo spazio o creane uno nuovo: ordini, abbonamenti e assistenza in un unico posto.') }}</p>
  <div class="hero-cta">
   @if($logged)
    <a class="hbtn gold" href="/area">{{ __('La mia area') }}</a><a class="hbtn line" href="/negozio">{{ __('Vai al negozio') }}</a>
   @else
    <a class="hbtn gold" href="/registrazione">{{ __('Registrati') }}</a><a class="hbtn line" href="/accedi">{{ __('Accedi') }}</a>
   @endif
  </div>
  <p class="contact">{{ __('Vuoi saperne di più o parlare con il team?') }} <a href="mailto:{{ $co['email'] }}">{{ __('Scrivi a LAMÈSS') }}</a></p>
 </div>
</section>
@endsection
