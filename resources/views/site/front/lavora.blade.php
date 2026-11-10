@extends('site.front.layout')
@section('title', __('Lavora con Lamèss'))
@section('description', __('Diventa incaricato Lamèss o apri un Lamèss Point: come funziona il percorso.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs">{!! __('<a href="/">Home</a> / Lavora con noi') !!}</p><h1>{{ __('Lavora con Lamèss.') }}</h1><p>{{ __('Tre modi per entrare in un mercato che si sta formando adesso.') }}</p></div></section>
<section class="light band">
  <div class="wrap">
    <div class="paths" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
      <article><h3>{{ __('Lamèss Point') }}</h3><p>{{ __('Centri estetici, attività per il benessere e strutture ricettive possono avere Lamèss nella propria offerta. Lo sviluppo è calibrato sulle singole città e può prevedere esclusività territoriale, definita negli accordi.') }}</p><a class="btn solid" href="/contatti">{{ __('Apri un Lamèss Point') }}</a></article>
      <article style="border-color:var(--line)"><h3>{{ __('Incaricato') }}</h3><p>{{ __('Affianchi Lamèss nella diffusione con un link referral personale, una pagina tua e un’area riservata. Ogni cliente che arriva dal tuo link entra nella tua struttura e lo segui dal tuo spazio.') }}</p><a class="btn solid" href="/contatti">{{ __('Diventa incaricato') }}</a></article>
      <article style="border-color:var(--line)"><h3>{{ __('Socio del Consorzio') }}</h3><p>{{ __('Chi vuole un coinvolgimento nel progetto può candidarsi come socio del Consorzio Kadeo Group, oggi 51 soci con esperienze diverse. Requisiti e modalità si spiegano in un incontro.') }}</p><a class="btn solid" href="/contatti">{{ __('Chiedi un incontro') }}</a></article>
    </div>
  </div>
</section>

<section class="dark band">
  <div class="wrap split" style="align-items:start">
    <div>
      <h2>{{ __('Come si diventa incaricato.') }}</h2>
      <p class="lead muted" style="margin-top:20px">{{ __('Un percorso in cinque passaggi, tutto online.') }}</p>
    </div>
    <ol class="steps">
      <li><div><b>{{ __('Ti registri dal link di chi ti ha invitato') }}</b><span>{{ __('Entri come cliente nella sua struttura.') }}</span></div></li>
      <li><div><b>{{ __('Acquisti la prima box') }}</b><span>{{ __('La prima box obbligatoria serve a conoscere il prodotto.') }}</span></div></li>
      <li><div><b>{{ __('Chiedi di diventare incaricato') }}</b><span>{{ __('Dal tuo profilo, con un clic.') }}</span></div></li>
      <li><div><b>{{ __('Firmi la Lettera di Incarico') }}</b><span>{{ __('Con un codice OTP ricevuto via email.') }}</span></div></li>
      <li><div><b>{{ __('Ricevi il tesserino e l’accesso all’area riservata') }}</b><span>{{ __('Il tesserino ha un QR code di verifica pubblica.') }}</span></div></li>
    </ol>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:44px;max-width:20ch">{{ __('Cosa trovi nella tua area riservata.') }}</h2>
    <div class="feat">
      <div><h3>{{ __('Qualifica e requisiti') }}</h3><p>{{ __('La tua qualifica attuale, cosa hai già raggiunto e cosa manca per la prossima.') }}</p></div>
      <div><h3>{{ __('Il mio ramo') }}</h3><p>{{ __('L’albero della tua rete, con elenco filtrabile, per capire chi aiutare a crescere.') }}</p></div>
      <div><h3>{{ __('Statini e Tasca') }}</h3><p>{{ __('Statino mensile e bonus settimanali, con i tuoi documenti e la richiesta di prelievo.') }}</p></div>
      <div><h3>{{ __('Pagina personale') }}</h3><p>{{ __('Una pagina pubblica tua, facile da modificare, con il tuo link referral personale.') }}</p></div>
      <div><h3>{{ __('Inviti') }}</h3><p>{{ __('L’elenco degli inviti spediti e se la persona si è già registrata dal tuo link.') }}</p></div>
      <div><h3>{{ __('Tesserino agente') }}</h3><p>{{ __('Il tesserino da stampare, con QR code di verifica, per la vendita porta a porta.') }}</p></div>
    </div>
    <p class="lead" style="margin-top:40px;max-width:60ch">{{ __('Compensi e condizioni si presentano in un incontro, insieme al Regolamento Incaricati.') }}</p>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>{{ __('Parliamone in un incontro.') }}</h2><a class="btn solid" href="/contatti">{{ __('Scrivici') }}</a></div></section>
@endsection
