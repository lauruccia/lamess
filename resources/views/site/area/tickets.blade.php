@extends('site.layout')
@section('title','Assistenza — Lamèss')
@section('width','area')
@section('content')
@php
    $answered = $tickets->where('status', 'answered')->count();
    $open = $tickets->where('status', 'open')->count();
@endphp
<div class="pg-head"><div><h1>Assistenza</h1><p class="sub">Scrivi al Consorzio e segui qui le risposte. I reclami sugli estratti conto si aprono dalla pagina dell'estratto, entro i termini.</p></div></div>
@if($answered)<div class="note note-ok"><div><strong>{{ $answered === 1 ? 'Hai una risposta' : 'Hai '.$answered.' risposte' }}</strong>Apri la richiesta per leggerla.</div></div>@endif
<div class="split">
 <section class="panel">
  <header><h2>Le mie richieste</h2><span class="muted" style="font-size:13px">{{ $open }} in attesa · {{ $answered }} con risposta</span></header>
  @if($tickets->isEmpty())
   <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'chat'])</span><div><strong>Nessuna richiesta aperta</strong><p>Domande su ordini, fatture, abbonamenti o sul tuo account? Scrivici con il modulo: rispondiamo qui.</p></div></div>
  @else
   <div class="list">
    @foreach($tickets as $t)
     @php
     [$lab, $tone] = \App\Support\Ui::ticketStatus($t->status);
     @endphp
     <a class="item" href="/area/assistenza/{{ $t->id }}">
      <span class="ico">@include('site.area._i', ['n' => 'chat'])</span>
      <span class="main"><span class="t">{{ $t->subject }}</span><span class="m">{{ $t->number }} · {{ \App\Models\Ticket::CATEGORIES[$t->category] ?? $t->category }} · {{ \App\Support\Ui::date($t->last_message_at, 'd/m/Y H:i') }}</span></span>
      <span class="end"><span class="st st-{{ $tone }}">{{ $lab }}</span></span>
     </a>
    @endforeach
   </div>
  @endif
 </section>

 <form method="post" action="/area/assistenza" class="panel accent">@csrf
  <header><h2>Nuova richiesta</h2></header>
  <div class="panel-body">
   <label for="t-cat">Di cosa si tratta</label>
   <select id="t-cat" name="category">@foreach($categories as $k => $label)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select>
   <label for="t-sub">Oggetto</label><input id="t-sub" name="subject" value="{{ old('subject') }}" maxlength="190">
   <label for="t-body">Messaggio</label><textarea id="t-body" name="body" rows="5">{{ old('body') }}</textarea>
   @foreach($errors->all() as $m)<p class="err">{{ $m }}</p>@endforeach
   <div class="form-actions"><button>Invia richiesta</button></div>
  </div>
 </form>
</div>
@endsection
