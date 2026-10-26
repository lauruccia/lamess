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
  <div class="kpi {{ $waiting_total > 0 ? 'k-warn' : '' }}"><small>Clienti senza primo ordine</small><strong>{{ $waiting_total + ($my_customers['waiting'] ?? 0) }}</strong><span>Iscritti nel tuo ramo che non hanno ancora comprato: sono i primi da contattare</span></div>
  @if($earn ?? null)
   <a class="kpi" href="/area/statini"><small>Compenso {{ $earn['period'] }}{{ $earn['closed'] ? '' : ' · provvisorio' }}</small><strong>€ {{ $eur($earn['accrued']) }}</strong><span>@if($earn['closed'])Mese chiuso: importo definitivo @else Non definitivo: sul calcolo di adesso @if($earn['calculated_at'])({{ $earn['calculated_at']->setTimezone('Europe/Rome')->format('d/m H:i') }}) @endif· sale se arrivano nuovi clienti prima della chiusura @endif</span></a>
  @endif
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
    @php
     $pearlCode = $plan?->kosmos?->pearlRank;
     $allReq = array_merge($k['requirements'] ?? [], []);
     $needPearl = collect($allReq)->contains(fn ($r) => $r['key'] === 'pearl' && ! $r['ok'] && ! $r['disabled']);
    @endphp
    @include('site.area._tree')

    @php
     $rankIdx = collect($tree['ranks'] ?? [])->pluck('name')->flip();
     $actRanks = collect($act)->pluck('rank')->filter()->unique()->sortBy(fn ($r) => $rankIdx[$r] ?? 99)->values();
     $perLevel = collect($act)->countBy('level')->sortKeys();
    @endphp
    <section class="panel" id="act-panel">
     <header><h2>Le persone del mio ramo</h2><span class="muted" style="font-size:13px" id="act-count">{{ count($act) }} persone</span></header>
     <div class="panel-body act-filters">
      <input id="act-q" type="search" placeholder="Cerca per nome o codice" aria-label="Cerca per nome o codice">
      <div class="act-row" role="group" aria-label="Filtra per livello">
       <span class="act-lbl">Livello</span>
       <button type="button" class="chip on" data-lv="">Tutti ({{ count($act) }})</button>
       @foreach($perLevel as $lv => $cnt)<button type="button" class="chip" data-lv="{{ $lv }}">{{ $lv }}{{ $lv === 1 ? ' · diretti' : '' }} ({{ $cnt }})</button>@endforeach
      </div>
      <div class="act-row">
       <label class="act-lbl" for="act-rank">Qualifica</label>
       <select id="act-rank"><option value="">Tutte</option>@foreach($actRanks as $r)<option value="{{ mb_strtolower($r) }}">{{ $r }}</option>@endforeach</select>
       <label class="act-lbl" for="act-show">Mostra</label>
       <select id="act-show">
        <option value="">Tutti</option>
        <option value="clients">Con almeno un cliente</option>
        <option value="noclients">Senza clienti</option>
        <option value="waiting">Con iscritti senza primo ordine</option>
        <option value="ready">Requisiti per la qualifica soddisfatti</option>
        <option value="team">Con persone sotto di sé</option>
        <option value="inactive">Sospesi o cessati</option>
       </select>
       <button type="button" class="chip" id="act-reset">Azzera filtri</button>
      </div>
     </div>
     <div class="tbl-wrap"><table class="tbl cards" id="act-t">
      <thead><tr>
       @foreach(['name' => 'Persona', 'level' => 'Liv.', 'rank' => 'Qualifica', 'clients' => 'Clienti', 'registered' => 'Iscritti', 'persons' => 'Il suo ramo'] as $k => $lbl)
        <th @if(in_array($k, ['clients', 'persons'], true))class="num"@endif aria-sort="none"><button type="button" class="srt" data-k="{{ $k }}">{{ $lbl }}<i aria-hidden="true"></i></button></th>
       @endforeach
       <th>Prossima qualifica e cosa manca</th>
       <th class="num" aria-sort="none"><button type="button" class="srt" data-k="eur">Venduto mese<i aria-hidden="true"></i></button></th>
      </tr></thead>
      <tbody>
      @foreach($act as $i => $a)
       @php
        $ready = $a['next_name'] !== null && ! $a['missing'];
       @endphp
       <tr data-i="{{ $i }}" data-name="{{ mb_strtolower($a['name'].' '.$a['code']) }}" data-level="{{ $a['level'] }}" data-rank="{{ mb_strtolower((string) $a['rank']) }}" data-rankord="{{ $rankIdx[$a['rank']] ?? 99 }}"
           data-clients="{{ $a['clients'] }}" data-registered="{{ $a['registered'] }}" data-waiting="{{ $a['waiting'] }}" data-persons="{{ $a['persons'] }}" data-eur="{{ $a['eur'] }}" data-ready="{{ $ready ? 1 : 0 }}" data-inactive="{{ $a['status'] !== 'active' ? 1 : 0 }}">
        <td data-l="Persona"><b>{{ $a['name'] }}</b> <span class="muted">{{ $a['code'] }}</span>@if($a['status'] !== 'active')<br><span class="st st-warn">{{ $a['status'] === 'suspended' ? 'sospeso' : 'cessato' }}</span>@endif @if($needPearl && $a['direct'] && $a['next_code'] === $pearlCode && $a['units'] > 0)<br><span class="st st-ok" title="Ti serve un Pearl in più al 1° livello">ti serve Pearl</span>@endif</td>
        <td data-l="Livello" class="num">{{ $a['level'] }}@if($a['direct']) <span class="muted">diretto</span>@endif</td>
        <td data-l="Qualifica">{{ $a['rank'] ?? '—' }}</td>
        <td data-l="Clienti" class="num"><strong>{{ $a['clients'] }}</strong></td>
        <td data-l="Iscritti">@if($a['registered'] > 0){{ $a['registered'] }} <span class="muted">· {{ $a['valid'] }} validi</span>@if($a['waiting'] > 0)<br><span class="st st-warn">{{ $a['waiting'] }} senza ordine</span>@endif @if($a['last_order'])<br><span class="muted">ultimo ordine {{ \App\Support\Ui::date(\Carbon\Carbon::parse($a['last_order'], 'UTC'), 'd/m') }}</span>@endif @else<span class="muted">nessuno</span>@endif</td>
        <td data-l="Il suo ramo" class="num">@if($a['persons'] > 0){{ $a['persons'] }} {{ $a['persons'] === 1 ? 'persona' : 'persone' }}<br><span class="muted">{{ $a['sub_clients'] }} clienti</span>@else<span class="muted">—</span>@endif</td>
        <td data-l="Prossima">@if($a['next_name']){{ $a['next_name'] }}: @if($a['missing']){{ implode(' · ', $a['missing']) }}@else<span class="st st-ok">requisiti soddisfatti</span>@endif @else<span class="muted">qualifica massima</span>@endif</td>
        <td data-l="Venduto mese" class="num">{{ $a['eur'] > 0 ? '€ '.$eur($a['eur']) : '—' }}</td>
       </tr>
      @endforeach
      <tr id="act-none" hidden><td colspan="8" class="muted" style="text-align:center;padding:18px">Nessuna persona corrisponde ai filtri.</td></tr>
      </tbody>
     </table></div>
     <div class="panel-body muted" style="font-size:13px">Di base chi è più vicino alla prossima qualifica è in cima: clicca il titolo di una colonna per ordinare (di nuovo per invertire). «Validi» sono i clienti che hanno fatto un ordine che conta per il piano. Vedi solo numeri: nome e contatti dei clienti degli altri incaricati restano riservati.</div>
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
 <div class="split">
  <div class="stack">
   @if($total['persons'] === 0)
    <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'tree'])</span><div><strong>Il tuo ramo è ancora vuoto</strong><p>Condividi il tuo link referral: chi si iscrive con quel link entra nella tua rete. <a href="{{ \App\Domain\Content\ReferralQr::url($me) }}">{{ \App\Domain\Content\ReferralQr::pretty($me) }}</a></p><a class="btn accent" href="/area/referral">Link e QR code</a></div></div></section>
   @else
    @include('site.area._tree')
   @endif
  </div>
  <div class="stack">
   <section class="panel">
    <header><h2>Il mio avanzamento</h2></header>
    <div class="panel-body">@include('partials.career-progress', ['p' => $progress])</div>
   </section>
  </div>
 </div>
@endif

<style>
 #act-t tr[hidden]{display:none!important}
 .act-filters{display:flex;flex-direction:column;gap:10px;padding-bottom:10px}
 .act-filters #act-q{width:100%;padding:8px 12px;border:1px solid var(--hair);border-radius:10px}
 .act-row{display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center}
 .act-lbl{font-size:13px;color:var(--muted,#6a615a);margin:0 2px 0 0}
 .act-row select{padding:6px 10px;border:1px solid var(--hair);border-radius:10px;font:inherit;font-size:14px;margin-right:8px}
 .chip{font:inherit;font-size:13px;padding:4px 11px;border:1px solid var(--hair);border-radius:999px;background:transparent;color:inherit;cursor:pointer;margin:0;box-shadow:none}
 .chip.on{background:#C2663A;border-color:#C2663A;color:#fff}
 .srt{font:inherit;font-weight:inherit;color:inherit;background:none;border:0;padding:0;margin:0;cursor:pointer;box-shadow:none;text-transform:inherit;letter-spacing:inherit}
 .srt i{display:inline-block;width:.9em;font-style:normal;opacity:.55}
 th[aria-sort=ascending] .srt i:after{content:'▲'}th[aria-sort=descending] .srt i:after{content:'▼'}
 .mini-bar{height:8px;min-width:90px;border-radius:5px;background:rgba(127,127,127,.22);overflow:hidden}
 .mini-bar>i{display:block;height:100%;background:#C2663A}.mini-bar>i.ok{background:#2E9E5B}
</style>
<script>
 (function(){
  var tb=document.querySelector('#act-t tbody'); if(!tb){return;}
  var rows=[].slice.call(tb.querySelectorAll('tr[data-i]')), none=document.getElementById('act-none'), cnt=document.getElementById('act-count');
  var q=document.getElementById('act-q'), rk=document.getElementById('act-rank'), sh=document.getElementById('act-show'), lv='';
  var chips=[].slice.call(document.querySelectorAll('[data-lv]'));
  var sortK=null, sortDir=1;
  var num={level:1,rankord:1,clients:1,registered:1,persons:1,eur:1};
  function match(r){
   var t=q.value.trim().toLowerCase(), d=r.dataset;
   if(t&&d.name.indexOf(t)<0){return false;}
   if(lv!==''&&d.level!==lv){return false;}
   if(rk.value&&d.rank!==rk.value){return false;}
   switch(sh.value){
    case 'clients':return +d.clients>0; case 'noclients':return +d.clients===0; case 'waiting':return +d.waiting>0;
    case 'ready':return d.ready==='1'; case 'team':return +d.persons>0; case 'inactive':return d.inactive==='1';
   }
   return true;
  }
  function apply(){
   var n=0;
   rows.forEach(function(r){var ok=match(r);r.hidden=!ok;if(ok){n++;}});
   none.hidden=n>0;
   cnt.textContent=(n===rows.length?rows.length+' persone':n+' di '+rows.length+' persone');
  }
  function sortRows(){
   var k=sortK;
   var arr=rows.slice().sort(function(a,b){
    if(k===null){return a.dataset.i-b.dataset.i;}
    var key=k==='rank'?'rankord':k, x,y;
    if(num[key]){x=+a.dataset[key];y=+b.dataset[key];}else{x=a.dataset[key]||'';y=b.dataset[key]||'';}
    var c=x<y?-1:(x>y?1:0);
    return c*sortDir||(a.dataset.i-b.dataset.i);
   });
   arr.forEach(function(r){tb.insertBefore(r,none);});
   document.querySelectorAll('#act-t th[aria-sort]').forEach(function(th){var b=th.querySelector('.srt');th.setAttribute('aria-sort',b&&b.dataset.k===k?(sortDir>0?'ascending':'descending'):'none');});
  }
  q.addEventListener('input',apply); rk.addEventListener('change',apply); sh.addEventListener('change',apply);
  chips.forEach(function(c){c.addEventListener('click',function(){lv=c.dataset.lv;chips.forEach(function(x){x.classList.toggle('on',x===c);});apply();});});
  document.querySelectorAll('.srt').forEach(function(b){b.addEventListener('click',function(){
   var k=b.dataset.k; if(sortK===k){sortDir=-sortDir;}else{sortK=k;sortDir=(k==='name'||k==='level'||k==='rank')?1:-1;} sortRows();});});
  document.getElementById('act-reset').addEventListener('click',function(){
   q.value='';rk.value='';sh.value='';lv='';chips.forEach(function(x){x.classList.toggle('on',x.dataset.lv==='');});sortK=null;sortDir=1;sortRows();apply();});
 })();
</script>
@endsection
