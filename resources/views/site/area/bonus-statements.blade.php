@extends('site.layout')
@section('title','Statini bonus — Lamèss')
@section('width','area')
@section('content')
@php($eur = fn ($v) => \App\Support\Money::eur($v))
<p class="crumb"><a href="/area/statini">← Statini mensili</a></p>
<div class="pg-head"><div><h1>Statini bonus</h1><p class="sub">Un statino a settimana per i Bonus di Struttura confermati. Dirette e Indirette sono nello statino mensile.</p></div></div>
@if($weeks->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'euro'])</span><div><strong>Ancora nessun bonus</strong><p>Il Bonus di Struttura compare qui la settimana in cui viene confermato.</p></div></div></section>
@else
 <section class="panel">
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Settimana</th><th>Dal</th><th class="num">Bonus</th><th class="num">Totale</th><th></th></tr></thead>
   <tbody>
   @foreach($weeks as $w)
    <tr>
     <td data-l="Settimana"><a href="/area/statini-bonus/{{ $w['week'] }}"><strong>{{ $w['week'] }}</strong></a></td>
     <td data-l="Dal">{{ $w['from']->format('d/m/Y') }}</td>
     <td data-l="Bonus" class="num">{{ $w['count'] }}</td>
     <td data-l="Totale" class="num"><strong>€ {{ $eur($w['total']) }}</strong></td>
     <td data-l="Documento"><a href="/area/statini-bonus/{{ $w['week'] }}/pdf">PDF</a></td>
    </tr>
   @endforeach
   </tbody>
  </table></div>
 </section>
@endif
@endsection
