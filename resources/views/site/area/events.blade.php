@extends('site.layout')
@section('title','Eventi — Lamèss')
@section('width','area')
@section('content')
@php
    $mineCount = $events->filter(fn ($e) => $svc->mine($e, $user)?->status === 'registered')->count();
@endphp
<div class="pg-head"><div><h1>Eventi e corsi</h1><p class="sub">Webinar, corsi ed eventi del Consorzio. Iscriviti in un click: se i posti sono esauriti entri in lista d'attesa.</p></div></div>
@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif

@if($events->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'calendar'])</span><div><strong>Nessun appuntamento in programma</strong><p>Quando il Consorzio pubblica un evento lo trovi qui e nella tua panoramica.</p></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>In programma</small><strong>{{ $events->where('status', 'published')->count() }}</strong><span>Prossimo: {{ \App\Support\Ui::date($events->where('status', 'published')->first()?->starts_at, 'd/m') }}</span></div>
  <div class="kpi k-ok"><small>Le mie iscrizioni</small><strong>{{ $mineCount }}</strong><span>{{ $mineCount ? 'Ti aspettiamo!' : 'Non sei ancora iscritto' }}</span></div>
 </div>
 <div class="split even">
 @foreach($events as $e)
  @php
      $mine = $svc->mine($e, $user);
      [$day, $mon] = \App\Support\Ui::chip($e->starts_at);
      $full = $e->capacity && $e->registeredCount() >= $e->capacity;
  @endphp
  <section class="panel">
   <div class="panel-body">
    <div style="display:flex;gap:14px;align-items:flex-start">
     <span class="chip-date"><b>{{ $day }}</b><small>{{ $mon }}</small></span>
     <div style="min-width:0;flex:1">
      <h3 style="margin:0 0 4px;font-size:20px">{{ $e->title }}</h3>
      <div class="pills" style="margin-bottom:8px"><span class="st st-info">{{ \App\Models\CorporateEvent::KINDS[$e->kind] ?? $e->kind }}</span>@if($e->status === 'cancelled')<span class="st st-err">annullato</span>@endif @if($mine)<span class="st st-{{ $mine->status === 'waitlist' ? 'warn' : 'ok' }}">{{ $mine->status === 'waitlist' ? 'in lista d\'attesa' : 'iscritto' }}</span>@endif</div>
      <p class="muted" style="margin:0 0 8px">{{ \App\Support\Ui::date($e->starts_at, 'l d/m/Y') }} · {{ \App\Support\Ui::date($e->starts_at, 'H:i') }}@if($e->ends_at)–{{ \App\Support\Ui::date($e->ends_at, 'H:i') }}@endif @if($e->location) · {{ $e->location }}@endif</p>
      @if($e->description)<p style="margin:0 0 10px">{{ $e->description }}</p>@endif
      @if($e->online_url && $mine?->status === 'registered')<p style="margin:0 0 10px">Collegamento: <a href="{{ $e->online_url }}">{{ $e->online_url }}</a></p>@endif
      @if($e->capacity)
       <div class="meter {{ $full ? '' : 'ok' }}" role="progressbar" aria-valuenow="{{ min(100, (int) round($e->registeredCount() / $e->capacity * 100)) }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ min(100, (int) round($e->registeredCount() / $e->capacity * 100)) }}%"></i></div>
       <p class="muted" style="margin:5px 0 0;font-size:13px">Posti: {{ $e->registeredCount() }} / {{ $e->capacity }}@if($full) · esauriti @endif</p>
      @endif
      <div class="form-actions">
       @if($mine)
        <form method="post" action="/area/eventi/{{ $e->id }}/annulla">@csrf<button class="btn ghost">Annulla iscrizione</button></form>
       @elseif($svc->isOpen($e))
        <form method="post" action="/area/eventi/{{ $e->id }}/iscrizione">@csrf<button class="btn accent">{{ $full ? 'Mettiti in lista d\'attesa' : 'Iscriviti' }}</button></form>
       @endif
      </div>
     </div>
    </div>
   </div>
  </section>
 @endforeach
 </div>
@endif
@endsection
