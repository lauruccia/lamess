@extends('site.layout')
@section('title','Materiali — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Materiali</h1><p class="sub">I file portano il tuo nome e il tuo codice in filigrana: sono per uso personale e non vanno diffusi.</p></div></div>
@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if($items->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'download'])</span><div><strong>Nessun materiale disponibile</strong><p>Presentazioni, listini e documenti compaiono qui appena il Consorzio li pubblica.</p></div></div></section>
@else
 <div class="split even">
 @foreach($items as $cat => $rows)
  <section class="panel">
   <header><h2>{{ $cat }}</h2><span class="muted" style="font-size:13px">{{ $rows->count() }} {{ $rows->count() === 1 ? 'file' : 'file' }}</span></header>
   <div class="list">
    @foreach($rows as $row)
     <div class="item">
      <span class="ico">@include('site.area._i', ['n' => 'doc'])</span>
      <span class="main"><span class="t">{{ $row['d']->title }}</span><span class="m">@if($row['d']->description){{ $row['d']->description }} · @endif{{ $row['d']->original_name }} · {{ number_format($row['d']->size / 1024, 0, ',', '.') }} KB</span></span>
      <span class="end">@if($row['ok'])<a class="btn ghost" style="padding:8px 16px;font-size:14px;margin:0" href="/area/materiali/{{ $row['d']->id }}/scarica">Scarica</a>@else<span class="st st-warn">dalla qualifica {{ $row['d']->min_rank_code }}</span>@endif</span>
     </div>
    @endforeach
   </div>
  </section>
 @endforeach
 </div>
@endif
@endsection
