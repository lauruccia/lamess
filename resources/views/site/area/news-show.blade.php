@extends('site.layout')
@section('title', $post->title.' — Lamèss')
@section('width','area')
@section('content')
<p class="crumb"><a href="/area/novita">← Tutte le novità</a></p>
<div class="split">
 <article class="panel"><div class="panel-body" style="padding:26px 28px">
  <span class="st st-info">{{ \App\Support\Ui::date($post->published_at) }}</span>
  <h1 style="margin:12px 0 16px;font-size:clamp(26px,3vw,36px)">{{ $post->title }}</h1>
  <div style="white-space:pre-line;font-size:16.5px;line-height:1.7;max-width:72ch">{{ $post->body }}</div>
 </div></article>
 <aside class="stack">
  @php($more = \App\Models\NewsPost::live()->whereIn('audience', \App\Domain\Content\Audience::visibleTo(auth()->user()))->where('id', '!=', $post->id)->orderByDesc('published_at')->limit(4)->get())
  @if($more->isNotEmpty())
   <section class="panel"><header><h2>Altre novità</h2></header>
    <div class="list">@foreach($more as $m)<a class="item" href="/area/novita/{{ $m->slug }}"><span class="main"><span class="t">{{ $m->title }}</span><span class="m">{{ \App\Support\Ui::date($m->published_at) }}</span></span></a>@endforeach</div>
   </section>
  @endif
  <section class="panel"><div class="panel-body"><strong>Domande su questa comunicazione?</strong><p class="muted" style="margin:4px 0 12px">Scrivici dall'area assistenza.</p><a class="btn ghost" href="/area/assistenza">Apri una richiesta</a></div></section>
 </aside>
</div>
@endsection
