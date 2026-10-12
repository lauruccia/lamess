@extends('site.layout')
@section('title','Ricevute — Lamèss')
@section('width','area')
@section('content')
@php($eur = fn ($v) => \App\Support\Money::eur($v))
<div class="pg-head"><div><h1>Le mie ricevute</h1><p class="sub">Per ogni pagamento il Consorzio emette la ricevuta per tuo conto. Hai 10 giorni dalla pubblicazione per segnalare anomalie.</p></div></div>
@if($rows->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'receipt'])</span><div><strong>Ancora nessuna ricevuta</strong><p>Compaiono dopo il primo pagamento delle provvigioni.</p></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>Ricevute</small><strong>{{ $rows->count() }}</strong><span>Ultima il {{ \App\Support\Ui::date($rows->first()->issued_at) }}</span></div>
  <div class="kpi"><small>Totale lordo</small><strong>€ {{ $eur($rows->sum(fn ($r) => (float) $r->gross)) }}</strong><span>Netto € {{ $eur($rows->sum(fn ($r) => (float) $r->net)) }}</span></div>
 </div>
 <section class="panel"><div class="tbl-wrap"><table class="tbl cards">
  <thead><tr><th>Numero</th><th>Data</th><th class="num">Lordo</th><th class="num">Netto</th><th>Segnalazione</th><th></th></tr></thead>
  <tbody>@foreach($rows as $r)
   <tr><td data-l="Numero"><a href="/area/ricevute/{{ $r->id }}"><strong>{{ $r->number }}</strong></a></td><td data-l="Data">{{ \App\Support\Ui::date($r->issued_at) }}</td>
    <td data-l="Lordo" class="num">€ {{ $eur($r->gross) }}</td><td data-l="Netto" class="num"><strong>€ {{ $eur($r->net) }}</strong></td>
    <td data-l="Segnalazione"><span class="st st-{{ $r->status === 'published' ? 'warn' : 'ok' }}">{{ ['published' => 'fino al '.$r->dispute_deadline_at->setTimezone('Europe/Rome')->format('d/m/Y'), 'disputed' => 'aperta', 'accepted' => 'accettata', 'resolved' => 'decisa'][$r->status] ?? $r->status }}</span></td>
    <td data-l="Documento"><a href="/area/ricevute/{{ $r->id }}/pdf">PDF</a></td></tr>
  @endforeach</tbody>
 </table></div></section>
@endif
@endsection
