@extends('site.layout')
@section('title','I miei clienti — Lamèss')
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $buyers = $all->filter(fn ($r) => ($orders[$r->user_id]->n ?? 0) > 0)->count();
    $spent = collect($orders)->sum(fn ($o) => (float) $o->spent);
    $c = auth()->user()->consultant;
    $valid = fn ($o) => $kosmos !== null && $o !== null && $kosmos->orderQualifies(\Brick\Math\BigDecimal::of((string) $o->biggest));
    $validCount = $kosmos === null ? 0 : $all->filter(fn ($r) => $valid($orders[$r->user_id] ?? null))->count();
    $kk = $progress['kosmos'] ?? null;
    $nt = $kk ? collect($kk['tiers'])->firstWhere('reached', false) : null;
@endphp
<div class="pg-head">
 <div><h1>I miei clienti</h1><p class="sub">Le persone iscritte con il tuo link o assegnate a te. Vedi solo i recapiti, per aiutarle con i loro ordini.</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/referral">Condividi il link</a></div>
</div>
@if($all->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'users'])</span><div><strong>Nessun cliente per ora</strong><p>Quando qualcuno si registra con il tuo link referral comparirà qui. Il tuo link: <a href="{{ \App\Domain\Content\ReferralQr::url($c) }}">{{ \App\Domain\Content\ReferralQr::pretty($c) }}</a></p><a class="btn accent" href="/area/referral">Apri link e QR code</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>Clienti</small><strong>{{ $all->count() }}</strong><span>{{ $all->where('registered_at', '>=', now()->subDays(30))->count() }} nuovi negli ultimi 30 giorni</span></div>
  <div class="kpi k-ok"><small>Hanno ordinato</small><strong>{{ $buyers }}</strong><span>{{ $all->count() - $buyers }} non hanno ancora acquistato</span></div>
  @if($kosmos !== null)
   <div class="kpi {{ $kk && $kk['known'] ? 'k-ok' : '' }}"><small>Clienti che contano per il piano</small><strong>{{ $kk && $kk['known'] ? $kk['clients'] : $validCount }}</strong><span>@if($nt)Ne mancano {{ $nt['missing'] }} per il {{ number_format($nt['pct'], 0, ',', '.') }}% di compenso diretto @elseif($kk && $kk['known'])Scaglione massimo raggiunto @else Cliente è {{ $kosmos->clientRule === \App\Domain\Engine\Plan\KosmosConfig::CLIENT_ANY_ORDER ? 'chi fa almeno un ordine' : 'chi fa un ordine da almeno € '.\App\Support\Money::eur($kosmos->clientMinAmount).' IVA inclusa' }} @endif</span></div>
  @endif
  <div class="kpi"><small>Venduto ai clienti</small><strong>€ {{ $eur($spent) }}</strong><span>Ordini pagati, IVA inclusa</span></div>
 </div>
 <section class="panel">
  <form method="get" action="/area/clienti" class="filters" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;padding:14px 16px 0">
   <div style="flex:1 1 220px"><label for="f-q">Cerca</label><input id="f-q" type="search" name="q" value="{{ $search }}" placeholder="Nome, email o telefono"></div>
   <div style="flex:0 1 240px"><label for="f-s">Mostra</label>
    <select id="f-s" name="stato"><option value="">Tutti i clienti</option>@foreach($filters as $key => $label)@continue($kosmos === null && in_array($key, ['contano', 'non-contano'], true))<option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>@endforeach</select></div>
   <div><button class="accent">Filtra</button>@if($search !== '' || $state !== '') <a class="btn" href="/area/clienti">Azzera</a>@endif</div>
  </form>
  @if($rows->isEmpty())<p class="muted" style="padding:14px 16px">Nessun cliente corrisponde ai filtri scelti ({{ $all->count() }} in totale).</p>@endif
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Cliente</th><th>Contatti</th><th>Iscritto dal</th><th class="num">Ordini pagati</th><th>Primo ordine</th><th>Ultimo ordine</th>@if($kosmos !== null)<th class="num">Ordine più alto</th><th>Conta per il piano</th>@endif</tr></thead>
   <tbody>
   @foreach($rows as $r)
    @php($o = $orders[$r->user_id] ?? null)
    <tr>
     <td data-l="Cliente"><div class="who"><span class="avatar" style="width:36px;height:36px;font-size:13px">{{ \App\Support\Ui::initials($r->user?->profile?->fullName() ?: (string) $r->user?->name) }}</span><div style="text-align:left"><b>{{ $r->user?->profile?->fullName() ?: $r->user?->name }}</b>@if($r->user?->profile?->type === 'company')<span>azienda</span>@endif</div></div></td>
     <td data-l="Contatti"><a href="mailto:{{ $r->user?->email }}">{{ $r->user?->email }}</a>@if($r->user?->profile?->phone)<br><span class="muted">{{ $r->user->profile->phone }}</span>@endif</td>
     <td data-l="Iscritto dal">{{ \App\Support\Ui::date($r->registered_at) }}</td>
     <td data-l="Ordini pagati" class="num">@if($o)<strong>{{ $o->n }}</strong> · € {{ $eur($o->spent) }}@else<span class="st">nessuno</span>@endif</td>
     <td data-l="Primo ordine">{{ $o?->first_paid ? \App\Support\Ui::date(\Carbon\Carbon::parse($o->first_paid, 'UTC')) : '—' }}</td>
     <td data-l="Ultimo ordine">{{ $o?->last_paid ? \App\Support\Ui::date(\Carbon\Carbon::parse($o->last_paid, 'UTC')) : '—' }}</td>
     @if($kosmos !== null)
      <td data-l="Ordine più alto" class="num">{{ $o ? '€ '.$eur($o->biggest) : '—' }}</td>
      <td data-l="Conta per il piano">@if($o === null)<span class="st">nessun ordine</span>@elseif($valid($o))<span class="st st-ok">sì</span>@else<span class="st st-warn">no, sotto € {{ $eur($kosmos->clientMinAmount) }}</span>@endif</td>
     @endif
    </tr>
   @endforeach
   </tbody>
  </table></div>
  @if($rows->lastPage() > 1)
  <p class="muted" style="padding:12px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
   @if($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">← Precedenti</a>@endif
   <span>Pagina {{ $rows->currentPage() }} di {{ $rows->lastPage() }} · {{ $rows->total() }} clienti</span>
   @if($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">Successivi →</a>@endif
  </p>
  @endif
 </section>
@endif
@endsection
