@extends('site.layout')
@section('title','Conformità — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Conformità</h1><p class="sub">Per presentare Lamèss usa solo materiali approvati e cita il codice. Se ti serve un testo o un'immagine nuova, proponila qui.</p></div></div>
@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if($sanctions->isNotEmpty())
 <div class="note"><div><strong>Provvedimenti in corso</strong>
  @foreach($sanctions as $s)<span style="display:block;margin-top:4px">{{ \App\Models\Sanction::TYPES[$s->type] ?? $s->type }} dal {{ \App\Support\Ui::date($s->starts_at) }}@if($s->ends_at) fino al {{ \App\Support\Ui::date($s->ends_at) }}@endif — <span class="muted">{{ $s->reason }}</span></span>@endforeach</div>
  <a class="btn" href="/area/assistenza">Chiedi chiarimenti</a></div>
@endif
<div class="split">
 <div class="stack">
  <section class="panel">
   <header><h2>Materiali approvati per tutta la rete</h2></header>
   @forelse($network as $m)
    <div class="item" style="display:block;border-top:1px solid var(--hair)">
     <span class="t">{{ $m->code }} · {{ $m->title }}@if($m->valid_until) <span class="st">valido fino al {{ $m->valid_until->format('d/m/Y') }}</span>@endif</span>
     @if($m->description)<p style="margin:4px 0 0">{{ $m->description }}</p>@endif
     @if($m->claims)<div style="white-space:pre-line;margin-top:6px;padding:10px 12px;background:var(--cream);border-radius:10px;font-size:14px">{{ $m->claims }}</div>@endif
     @if($m->url)<p style="margin:6px 0 0"><a href="{{ $m->url }}">{{ $m->url }}</a></p>@endif
    </div>
   @empty
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'doc'])</span><div><strong>Nessun materiale condiviso al momento</strong><p>Qui compaiono i testi approvati da usare con la rete.</p></div></div>
   @endforelse
  </section>
  <section class="panel">
   <header><h2>Le mie richieste</h2></header>
   @forelse($mine as $m)
    <div class="item">
     <span class="main"><span class="t">{{ $m->code ?: $m->title }} <span class="st st-{{ ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'err', 'withdrawn' => ''][$m->status] ?? '' }}">{{ ['pending' => 'in attesa', 'approved' => 'approvato', 'rejected' => 'non approvato', 'withdrawn' => 'ritirato'][$m->status] ?? $m->status }}</span></span>
      <span class="m">{{ $m->title }}@if($m->decision_note) · {{ $m->decision_note }}@endif</span></span>
     @if(in_array($m->status, ['pending', 'approved'], true))<span class="end"><form method="post" action="/area/conformita/materiali/{{ $m->id }}/ritira">@csrf<button class="btn ghost" style="padding:7px 14px;font-size:13.5px">Ritira</button></form></span>@endif
    </div>
   @empty
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'check'])</span><div><strong>Non hai ancora proposto materiali</strong><p>Usa il modulo a lato per far approvare un testo.</p></div></div>
   @endforelse
  </section>
 </div>
 <form method="post" action="/area/conformita/materiali" class="panel accent">@csrf
  <header><h2>Proponi un materiale</h2></header>
  <div class="panel-body">
   <label for="m-t">Titolo</label><input id="m-t" name="title" value="{{ old('title') }}" maxlength="190">
   <label for="m-d">A cosa serve</label><textarea id="m-d" name="description" rows="2">{{ old('description') }}</textarea>
   <label for="m-c">Testo o affermazioni da approvare</label><textarea id="m-c" name="claims" rows="5">{{ old('claims') }}</textarea>
   <label for="m-u">Link (facoltativo)</label><input id="m-u" name="url" value="{{ old('url') }}">
   <div class="form-actions"><button>Invia per approvazione</button></div>
  </div>
 </form>
</div>
@endsection
