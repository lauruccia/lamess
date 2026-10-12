@extends('site.layout')
@section('title','Novità — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Novità</h1><p class="sub">Le comunicazioni del Consorzio: prodotti, regole, iniziative.</p></div></div>
@if($posts->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'news'])</span><div><strong>Nessuna novità per ora</strong><p>Quando il Consorzio pubblica una comunicazione la trovi qui e nella tua panoramica.</p></div></div></section>
@else
 @php
     $first = $posts->first();
 @endphp
 <div class="split">
  <div class="stack">
   <a class="panel accent" href="/area/novita/{{ $first->slug }}" style="display:block;text-decoration:none;color:inherit">
    <div class="panel-body" style="padding:22px">
     <span class="st st-info">{{ $first->pinned ? 'In evidenza' : 'Ultima novità' }}</span>
     <h2 style="margin:12px 0 6px;font-size:26px">{{ $first->title }}</h2>
     <p class="muted" style="margin:0 0 10px;font-size:13.5px">{{ \App\Support\Ui::date($first->published_at) }}</p>
     @if($first->excerpt)<p style="margin:0 0 12px">{{ $first->excerpt }}</p>@endif
     <span style="color:var(--lm-brown);font-weight:600">Leggi →</span>
    </div>
   </a>
  </div>
  <section class="panel">
   <header><h2>Tutte le novità</h2><span class="muted" style="font-size:13px">{{ $posts->count() }}</span></header>
   <div class="list">
    @foreach($posts as $p)
     @php
     [$day, $mon] = $p->published_at ? \App\Support\Ui::chip($p->published_at) : ['', ''];
     @endphp
     <a class="item" href="/area/novita/{{ $p->slug }}">
      <span class="chip-date"><b>{{ $day ?: '·' }}</b><small>{{ $mon }}</small></span>
      <span class="main"><span class="t">{{ $p->title }}</span>@if($p->excerpt)<span class="m">{{ \Illuminate\Support\Str::limit($p->excerpt, 90) }}</span>@endif</span>
      @if($p->pinned)<span class="end"><span class="st st-info">in evidenza</span></span>@endif
     </a>
    @endforeach
   </div>
  </section>
 </div>
@endif
@endsection
