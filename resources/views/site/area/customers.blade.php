@extends('site.layout')
@section('title','I miei clienti — Lamèss')
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $buyers = $rows->filter(fn ($r) => ($orders[$r->user_id]->n ?? 0) > 0)->count();
    $spent = collect($orders)->sum(fn ($o) => (float) $o->spent);
    $c = auth()->user()->consultant;
@endphp
<div class="pg-head">
 <div><h1>I miei clienti</h1><p class="sub">Le persone iscritte con il tuo link o assegnate a te. Vedi solo i recapiti, per aiutarle con i loro ordini.</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/referral">Condividi il link</a></div>
</div>
@if($rows->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'users'])</span><div><strong>Nessun cliente per ora</strong><p>Quando qualcuno si registra con il tuo link referral comparirà qui. Il tuo link: <a href="{{ url('/r/'.$c->referral_code) }}">{{ url('/r/'.$c->referral_code) }}</a></p><a class="btn accent" href="/area/referral">Apri link e QR code</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>Clienti</small><strong>{{ $rows->count() }}</strong><span>{{ $rows->where('registered_at', '>=', now()->subDays(30))->count() }} nuovi negli ultimi 30 giorni</span></div>
  <div class="kpi k-ok"><small>Hanno ordinato</small><strong>{{ $buyers }}</strong><span>{{ $rows->count() - $buyers }} non hanno ancora acquistato</span></div>
  <div class="kpi"><small>Venduto ai clienti</small><strong>€ {{ $eur($spent) }}</strong><span>Ordini pagati, IVA inclusa</span></div>
 </div>
 <section class="panel">
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Cliente</th><th>Contatti</th><th>Iscritto dal</th><th class="num">Ordini pagati</th><th>Ultimo ordine</th></tr></thead>
   <tbody>
   @foreach($rows as $r)
    @php($o = $orders[$r->user_id] ?? null)
    <tr>
     <td data-l="Cliente"><div class="who"><span class="avatar" style="width:36px;height:36px;font-size:13px">{{ \App\Support\Ui::initials($r->user?->profile?->fullName() ?: (string) $r->user?->name) }}</span><div style="text-align:left"><b>{{ $r->user?->profile?->fullName() ?: $r->user?->name }}</b>@if($r->user?->profile?->type === 'company')<span>azienda</span>@endif</div></div></td>
     <td data-l="Contatti"><a href="mailto:{{ $r->user?->email }}">{{ $r->user?->email }}</a>@if($r->user?->profile?->phone)<br><span class="muted">{{ $r->user->profile->phone }}</span>@endif</td>
     <td data-l="Iscritto dal">{{ \App\Support\Ui::date($r->registered_at) }}</td>
     <td data-l="Ordini pagati" class="num">@if($o)<strong>{{ $o->n }}</strong> · € {{ $eur($o->spent) }}@else<span class="st">nessuno</span>@endif</td>
     <td data-l="Ultimo ordine">{{ $o?->last_paid ? \App\Support\Ui::date(\Carbon\Carbon::parse($o->last_paid, 'UTC')) : '—' }}</td>
    </tr>
   @endforeach
   </tbody>
  </table></div>
 </section>
@endif
@endsection
