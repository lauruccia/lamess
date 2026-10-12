@extends('site.layout')
@section('title',$ticket->number.' — Lamèss')
@section('width','area')
@section('content')
@php
[$lab, $tone] = \App\Support\Ui::ticketStatus($ticket->status);
@endphp
<p class="crumb"><a href="/area/assistenza">← Tutte le richieste</a></p>
<div class="pg-head"><div><h1>{{ $ticket->subject }}</h1><p class="sub">{{ $ticket->number }} · {{ \App\Models\Ticket::CATEGORIES[$ticket->category] ?? $ticket->category }} · <span class="st st-{{ $tone }}">{{ $lab }}</span></p></div></div>
<div class="split">
 <div class="stack">
  @foreach($messages as $m)
   <section class="panel" style="{{ $m->is_staff ? 'border-color:var(--sand);background:#fffaf6' : '' }}">
    <header><div class="who"><span class="avatar" style="width:36px;height:36px;font-size:13px">{{ $m->is_staff ? 'LM' : \App\Support\Ui::initials(auth()->user()->name) }}</span><div><b>{{ $m->is_staff ? 'Consorzio Lamèss' : 'Tu' }}</b></div></div><span class="muted" style="font-size:13px">{{ \App\Support\Ui::date($m->created_at, 'd/m/Y H:i') }}</span></header>
    <div class="panel-body"><p style="white-space:pre-line;margin:0">{{ $m->body }}</p></div>
   </section>
  @endforeach
  @if($ticket->status !== 'closed')
   <form method="post" action="/area/assistenza/{{ $ticket->id }}/rispondi" class="panel">@csrf
    <header><h2>Rispondi</h2></header>
    <div class="panel-body">
     <textarea name="body" rows="4" aria-label="La tua risposta">{{ old('body') }}</textarea>
     @foreach($errors->all() as $m)<p class="err">{{ $m }}</p>@endforeach
     <div class="form-actions"><button>Invia</button></div>
    </div>
   </form>
  @else
   <div class="note note-info"><div>Richiesta chiusa il {{ \App\Support\Ui::date($ticket->closed_at) }}. Se ti serve ancora aiuto apri una nuova richiesta.</div><a class="btn" href="/area/assistenza">Nuova richiesta</a></div>
  @endif
 </div>
 <aside class="stack">
  <section class="panel"><header><h2>Dettagli</h2></header><div class="panel-body"><dl class="dl">
   <dt>Numero</dt><dd>{{ $ticket->number }}</dd>
   <dt>Categoria</dt><dd>{{ \App\Models\Ticket::CATEGORIES[$ticket->category] ?? $ticket->category }}</dd>
   <dt>Messaggi</dt><dd>{{ $messages->count() }}</dd>
   <dt>Ultimo messaggio</dt><dd>{{ \App\Support\Ui::date($ticket->last_message_at, 'd/m/Y H:i') }}</dd>
  </dl></div></section>
  @if($ticket->status !== 'closed' && $ticket->category !== 'statement_dispute')
   <section class="panel"><div class="panel-body"><strong>Problema risolto?</strong><p class="muted" style="margin:4px 0 12px">Puoi chiudere la richiesta: potrai sempre aprirne un'altra.</p>
    <form method="post" action="/area/assistenza/{{ $ticket->id }}/chiudi">@csrf<button class="btn ghost">Chiudi la richiesta</button></form></div></section>
  @endif
 </aside>
</div>
@endsection
