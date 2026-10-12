@extends('site.layout')
@section('title','Wallet — Lamèss')
@section('width','area')
@section('content')
@php($eur = fn ($v) => \App\Support\Money::eur($v))
<div class="pg-head"><div><h1>Il mio wallet</h1><p class="sub">Le provvigioni accreditate dopo la chiusura di ogni mese e le richieste di prelievo.</p></div></div>
@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif
@if((float) $s['blocked'] != 0)
 <div class="note"><div><strong>€ {{ $eur($s['blocked']) }} bloccati</strong>{{ implode(', ', $s['reasons']) }}</div><a class="btn" href="/area/onboarding">Completa i dati</a></div>
@elseif($s['reasons'])
 <div class="note note-info"><div><strong>Per prelevare</strong>{{ implode(', ', $s['reasons']) }}</div></div>
@endif
<div class="kpis">
 <div class="kpi"><small>Saldo</small><strong>€ {{ $eur($s['balance']) }}</strong><span>Totale accreditato</span></div>
 <div class="kpi k-ok"><small>Disponibile per il prelievo</small><strong>€ {{ $eur($s['available']) }}</strong><span>@if((float) $s['reserved'] != 0)€ {{ $eur($s['reserved']) }} già richiesti @else Pronto da richiedere @endif</span></div>
 <div class="kpi {{ (float) $s['blocked'] != 0 ? 'k-warn' : '' }}"><small>Bloccato</small><strong>€ {{ $eur($s['blocked']) }}</strong><span>{{ (float) $s['blocked'] != 0 ? 'Vedi l\'avviso sopra' : 'Nessun blocco' }}</span></div>
</div>
<div class="split">
 <div class="stack">
  <section class="panel">
   <header><h2>Le mie richieste</h2></header>
   @if($requests->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'wallet'])</span><div><strong>Nessuna richiesta</strong><p>Quando chiedi un prelievo lo segui qui, fino al bonifico.</p></div></div>
   @else
    <div class="list">
     @foreach($requests as $r)
      <div class="item">
       <span class="main"><span class="t">€ {{ $eur($r->gross) }} <span class="st st-{{ in_array($r->status, ['paid','approved'], true) ? 'ok' : ($r->status === 'rejected' ? 'err' : 'warn') }}">{{ \App\Support\StatusLabels::label($r->status) }}</span></span>
        <span class="m">Richiesta il {{ \App\Support\Ui::date($r->requested_at) }} · netto previsto € {{ $eur($r->net) }}@if((float) $r->withholding) (ritenuta € {{ $eur($r->withholding) }})@endif · pagamento entro il {{ $r->due_by->format('d/m/Y') }}@if($r->status === 'rejected') · motivo: {{ $r->rejection_reason }}@endif</span></span>
       @if($r->status === 'requested')<span class="end"><form method="post" action="/area/wallet/prelievo/{{ $r->id }}/annulla">@csrf<button class="btn ghost" style="padding:7px 14px;font-size:13.5px">Annulla</button></form></span>@endif
      </div>
     @endforeach
    </div>
   @endif
  </section>
  <section class="panel">
   <header><h2>Movimenti</h2></header>
   @if($transactions->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'euro'])</span><div><strong>Nessun movimento ancora</strong><p>Gli accrediti compaiono dopo la chiusura del mese.</p></div></div>
   @else
    <div class="tbl-wrap"><table class="tbl cards">
     <thead><tr><th>Data</th><th>Descrizione</th><th class="num">Importo</th><th class="num">Saldo</th></tr></thead>
     <tbody>@foreach($transactions as $t)
      <tr><td data-l="Data">{{ \App\Support\Ui::date($t->created_at) }}</td><td data-l="Descrizione">{{ $t->description }}</td>
       <td data-l="Importo" class="num"><strong style="color:var(--{{ (float) $t->amount < 0 ? 'err' : 'ok' }})">{{ (float) $t->amount < 0 ? '−' : '+' }} € {{ $eur(abs((float) $t->amount)) }}</strong></td><td data-l="Saldo" class="num">€ {{ $eur($t->balance_after) }}</td></tr>
     @endforeach</tbody>
    </table></div>
   @endif
  </section>
 </div>
 <aside class="stack">
  @if($enabled)
   <form method="post" action="/area/wallet/prelievo" class="panel accent">@csrf
    <header><h2>Chiedi un prelievo</h2></header>
    <div class="panel-body">
     <label for="w-amount">Importo lordo (€)</label><input id="w-amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="0,00">
     @error('amount')<p class="err">{{ $message }}</p>@enderror
     <p class="muted" style="margin:10px 0 0;font-size:13px">Minimo € 15,00. Dal prelievo si detraggono la commissione bonifico di € {{ $eur($fee) }} e le eventuali trattenute fiscali, indicate nella ricevuta. Il bonifico parte entro 5 giorni lavorativi dall'approvazione.</p>
     <div class="form-actions"><button>Invia la richiesta</button></div>
    </div>
   </form>
  @else
   <section class="panel"><div class="panel-body"><strong>Pagamento automatico</strong><p class="muted" style="margin:4px 0 0">I compensi vengono pagati in automatico ogni mese: non serve chiedere il prelievo.</p></div></section>
  @endif
  <p class="muted" style="font-size:12.5px;margin:0">I guadagni indicati non costituiscono garanzia di risultato.</p>
 </aside>
</div>
@endsection
