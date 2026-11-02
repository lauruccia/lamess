@extends('site.front.layout')
@section('title', 'Lavora con Lamèss')
@section('description', 'Diventa incaricato Lamèss o apri un Lamèss Point: come funziona il percorso.')
@section('content')
@verbatim
<section class="dark pagehead"><div class="wrap"><p class="crumbs"><a href="/">Home</a> / Lavora con noi</p><h1>Lavora con Lamèss.</h1><p>Tre modi per entrare in un mercato che si sta formando adesso.</p></div></section>
<section class="light band">
  <div class="wrap">
    <div class="paths" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
      <article><h3>Lamèss Point</h3><p>Centri estetici, attività per il benessere e strutture ricettive possono avere Lamèss nella propria offerta. Lo sviluppo è calibrato sulle singole città e può prevedere esclusività territoriale, definita negli accordi.</p><a class="btn solid" href="/contatti">Apri un Lamèss Point</a></article>
      <article style="border-color:var(--line)"><h3>Incaricato</h3><p>Affianchi Lamèss nella diffusione con un link referral personale, una pagina tua e un’area riservata. Ogni cliente che arriva dal tuo link entra nella tua struttura e lo segui dal tuo spazio.</p><a class="btn solid" href="/contatti">Diventa incaricato</a> <a class="btn" href="/come-funziona" style="margin-top:10px">Guarda come funziona</a></article>
      <article style="border-color:var(--line)"><h3>Socio del Consorzio</h3><p>Chi vuole un coinvolgimento nel progetto può candidarsi come socio del Consorzio Kadeo Group, oggi 51 soci con esperienze diverse. Requisiti e modalità si spiegano in un incontro.</p><a class="btn solid" href="/contatti">Chiedi un incontro</a></article>
    </div>
  </div>
</section>

<section class="dark band">
  <div class="wrap split" style="align-items:start">
    <div>
      <h2>Come si diventa incaricato.</h2>
      <p class="lead muted" style="margin-top:20px">Un percorso in cinque passaggi, tutto online.</p>
    </div>
    <ol class="steps">
      <li><div><b>Ti registri dal link di chi ti ha invitato</b><span>Entri come cliente nella sua struttura.</span></div></li>
      <li><div><b>Acquisti la prima box</b><span>La prima box obbligatoria serve a conoscere il prodotto.</span></div></li>
      <li><div><b>Chiedi di diventare incaricato</b><span>Dal tuo profilo, con un clic.</span></div></li>
      <li><div><b>Firmi la Lettera di Incarico</b><span>Con un codice OTP ricevuto via email.</span></div></li>
      <li><div><b>Ricevi il tesserino e l’accesso all’area riservata</b><span>Il tesserino ha un QR code di verifica pubblica.</span></div></li>
    </ol>
  </div>
</section>

<section class="white band">
  <div class="wrap">
    <h2 style="margin-bottom:44px;max-width:20ch">Cosa trovi nella tua area riservata.</h2>
    <div class="feat">
      <div><h3>Qualifica e requisiti</h3><p>La tua qualifica attuale, cosa hai già raggiunto e cosa manca per la prossima.</p></div>
      <div><h3>Il mio ramo</h3><p>L’albero della tua rete, con elenco filtrabile, per capire chi aiutare a crescere.</p></div>
      <div><h3>Statini e Tasca</h3><p>Statino mensile e bonus settimanali, con i tuoi documenti e la richiesta di prelievo.</p></div>
      <div><h3>Pagina personale</h3><p>Una pagina pubblica tua, facile da modificare, con il tuo link referral personale.</p></div>
      <div><h3>Inviti</h3><p>L’elenco degli inviti spediti e se la persona si è già registrata dal tuo link.</p></div>
      <div><h3>Tesserino agente</h3><p>Il tesserino da stampare, con QR code di verifica, per la vendita porta a porta.</p></div>
    </div>
    <p class="lead" style="margin-top:40px;max-width:60ch">Compensi e condizioni si presentano in un incontro, insieme al Regolamento Incaricati. Intanto puoi vedere <a href="/come-funziona" style="text-decoration:underline">come funziona, in due minuti</a>.</p>
  </div>
</section>
<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band"><h2>Parliamone in un incontro.</h2><a class="btn solid" href="/contatti">Scrivici</a></div></section>
@endverbatim
@endsection
