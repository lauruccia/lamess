@extends('site.layout')
@section('title','Statino bonus '.$s['week'].' — Lamèss')
@section('width','area')
@section('content')
@php($eur = fn ($v) => \App\Support\Money::eur($v))
<p class="crumb"><a href="/area/statini-bonus">← Tutti gli statini bonus</a></p>
<div class="pg-head">
 <div><h1>Statino bonus {{ $s['week'] }}</h1><p class="sub">Dal {{ $s['from']->format('d/m/Y') }} al {{ $s['to']->format('d/m/Y') }}</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/statini-bonus/{{ $s['week'] }}/pdf">Scarica PDF</a></div>
</div>
<div class="kpis"><div class="kpi"><small>Totale della settimana</small><strong>€ {{ $eur($s['total']) }}</strong><span>{{ count($s['lines']) }} {{ count($s['lines']) === 1 ? 'bonus' : 'bonus' }} di struttura</span></div></div>
<section class="panel">
 <header><h2>Bonus di Struttura</h2><strong>€ {{ $eur($s['total']) }}</strong></header>
 @if($s['lines'] === [])
  <div class="empty"><div><strong>Nessun bonus in questa settimana</strong></div></div>
 @else
  <div class="list">
   @foreach($s['lines'] as $l)
    <div class="item">
     <span class="main"><span class="t">{{ $l['source'] }} <span class="st st-ok">{{ $l['state'] }}</span></span><span class="m">@if($l['reached_at'])3° cliente il {{ \Carbon\Carbon::parse($l['reached_at'])->format('d/m/Y') }} — @endif{{ $l['why'] }}</span></span>
     <span class="end"><b>€ {{ $eur($l['amount']) }}</b></span>
    </div>
   @endforeach
  </div>
 @endif
</section>
@endsection
