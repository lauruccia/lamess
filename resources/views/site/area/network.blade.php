@extends('site.layout')
@section('title','Il mio ramo — Lamèss')
@section('width','area')
@section('content')
@php
    $n = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $k = $progress['kosmos'] ?? null;
    $colReq = collect($k['requirements'] ?? [])->firstWhere('key', 'columns');
    $target = (int) ($colReq['target'] ?? 0);
@endphp
<div class="pg-head">
 <div><h1>Il mio ramo</h1><p class="sub">La tua rete, in sola lettura. @if($period)I numeri sono quelli dell'ultimo calcolo, mese {{ $period }}.@endif</p></div>
 <div class="pg-actions"><a class="btn accent" href="/area/referral">Invita qualcuno</a></div>
</div>

<div class="kpis">
 <div class="kpi"><small>Persone nel ramo</small><strong>{{ $total['persons'] }}</strong><span>{{ $direct }} {{ $direct === 1 ? 'diretta' : 'dirette' }} · {{ $depth }} {{ $depth === 1 ? 'livello' : 'livelli' }}@if($total['new30'] > 0) · {{ $total['new30'] }} {{ $total['new30'] === 1 ? 'nuova' : 'nuove' }} in 30 giorni @endif</span></div>
 @if($kosmos)
  <div class="kpi"><small>I miei clienti</small><strong>{{ $mine ? $mine['clients'] : '—' }}</strong><span>{{ $mine ? 'Qualifica '.($mine['rank'] ?? '—') : 'Nessun calcolo ancora' }}@if($progress && ($k['known'] ?? false)) · diretto {{ $n($progress['rank']['direct_pct']) }}%@endif</span></div>
  <div class="kpi"><small>Clienti nel mio ramo</small><strong>{{ $total['clients'] }}</strong><span>Clienti personali di chi è sotto di te</span></div>
  <div class="kpi"><small>Pearl nel ramo</small><strong>{{ $total['pearl'] }}</strong><span>{{ $mine ? $mine['pearl_first'].' al tuo 1° livello' : 'Qualifica Pearl o superiore' }}</span></div>
  <div class="kpi"><small>Venduto del mese</small><strong>€ {{ $eur($total['eur'] + $mine_eur) }}</strong><span>Ai clienti, IVA inclusa · tuoi € {{ $eur($mine_eur) }}</span></div>
 @elseif(\App\Domain\Plan\LegacyPlan::visible())
  <div class="kpi"><small>PVF personali del mese</small><strong>{{ $mine ? $n($mine['pvf']) : '—' }}</strong><span>{{ $mine ? 'Gruppo '.$n($mine['group_pv']).' PV' : 'Nessun calcolo ancora' }}</span></div>
  <div class="kpi"><small>PR del mese</small><strong>{{ $mine ? $n($mine['pr']) : '—' }}</strong><span>{{ $mine ? 'Gruppo '.$n($mine['group_pr']).' PR' : 'Nessun calcolo ancora' }}</span></div>
 @endif
 @if(\App\Domain\Plan\ActiveStatus::applies())
  <div class="kpi {{ $mine && $mine['active'] ? 'k-ok' : '' }}"><small>Il mio stato</small><strong>{{ $mine ? ($mine['active'] ? 'Attivo' : 'Non attivo') : '—' }}</strong><span>{{ $mine && $mine['rank'] ? 'Qualifica '.$mine['rank'] : 'Qualifica in attesa di calcolo' }}</span></div>
 @endif
</div>

@if($kosmos)
 <div class="split">
  <div class="stack">
   @if($total['persons'] === 0)
    <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'tree'])</span><div><strong>Il tuo ramo è ancora vuoto</strong><p>Condividi il tuo link referral: chi si iscrive con quel link entra nella tua rete. <a href="{{ \App\Domain\Content\ReferralQr::url($me) }}">{{ \App\Domain\Content\ReferralQr::pretty($me) }}</a></p><a class="btn accent" href="/area/referral">Link e QR code</a></div></div></section>
   @else
    <section class="panel">
     <header><h2>Albero della rete</h2><span class="muted" style="font-size:13px">Tocca un nome per aprire o chiudere il ramo</span></header>
     <div class="panel-body">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
       <input id="tree-q" type="search" placeholder="Cerca per nome o codice" style="flex:1 1 200px;padding:8px 12px;border:1px solid var(--hair);border-radius:10px">
       <button type="button" class="btn ghost" id="tree-open" style="padding:7px 14px;font-size:13.5px">Apri tutto</button>
       <button type="button" class="btn ghost" id="tree-close" style="padding:7px 14px;font-size:13.5px">Chiudi tutto</button>
      </div>
      <ul class="tree" id="tree">@include('site.area._node', ['ids' => $children[$me->id] ?? []])</ul>
      <p class="muted" id="tree-none" style="display:none">Nessuna persona trovata.</p>
     </div>
    </section>

    <section class="panel">
     <header><h2>Le mie colonne</h2><span class="muted" style="font-size:13px">Una colonna è un tuo diretto con tutta la sua discendenza</span></header>
     <div class="tbl-wrap"><table class="tbl cards">
      <thead><tr><th>Colonna</th><th>Qualifica</th><th class="num">Persone</th><th class="num">Clienti</th>@if($target > 0)<th>Verso {{ $target }} clienti</th>@endif<th class="num">Venduto mese</th></tr></thead>
      <tbody>
      @foreach($columns as $col)
       <tr>
        <td data-l="Colonna"><b>{{ $col['name'] }}</b> <span class="muted">{{ $col['code'] }}</span></td>
        <td data-l="Qualifica">{{ $col['rank'] ?? '—' }}@if($col['best_rank'] && $col['best_rank'] !== $col['rank']) <span class="muted">· migliore {{ $col['best_rank'] }}</span>@endif</td>
        <td data-l="Persone" class="num">{{ $col['persons'] }}</td>
        <td data-l="Clienti" class="num"><strong>{{ $col['clients'] }}</strong></td>
        @if($target > 0)<td data-l="Verso {{ $target }}"><div class="mini-bar" role="progressbar" aria-valuenow="{{ min(100, (int) floor($col['clients'] / $target * 100)) }}" aria-valuemin="0" aria-valuemax="100"><i class="{{ $col['clients'] >= $target ? 'ok' : '' }}" style="width:{{ min(100, (int) floor($col['clients'] / $target * 100)) }}%"></i></div></td>@endif
        <td data-l="Venduto mese" class="num">{{ $col['eur'] > 0 ? '€ '.$eur($col['eur']) : '—' }}</td>
       </tr>
      @endforeach
      </tbody>
     </table></div>
    </section>

    <section class="panel">
     <header><h2>Livello per livello</h2><span class="muted" style="font-size:13px">Il livello 1 sono i tuoi diretti</span></header>
     <div class="tbl-wrap"><table class="tbl cards">
      <thead><tr><th>Livello</th><th class="num">Persone</th><th class="num">Con clienti</th><th class="num">Clienti</th><th class="num">Pearl o più</th><th class="num">Venduto mese</th><th class="num">Il tuo compenso</th></tr></thead>
      <tbody>
      @foreach($levels as $lv)
       @php($def = collect($k['levels'] ?? [])->firstWhere('level', $lv['level']))
       <tr>
        <td data-l="Livello"><strong>{{ $lv['level'] }}</strong></td>
        <td data-l="Persone" class="num">{{ $lv['persons'] }}</td>
        <td data-l="Con clienti" class="num">{{ $lv['with_clients'] }}</td>
        <td data-l="Clienti" class="num">{{ $lv['clients'] }}</td>
        <td data-l="Pearl o più" class="num">{{ $lv['pearl'] }}</td>
        <td data-l="Venduto mese" class="num">{{ $lv['eur'] > 0 ? '€ '.$eur($lv['eur']) : '—' }}</td>
        <td data-l="Il tuo compenso" class="num">@if($def)<span class="st st-{{ $def['unlocked'] ? 'ok' : 'warn' }}">{{ $n($def['pct']) }}% {{ $def['unlocked'] ? 'sbloccato' : 'bloccato' }}</span>@else<span class="muted">estesi (se qualificato)</span>@endif</td>
       </tr>
      @endforeach
      </tbody>
     </table></div>
    </section>
   @endif
  </div>
  <div class="stack">
   <section class="panel">
    <header><h2>Verso {{ $k['next_name'] ?? 'la qualifica massima' }}</h2></header>
    <div class="panel-body">@include('partials.career-progress', ['p' => $progress])</div>
   </section>
  </div>
 </div>
@else
 @if($total['persons'] === 0)
  <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'tree'])</span><div><strong>Il tuo ramo è ancora vuoto</strong><p>Condividi il tuo link referral: chi si iscrive con quel link entra nella tua rete. <a href="{{ \App\Domain\Content\ReferralQr::url($me) }}">{{ \App\Domain\Content\ReferralQr::pretty($me) }}</a></p><a class="btn accent" href="/area/referral">Link e QR code</a></div></div></section>
 @else
  <section class="panel">
   <header><h2>Albero della rete</h2><span class="muted" style="font-size:13px">Tocca un nome per aprire o chiudere il ramo</span></header>
   <div class="panel-body"><ul class="tree" id="tree">@include('site.area._node', ['ids' => $children[$me->id] ?? []])</ul></div>
  </section>
 @endif
@endif

<style>
 .mini-bar{height:8px;min-width:90px;border-radius:5px;background:rgba(127,127,127,.22);overflow:hidden}
 .mini-bar>i{display:block;height:100%;background:#C2663A}.mini-bar>i.ok{background:#2E9E5B}
 .tree .node{display:inline-flex;flex-wrap:wrap;gap:4px 8px;align-items:center}
</style>
<script>
 (function(){
  var q=document.getElementById('tree-q'); if(!q) return;
  var tree=document.getElementById('tree'), none=document.getElementById('tree-none');
  function all(open){tree.querySelectorAll('details').forEach(function(d){d.open=open;});}
  document.getElementById('tree-open').addEventListener('click',function(){all(true);});
  document.getElementById('tree-close').addEventListener('click',function(){all(false);});
  q.addEventListener('input',function(){
   var t=q.value.trim().toLowerCase(), items=tree.querySelectorAll('li'), shown=0;
   items.forEach(function(li){li.style.display='';});
   if(t===''){none.style.display='none';return;}
   items.forEach(function(li){li.style.display='none';});
   items.forEach(function(li){
    if((li.getAttribute('data-name')||'').indexOf(t)===-1) return;
    shown++; var p=li;
    while(p&&p!==tree){ if(p.tagName==='LI'){p.style.display='';} if(p.tagName==='DETAILS'){p.open=true;} p=p.parentElement; }
   });
   none.style.display=shown?'none':'';
  });
 })();
</script>
@endsection
