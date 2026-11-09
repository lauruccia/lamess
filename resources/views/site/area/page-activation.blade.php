@extends('site.layout')
@section('title','La mia pagina — Lamèss')
@section('width','area')
@section('content')
@php
 $state = $svc->activationState($page);
 $price = number_format((float) $svc->price(), 2, ',', '.');
 $payUrl = (string) config('lamess.personal_pages.pay_url');
 $months = (int) config('lamess.personal_pages.validity_months', 12);
@endphp
<div class="pg-head">
 <div>
  <h1>La mia pagina</h1>
  <p class="sub">La tua pagina personale Lamèss: presenti il prodotto a chi vuoi, con la tua foto, i tuoi contatti e il tuo link per registrarsi nella tua struttura.</p>
 </div>
</div>

@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif
@if($state === 'expired')<div class="note note-err"><div><strong>La tua pagina è scaduta</strong>Dal {{ $page->activation_expires_at?->timezone('Europe/Rome')->format('d/m/Y') }} non è più visibile. Rinnovala per riattivarla: testi e foto che avevi scritto restano salvati.</div></div>@endif

<div class="split">
 <section class="panel accent">
  <header><h2>{{ $state === 'expired' ? 'Rinnova la tua pagina' : 'Attiva la tua pagina' }}</h2>
   @if($state === 'requested')<span class="st st-warn">Richiesta inviata</span>@endif
  </header>
  <div class="panel-body">
   <p style="margin-top:0">La pagina personale non è inclusa di default: si attiva su richiesta e vale <strong>{{ $months === 12 ? 'un anno' : $months.' mesi' }}</strong>, poi si rinnova. Il costo è di <strong>{{ $price }} €</strong>.</p>
   @if($state === 'requested')
    <p>Abbiamo ricevuto la tua richiesta. Appena il Consorzio verifica il pagamento la pagina viene attivata e ti arriva una email. Non devi fare altro.</p>
   @else
    <ol style="line-height:1.8;padding-left:20px">
     <li>Registrati su <a href="https://kmoney.it" target="_blank" rel="noopener">kmoney.it</a> (se non hai già un conto).</li>
     <li>Acquista il prodotto <a href="{{ $payUrl }}" target="_blank" rel="noopener"><strong>Pagina Lamèss</strong></a> ({{ $price }} €, un anno). Il prodotto è visibile solo dopo l'accesso a kmoney.it.</li>
     <li>Torna qui, scrivi con quale nome o email hai pagato su KMoney e premi «Ho pagato, richiedi l'attivazione».</li>
    </ol>
    <form method="post" action="/area/pagina/richiesta">@csrf
     <label for="kmoney_ref">Nome o email del tuo conto KMoney</label>
     <input id="kmoney_ref" name="kmoney_ref" maxlength="120" value="{{ old('kmoney_ref') }}" style="max-width:360px;margin-bottom:12px">
     <div><button class="btn accent">Ho pagato, richiedi l'attivazione</button></div>
    </form>
    <p class="muted" style="font-size:13.5px;margin-bottom:0">Dopo il pagamento il Consorzio attiva la pagina e ti avvisa con una email, di solito entro un giorno lavorativo. Se pensi di avere diritto all'attivazione gratuita, scrivi all'assistenza.</p>
   @endif
  </div>
 </section>
 <aside class="stack">
  <section class="panel">
   <header><h2>Cosa ottieni</h2></header>
   <div class="panel-body"><ul style="margin:0;padding-left:18px;line-height:1.7;font-size:14.5px">
    <li>Un indirizzo tutto tuo ({{ preg_replace('#^https?://#', '', url('/i')) }}/il-tuo-nome) da condividere.</li>
    <li>La tua foto, la tua presentazione, i tuoi contatti e i tuoi social.</li>
    <li>Descrizione del prodotto, domande frequenti e avvertenze già pronte, curate dal Consorzio.</li>
    <li>Chi si registra dalla tua pagina entra nella tua struttura.</li>
   </ul></div>
  </section>
 </aside>
</div>
@endsection
