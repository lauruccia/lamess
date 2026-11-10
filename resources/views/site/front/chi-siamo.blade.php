@extends('site.front.layout')
@section('title', __('Chi siamo'))
@section('description', __('Il progetto Lamèss e il Consorzio Kadeo Group.'))
@section('content')
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">{{ __('Home') }}</a> {{ __('/ Chi siamo') }}</p><h1>{{ __('Chi siamo.') }}</h1><p>{{ __('Un progetto nato da quasi quattro anni di ricerca, portato avanti da un consorzio di imprenditori.') }}</p></div></section>
<section class="light band">
  <div class="wrap split" style="align-items:start">
    <div>
      <h2>{{ __('La storia, finora.') }}</h2>
      <p style="margin-top:22px">{{ __('Lamèss nasce dall’incontro tra un caffè 100% Arabica di classe tripla AAA e il mondo della nutricosmetica. L’idea è rendere l’integrazione quotidiana un momento piacevole.') }}</p>
    </div>
    <ul class="tl">
      <li><b>{{ __('Quasi quattro anni di ricerca e sviluppo') }}</b><span>{{ __('Formulazione, scelta degli ingredienti, gusto.') }}</span></li>
      <li><b>{{ __('3 giugno 2026') }}</b><span>{{ __('Debutto ufficiale sul mercato.') }}</span></li>
      <li><b>{{ __('Ottobre 2026') }}</b><span>{{ __('Oltre 1.200 box distribuite, circa 600 iscritti, tre Lamèss Point, 51 soci del Consorzio.') }}</span></li>
    </ul>
  </div>
</section>


<section class="dark band">
  <div class="wrap press" style="color:var(--cream)">
    <div>
      <h2>{{ __('Il Consorzio.') }}</h2>
      <p style="margin-top:20px;color:var(--taupe)">{{ __('Il Consorzio Kadeo Group riunisce 51 soci con esperienze e competenze diverse, uniti da un obiettivo comune. Sede ad Arezzo.') }}</p>
      <ul class="list" style="border-color:var(--line-dark)">
        <li style="border-color:var(--line-dark)"><span>{{ __('The Lookmaker Institute') }}</span><span style="color:var(--sand)">{{ __('Sponsor ufficiale 2027') }}</span></li>
        <li style="border-color:var(--line-dark)"><span>{{ __('Farmadati') }}</span><span style="color:var(--sand)">{{ __('codice 991106055') }}</span></li>
      </ul>
    </div>
    <div>
      <h2>{{ __('Nel mondo.') }}</h2>
      <ul class="list" style="border-color:var(--line-dark)">
        <li style="border-color:var(--line-dark)"><span>{{ __('Barcellona') }}</span><span style="color:var(--sand)">{{ __('Torre MAPFRE, ufficio') }}</span></li>
        <li style="border-color:var(--line-dark)"><span>{{ __('Germania') }}</span><span style="color:var(--sand)">{{ __('collaborazione con una famiglia di imprenditori') }}</span></li>
        <li style="border-color:var(--line-dark)"><span>{{ __('Paesi Bassi') }}</span><span style="color:var(--sand)">{{ __('Nadia Group') }}</span></li>
        <li style="border-color:var(--line-dark)"><span>{{ __('Dubai') }}</span><span style="color:var(--sand)">{{ __('struttura in sviluppo') }}</span></li>
      </ul>
    </div>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>{{ __('Vuoi conoscerci?') }}</h2><a class="btn solid" href="/contatti">{{ __('Contattaci') }}</a></div></section>
@endsection
