@extends('site.front.layout')
@section('title', 'Chi siamo')
@section('description', 'Il progetto Lamèss e il Consorzio Kadeo Group.')
@section('content')
@verbatim
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">Home</a> / Chi siamo</p><h1>Chi siamo.</h1><p>Un progetto nato da quasi quattro anni di ricerca, portato avanti da un consorzio di imprenditori.</p></div></section>
<section class="light band">
  <div class="wrap split" style="align-items:start">
    <div>
      <h2>La storia, finora.</h2>
      <p style="margin-top:22px">Lamèss nasce dall’incontro tra un caffè 100% Arabica di classe tripla AAA e il mondo della nutricosmetica. L’idea è rendere l’integrazione quotidiana un momento piacevole.</p>
    </div>
    <ul class="tl">
      <li><b>Quasi quattro anni di ricerca e sviluppo</b><span>Formulazione, scelta degli ingredienti, gusto.</span></li>
      <li><b>3 giugno 2026</b><span>Debutto ufficiale sul mercato.</span></li>
      <li><b>Ottobre 2026</b><span>Oltre 1.200 box distribuite, circa 600 iscritti, tre Lamèss Point, 51 soci del Consorzio.</span></li>
    </ul>
  </div>
</section>


<section class="dark band">
  <div class="wrap press" style="color:var(--cream)">
    <div>
      <h2>Il Consorzio.</h2>
      <p style="margin-top:20px;color:var(--taupe)">Il Consorzio Kadeo Group riunisce 51 soci con esperienze e competenze diverse, uniti da un obiettivo comune. Sede ad Arezzo.</p>
      <ul class="list" style="border-color:var(--line-dark)">
        <li style="border-color:var(--line-dark)"><span>The Lookmaker Institute</span><span style="color:var(--sand)">Sponsor ufficiale 2027</span></li>
        <li style="border-color:var(--line-dark)"><span>Farmadati</span><span style="color:var(--sand)">codice 991106055</span></li>
      </ul>
    </div>
    <div>
      <h2>Nel mondo.</h2>
      <ul class="list" style="border-color:var(--line-dark)">
        <li style="border-color:var(--line-dark)"><span>Barcellona</span><span style="color:var(--sand)">Torre MAPFRE, ufficio</span></li>
        <li style="border-color:var(--line-dark)"><span>Germania</span><span style="color:var(--sand)">collaborazione con una famiglia di imprenditori</span></li>
        <li style="border-color:var(--line-dark)"><span>Paesi Bassi</span><span style="color:var(--sand)">Nadia Group</span></li>
        <li style="border-color:var(--line-dark)"><span>Dubai</span><span style="color:var(--sand)">struttura in sviluppo</span></li>
      </ul>
    </div>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>Vuoi conoscerci?</h2><a class="btn solid" href="/contatti">Contattaci</a></div></section>
@endverbatim
@endsection
