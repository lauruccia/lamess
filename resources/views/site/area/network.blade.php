@extends('site.layout')
@section('title','Il mio ramo — Lamèss')
@section('width','area')
@section('content')
@php($n = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.'))
<div class="pg-head">
 <div><h1>Il mio ramo</h1><p class="sub">La tua rete, in sola lettura. @if($period)I numeri sono quelli dell'ultimo calcolo, mese {{ $period }}.@endif</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/referral">Invita qualcuno</a></div>
</div>
<div class="kpis">
 <div class="kpi"><small>Persone nel ramo</small><strong>{{ $total }}</strong><span>{{ $direct }} {{ $direct === 1 ? 'diretta' : 'dirette' }} · {{ $levels }} {{ $levels === 1 ? 'livello' : 'livelli' }}</span></div>
 @if(\App\Domain\Plan\LegacyPlan::visible())
 <div class="kpi"><small>PVF personali del mese</small><strong>{{ $mine ? $n($mine['pvf']) : '—' }}</strong><span>{{ $mine ? 'Gruppo '.$n($mine['group_pv']).' PV' : 'Nessun calcolo ancora' }}</span></div>
 <div class="kpi"><small>PR del mese</small><strong>{{ $mine ? $n($mine['pr']) : '—' }}</strong><span>{{ $mine ? 'Gruppo '.$n($mine['group_pr']).' PR' : 'Nessun calcolo ancora' }}</span></div>
 @endif
 @if(\App\Domain\Plan\ActiveStatus::applies())
 <div class="kpi {{ $mine && $mine['active'] ? 'k-ok' : '' }}"><small>Il mio stato</small><strong>{{ $mine ? ($mine['active'] ? 'Attivo' : 'Non attivo') : '—' }}</strong><span>{{ $mine && $mine['rank'] ? 'Qualifica '.\App\Domain\Career\RankPalette::label($mine['rank']) : 'Qualifica in attesa di calcolo' }}</span></div>
 @endif
</div>

@if($total === 0)
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'tree'])</span><div><strong>Il tuo ramo è ancora vuoto</strong><p>Condividi il tuo link referral: chi si iscrive con quel link entra nella tua rete. <a href="{{ url('/r/'.$me->referral_code) }}">{{ url('/r/'.$me->referral_code) }}</a></p><a class="btn accent" href="/area/referral">Link e QR code</a></div></div></section>
@else
 <section class="panel">
  <header><h2>Albero della rete</h2><span class="muted" style="font-size:13px">Tocca un nome per aprire o chiudere il ramo</span></header>
  <div class="panel-body">
   @if(! empty($ranks))<p class="rank-legend" aria-label="Colori delle qualifiche">@foreach($ranks as $r)<span class="rank-chip" style="--stone: {{ $r['color'] }}">{{ \App\Domain\Career\RankPalette::label($r['code']) }}</span>@endforeach</p>@endif
   <ul class="tree">@include('site.area._node', ['ids' => $children[$me->id] ?? []])</ul></div>
 </section>
@endif
@endsection
