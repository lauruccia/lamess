@extends('site.layout')
@section('title','I miei ordini — Lamèss')
@section('width','area')
@section('content')
@php
    $orders->loadCount('items')->load('invoice');
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $paid = $orders->whereIn('status', \App\Models\Order::PAID_STATES);
    $pending = $orders->where('status', 'pending_payment');
    $last = $orders->first();
@endphp
<div class="pg-head">
 <div>
  <h1>I miei ordini</h1>
  <p class="sub">Stato, fatture e spedizioni di tutti i tuoi acquisti.</p>
 </div>
 <div class="pg-actions"><a class="btn accent" href="/negozio">Nuovo ordine</a></div>
</div>

@foreach($pending as $o)
 <div class="note"><div><strong>Ordine {{ $o->number }} in attesa di pagamento</strong>€ {{ $eur($o->total) }} · {{ $o->payment_method === 'transfer' ? 'serve il bonifico: nell\'ordine trovi IBAN e causale.' : ($o->payment_method === 'kmoney' ? 'completa il pagamento con KMoney.' : 'completa il pagamento con carta.') }}</div><a class="btn" href="/area/ordini/{{ $o->id }}">{{ $o->payment_method === 'transfer' ? 'Vedi istruzioni' : 'Paga ora' }}</a></div>
@endforeach

@if($orders->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'bag'])</span><div><strong>Non hai ancora ordini</strong><p>Quando acquisti dal negozio ritrovi qui lo stato di ogni ordine, la fattura e il codice di tracking.</p><a class="btn accent" href="/negozio">Vai al negozio</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>Ordini</small><strong>{{ $orders->count() }}</strong><span>{{ $paid->count() }} pagati</span></div>
  <div class="kpi"><small>Totale acquistato</small><strong>€ {{ $eur($paid->sum('total')) }}</strong><span>IVA inclusa, ordini pagati</span></div>
  <div class="kpi {{ $pending->isNotEmpty() ? 'k-warn' : '' }}"><small>Da pagare</small><strong>{{ $pending->count() }}</strong><span>{{ $pending->isNotEmpty() ? '€ '.$eur($pending->sum('total')).' in attesa' : 'Tutto in regola' }}</span></div>
  <div class="kpi"><small>Ultimo ordine</small><strong>{{ \App\Support\Ui::date($last->created_at, 'd/m/Y') }}</strong><span>{{ $last->number }}</span></div>
 </div>
 <section class="panel">
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Ordine</th><th>Data</th><th>Tipo</th><th class="num">Articoli</th><th class="num">Totale</th><th>Stato</th><th>Documento</th></tr></thead>
   <tbody>
   @foreach($orders as $o)
    @php
    [$lab, $tone] = \App\Support\Ui::orderStatus($o->status);
    @endphp
    <tr>
     <td data-l="Ordine"><a href="/area/ordini/{{ $o->id }}"><strong>{{ $o->number }}</strong></a></td>
     <td data-l="Data">{{ \App\Support\Ui::date($o->created_at) }}</td>
     <td data-l="Tipo"><span class="st st-{{ $o->subscription_id ? 'info' : 'mute' }}">{{ $o->subscription_id ? 'Abbonamento' : 'Singolo' }}</span></td>
     <td data-l="Articoli" class="num">{{ $o->items_count }}</td>
     <td data-l="Totale" class="num"><strong>€ {{ $eur($o->total) }}</strong></td>
     <td data-l="Stato"><span class="st st-{{ $tone }}">{{ $lab }}</span></td>
     <td data-l="Documento">@if($o->invoice)<a href="/area/ordini/{{ $o->id }}/fattura">{{ $o->invoice->type === 'receipt' ? 'Ricevuta' : 'Fattura' }} (PDF)</a>@else<span class="muted">—</span>@endif</td>
    </tr>
   @endforeach
   </tbody>
  </table></div>
 </section>
@endif
@endsection
