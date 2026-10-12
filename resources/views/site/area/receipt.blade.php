@extends('site.layout')
@section('title','Ricevuta '.$r->number.' — Lamèss')
@section('width','area')
@section('content')
@php($eur = fn ($v) => number_format((float) $v, 2, ',', '.'))
<p class="crumb"><a href="/area/ricevute">← Tutte le ricevute</a></p>
<div class="pg-head"><div><h1>Ricevuta {{ $r->number }}</h1><p class="sub">Mese {{ $r->payout->period->code }} · emessa il {{ \App\Support\Ui::date($r->issued_at) }}</p></div><div class="pg-actions"><a class="btn accent" href="/area/ricevute/{{ $r->id }}/pdf">Scarica PDF</a></div></div>
<div class="kpis">
 <div class="kpi"><small>Provvigioni lorde</small><strong>€ {{ $eur($r->gross) }}</strong><span>Mese {{ $r->payout->period->code }}</span></div>
 <div class="kpi k-ok"><small>Netto pagato</small><strong>€ {{ $eur($r->net) }}</strong><span>Dopo trattenute e commissioni</span></div>
</div>
<div class="split">
 <section class="panel">
  <header><h2>Dettaglio</h2></header>
  <div class="panel-body">
   <dl class="dl">
    <dt>Provvigioni lorde</dt><dd>€ {{ $eur($r->gross) }}</dd>
    @if($r->regime !== 'vat_holder')
     @if((float) $r->withholding != 0)<dt>Ritenuta d'acconto</dt><dd>− € {{ $eur($r->withholding) }}</dd>@endif
     @if((float) $r->inps_worker != 0)<dt>Contributo INPS (tua quota)</dt><dd>− € {{ $eur($r->inps_worker) }}</dd>@endif
    @endif
    @if((float) $r->fee != 0)<dt>Commissione bonifico</dt><dd>− € {{ $eur($r->fee) }}</dd>@endif
    <dt><strong>Netto pagato</strong></dt><dd style="font-size:18px"><strong>€ {{ $eur($r->net) }}</strong></dd>
   </dl>
   @if($r->regime === 'vat_holder')<p class="muted" style="margin:14px 0 0">Hai la partita IVA: nessuna trattenuta. Il compenso va fatturato con la tua partita IVA.</p>@endif
  </div>
 </section>
 <aside class="stack">
  <section class="panel"><header><h2>Segnalazioni</h2></header><div class="panel-body"><p style="margin:0">
   @if($r->status === 'published')Puoi segnalare anomalie <strong>entro il {{ \App\Support\Ui::date($r->dispute_deadline_at) }}</strong> ({{ $disputeDays }} giorni); dopo si considera accettata.
   @elseif($r->status === 'accepted')Termine scaduto: la ricevuta è considerata accettata.
   @else Segnalazione {{ $r->status === 'disputed' ? 'aperta' : 'decisa' }}: <a href="/area/assistenza/{{ $r->ticket_id }}">vedi la richiesta</a>.@endif</p></div></section>
  @if($r->canBeDisputed())
   <form method="post" action="/area/ricevute/{{ $r->id }}/reclamo" class="panel accent">@csrf
    <header><h2>Segnala un'anomalia</h2></header>
    <div class="panel-body">
     <p class="muted" style="margin:0 0 10px">Indica che cosa non torna (almeno {{ $minLength }} caratteri).</p>
     <textarea name="text" rows="5" aria-label="Che cosa non torna">{{ old('text') }}</textarea>
     @foreach($errors->all() as $m)<p class="err">{{ $m }}</p>@endforeach
     <div class="form-actions"><button>Invia la segnalazione</button></div>
    </div>
   </form>
  @endif
 </aside>
</div>
@endsection
