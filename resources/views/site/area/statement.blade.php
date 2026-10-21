@extends('site.layout')
@section('title','Statino '.$s['period']->code.' — Lamèss')
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $paid = $s['payout']?->status === 'paid';
@endphp
<p class="crumb"><a href="/area/statini">← Tutti gli statini</a></p>
<div class="pg-head">
 <div><h1>Statino {{ $s['period']->code }}</h1><p class="sub">Il dettaglio di ogni provvigione maturata nel mese, con base di calcolo e motivo.</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/statini/{{ $s['period']->code }}/pdf">Scarica PDF</a></div>
</div>

<div class="kpis">
 <div class="kpi"><small>Totale del mese</small><strong>€ {{ $eur($s['total']) }}</strong><span>{{ count($s['sections']) }} {{ count($s['sections']) === 1 ? 'voce' : 'voci' }} di compenso</span></div>
 <div class="kpi"><small>Trattenuto</small><strong>€ {{ $eur($s['held']) }}</strong><span>Non erogato in questo mese</span></div>
 <div class="kpi"><small>Residuo precedente</small><strong>€ {{ $eur($s['carried']) }}</strong><span>Cumulato da mesi passati</span></div>
 <div class="kpi {{ $paid ? 'k-ok' : '' }}"><small>Pagamento</small><strong>{{ $paid ? 'Pagato' : ($s['payout'] ? 'In preparazione' : 'Non ancora') }}</strong><span>@if($paid)Il {{ \App\Support\Ui::date($s['payout']->paid_at) }}@elseif($s['payout'])€ {{ $eur($s['payout']->amount) }}@else Da € {{ $eur($s['min_payout']) }} con onboarding completo @endif</span></div>
</div>

<div class="split">
 <div class="stack">
  @forelse($s['sections'] as $g)
   <section class="panel">
    <header><h2>{{ $g['label'] }}</h2><strong>€ {{ $eur($g['accrued']) }}</strong></header>
    <div class="list">
     @foreach($g['lines'] as $l)
      <div class="item">
       <span class="main"><span class="t">{{ $l['source'] }}@if($l['level']) · livello {{ $l['level'] }}@endif @if($l['extended'] ?? false)<span class="st">esteso</span>@endif @if($l['held'])<span class="st st-warn">trattenuto</span>@endif</span><span class="m">Base € {{ $eur($l['base']) }}@if($l['pct']) × {{ $l['pct'] }}%@endif — {{ $l['why'] }}</span></span>
       <span class="end"><b>€ {{ $eur($l['amount']) }}</b></span>
      </div>
     @endforeach
    </div>
   </section>
  @empty
   <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'euro'])</span><div><strong>Nessuna provvigione in questo mese</strong><p>Le voci compaiono qui quando la tua rete e i tuoi acquisti maturano compensi.</p></div></div></section>
  @endforelse
 </div>

 <aside class="stack">
  <section class="panel">
   <header><h2>Stato del documento</h2></header>
   <div class="panel-body">
    <p style="margin:0">
    @if($doc)
     Pubblicato il {{ \App\Support\Ui::date($doc->published_at) }}.
     @if($doc->status === 'published')Puoi contestarlo con un reclamo circostanziato <strong>entro il {{ \App\Support\Ui::date($doc->dispute_deadline_at) }}</strong> ({{ $disputeDays }} giorni, Reg. 5.2); dopo si considera accettato.
     @elseif($doc->status === 'accepted')Termine per i reclami scaduto: l'estratto è considerato accettato.
     @elseif($doc->status === 'disputed')Reclamo aperto: <a href="/area/assistenza/{{ $doc->ticket_id }}">vedi la richiesta</a>.
     @else Reclamo deciso ({{ $doc->outcome === 'upheld' ? 'accolto' : 'respinto' }}): <a href="/area/assistenza/{{ $doc->ticket_id }}">vedi la risposta</a>.@endif
    @else
     Per contestazioni circostanziate hai 10 giorni dalla pubblicazione (Reg. 5.2).
    @endif
    </p>
    <p class="muted" style="margin:12px 0 0;font-size:13px">@if($paid)Pagato il {{ \App\Support\Ui::date($s['payout']->paid_at) }}.@elseif($s['payout'])Pagamento in preparazione per € {{ $eur($s['payout']->amount) }}.@elseif(\App\Domain\Wallet\WithdrawalService::enabled())Il totale del mese è accreditato nella <a href="/area/wallet">Tasca</a> alla chiusura del mese: lo ritiri con una richiesta di prelievo.@else Il pagamento avviene da € {{ $eur($s['min_payout']) }} con onboarding completo; sotto soglia gli importi si cumulano.@endif</p>
   </div>
  </section>
  @if($doc && $doc->canBeDisputed())
   <form method="post" action="/area/statini/{{ $s['period']->code }}/reclamo" class="panel accent">@csrf
    <header><h2>Contesta questo estratto</h2></header>
    <div class="panel-body">
     <p class="muted" style="margin:0 0 10px">Indica la voce contestata e il motivo (almeno {{ $minLength }} caratteri).</p>
     <textarea name="text" rows="5" aria-label="Motivo del reclamo">{{ old('text') }}</textarea>
     @foreach($errors->all() as $m)<p class="err">{{ $m }}</p>@endforeach
     <div class="form-actions"><button>Invia il reclamo</button></div>
    </div>
   </form>
  @endif
 </aside>
</div>
@endsection
