@extends('site.layout')
@section('title','Dettaglio compensi — Lamèss')
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $groups = collect($d['groups'] ?? []);
    $direct = $groups->where('type', 'direct');
    $indirect = $groups->whereIn('type', ['indirect', 'indirect_ext']);
    $structure = $groups->where('type', 'structure');
    $others = $groups->reject(fn ($g) => in_array($g['type'], ['direct', 'indirect', 'indirect_ext', 'structure'], true));
    $sum = fn ($gs, $k) => (float) $gs->sum($k);
    $linesOf = fn ($gs) => $gs->flatMap(fn ($g) => $g['lines'])->values();
    $tone = fn ($s) => ['accrued' => 'ok', 'held' => 'warn'][$s] ?? 'err';
    $monthly = $d ? $d['accrued'] - $sum($structure, 'accrued') : 0.0;
    $ms = $d['missing'] ?? null;
    $count = fn ($n) => $n.' '.($n === 1 ? 'riga' : 'righe');

    // Riepilogo per aliquota (diretti) e per livello (indiretti): solo righe maturate.
    $byPct = fn ($lines) => collect($lines)->where('status', 'accrued')->groupBy(fn ($l) => ($l['level'] ? 'L'.$l['level'].' · ' : '').($l['pct'] ?? '—'))
        ->map(fn ($g, $k) => ['label' => $k, 'level' => $g->first()['level'], 'n' => $g->count(), 'base' => (float) $g->sum('base'), 'amount' => (float) $g->sum('amount')])
        ->sortBy(fn ($r) => $r['level'] ?? 0)->values();
@endphp

<p class="crumb"><a href="/area">← La mia area</a></p>
<div class="pg-head">
 <div>
  <h1>Dettaglio compensi</h1>
  <p class="sub">Ogni compenso del mese: da quale ordine nasce, a che livello, con quale regola e perché è maturato, trattenuto o non ancora spettante.</p>
 </div>
 <div class="pg-actions">
  @if($periods->isNotEmpty())
   <form method="get" action="/area/compensi" style="display:flex;gap:8px;align-items:center">
    <label for="mese" style="font-size:13.5px;font-weight:600">Mese</label>
    <select id="mese" name="mese" onchange="this.form.submit()">
     @foreach($periods as $pp)
      <option value="{{ $pp->code }}" @selected($period && $pp->code === $period->code)>{{ $pp->code }}{{ $pp->isClosed() ? ' (chiuso)' : ' (in corso)' }}</option>
     @endforeach
    </select>
    <noscript><button class="btn ghost" type="submit">Vai</button></noscript>
   </form>
  @endif
  @if($hasStatement)<a class="btn accent" href="/area/statini/{{ $period->code }}">Statino del mese</a>@else<a class="btn ghost" href="/area/statini">Statini</a>@endif
 </div>
</div>

@if($periods->isEmpty())
 <section class="panel"><div class="empty"><div><strong>Ancora nessun calcolo</strong><p>I compensi compaiono qui appena il portale calcola il primo mese.</p></div></div></section>
@elseif($d === null)
 <div class="note note-err"><div><strong>Dettaglio non disponibile</strong>Non riesco a leggere il calcolo di questo mese. Riprova tra poco; se il problema resta scrivici dall'assistenza.</div><a class="btn" href="/area/assistenza">Assistenza</a></div>
@elseif($d['run_id'] === null)
 <div class="note note-info"><div><strong>Nessun calcolo per {{ $period->code }}</strong>Il calcolo del mese non è ancora disponibile.</div></div>
@else
 <div class="note {{ $d['closed'] ? 'note-ok' : 'note-info' }}"><div>
  <strong>{{ $d['closed'] ? 'Mese chiuso: importi definitivi' : 'Compensi provvisori' }}</strong>
  {{ $d['closed'] ? 'Questi importi sono quelli dello statino del mese.' : 'Stima dell\'ultimo calcolo: si aggiorna a ogni ordine, pagamento o reso e può cambiare con nuovi clienti fino alla chiusura del mese.' }}
 </div></div>

 <div class="kpis">
  <div class="kpi k-ok"><small>Compensi del mese</small><strong>€ {{ $eur($monthly) }}</strong><span>Diretti + indiretti{{ $others->isNotEmpty() ? ' + altre voci' : '' }}, senza il Bonus istantaneo</span></div>
  <div class="kpi"><small>Diretti</small><strong>€ {{ $eur($sum($direct, 'accrued')) }}</strong><span>{{ $count($linesOf($direct)->count()) }}</span></div>
  <div class="kpi"><small>Indiretti</small><strong>€ {{ $eur($sum($indirect, 'accrued')) }}</strong><span>{{ $count($linesOf($indirect)->count()) }}</span></div>
  <a class="kpi" href="#struttura"><small>Bonus istantaneo</small><strong>€ {{ $eur($sum($structure, 'accrued')) }}</strong><span>Bonus di Struttura: si paga a parte, ogni settimana</span></a>
  <div class="kpi {{ $d['held'] != 0 ? 'k-warn' : '' }}"><small>Trattenuto</small><strong>€ {{ $eur($d['held']) }}</strong><span>Non erogato per ora</span></div>
  <div class="kpi {{ ($ms['total'] ?? 0) != 0 ? 'k-warn' : '' }}"><small>Non maturato</small><strong>€ {{ $eur($ms['total'] ?? 0) }}</strong><span>Requisiti mancanti</span></div>
 </div>

 <div class="stack">
  {{-- Compensi diretti --}}
  <section class="panel" id="diretti">
   <header><h2>Compensi diretti</h2><span><strong>€ {{ $eur($sum($direct, 'accrued')) }}</strong>@if($sum($direct, 'held') != 0) <span class="st st-warn">€ {{ $eur($sum($direct, 'held')) }} trattenuti</span>@endif</span></header>
   <div class="panel-body">
    <p class="m" style="margin:0 0 6px;color:var(--muted)">Il compenso sulle vendite ai tuoi clienti personali (e sui tuoi acquisti). L'aliquota dipende da quanti clienti personali hai nel mese e vale su tutte le vendite del mese.</p>
   </div>
   @if($direct->isEmpty())
    <div class="empty"><div><strong>Nessun compenso diretto in questo mese</strong><p>Compare qui quando un tuo cliente personale fa un ordine.</p></div></div>
   @else
    @php $rows = $byPct($linesOf($direct)); @endphp
    <div class="tbl-wrap"><table class="tbl cards">
     <thead><tr><th>Aliquota</th><th class="num">Ordini</th><th class="num">Base di calcolo</th><th class="num">Compenso</th></tr></thead>
     <tbody>
      @foreach($rows as $r)
       <tr><td data-l="Aliquota"><strong>{{ str_replace(' · ', '', $r['label']) }}</strong></td><td data-l="Ordini" class="num">{{ $r['n'] }}</td><td data-l="Base di calcolo" class="num">€ {{ $eur($r['base']) }}</td><td data-l="Compenso" class="num"><strong>€ {{ $eur($r['amount']) }}</strong></td></tr>
      @endforeach
     </tbody>
    </table></div>
    <div class="list">
     @foreach($direct as $g)
      @foreach($g['lines'] as $l)
       @include('site.area._earning-line', ['l' => $l, 'eur' => $eur, 'tone' => $tone])
      @endforeach
     @endforeach
    </div>
   @endif
  </section>

  {{-- Compensi indiretti --}}
  <section class="panel" id="indiretti">
   <header><h2>Compensi indiretti</h2><span><strong>€ {{ $eur($sum($indirect, 'accrued')) }}</strong>@if($sum($indirect, 'held') != 0) <span class="st st-warn">€ {{ $eur($sum($indirect, 'held')) }} trattenuti</span>@endif</span></header>
   <div class="panel-body">
    <p class="m" style="margin:0 0 6px;color:var(--muted)">Il compenso sugli ordini della tua rete, per livello (dal 1° al 5°, più l'indiretto esteso per chi ha la qualifica). Ogni livello matura se hai i suoi requisiti: clienti personali e Pearl al 1° livello.</p>
   </div>
   @if($indirect->isEmpty())
    <div class="empty"><div><strong>Nessun compenso indiretto in questo mese</strong><p>Compare qui quando le persone della tua rete fanno ordini e hai i requisiti del livello.</p></div></div>
   @else
    @php $rows = $byPct($linesOf($indirect)); @endphp
    <div class="tbl-wrap"><table class="tbl cards">
     <thead><tr><th>Livello</th><th>Aliquota</th><th class="num">Ordini</th><th class="num">Base di calcolo</th><th class="num">Compenso</th></tr></thead>
     <tbody>
      @foreach($rows as $r)
       @php $parts = explode(' · ', $r['label']); @endphp
       <tr><td data-l="Livello"><strong>{{ $r['level'] ?? '—' }}</strong></td><td data-l="Aliquota">{{ $parts[1] ?? $parts[0] }}</td><td data-l="Ordini" class="num">{{ $r['n'] }}</td><td data-l="Base di calcolo" class="num">€ {{ $eur($r['base']) }}</td><td data-l="Compenso" class="num"><strong>€ {{ $eur($r['amount']) }}</strong></td></tr>
      @endforeach
     </tbody>
    </table></div>
    <div class="list">
     @foreach($indirect as $g)
      @foreach($g['lines'] as $l)
       @include('site.area._earning-line', ['l' => $l, 'eur' => $eur, 'tone' => $tone])
      @endforeach
     @endforeach
    </div>
   @endif
  </section>

  {{-- Bonus istantaneo (Bonus di Struttura) --}}
  <section class="panel" id="struttura">
   <header><h2>Bonus istantaneo (Bonus di Struttura)</h2><span><strong>€ {{ $eur($sum($structure, 'accrued')) }}</strong>@if($sum($structure, 'held') != 0) <span class="st st-warn">€ {{ $eur($sum($structure, 'held')) }} trattenuti</span>@endif</span></header>
   <div class="panel-body">
    <p class="m" style="margin:0 0 6px;color:var(--muted)">Scatta quando una persona che hai portato in rete diventa PearlQ (3 clienti personali entro 30 giorni dall'attivazione): a te spetta la differenza tra il bonus della tua qualifica e il più alto già riconosciuto lungo la catena. Non entra nel totale mensile: si conferma e si paga a parte, con lo <a href="/area/statini-bonus">statino bonus settimanale</a>.</p>
   </div>
   @if($structure->isEmpty())
    <div class="empty"><div><strong>Nessun bonus in questo mese</strong><p>Compare qui quando qualcuno della tua rete diventa PearlQ.</p></div></div>
   @else
    <div class="list">
     @foreach($structure as $g)
      @foreach($g['lines'] as $l)
       @include('site.area._earning-line', ['l' => $l, 'eur' => $eur, 'tone' => $tone])
      @endforeach
     @endforeach
    </div>
   @endif
  </section>

  {{-- Altre voci (storni per reso, ecc.) --}}
  @foreach($others as $g)
   <section class="panel">
    <header><h2>{{ $g['label'] }}</h2><span><strong>€ {{ $eur($g['accrued']) }}</strong>@if($g['held'] != 0) <span class="st st-warn">€ {{ $eur($g['held']) }} trattenuti</span>@endif</span></header>
    <div class="list">
     @foreach($g['lines'] as $l)
      @include('site.area._earning-line', ['l' => $l, 'eur' => $eur, 'tone' => $tone])
     @endforeach
    </div>
   </section>
  @endforeach

  {{-- Non maturati --}}
  @if($ms && $ms['lines'])
   <section class="panel" id="non-maturati">
    <header><h2>Compensi non maturati: requisiti mancanti</h2><span><strong>€ {{ $eur($ms['total']) }}</strong> · {{ $count(count($ms['lines'])) }}</span></header>
    <div class="panel-body">
     <p class="m" style="margin:0;color:var(--muted)">Sono compensi che ti sarebbero spettati su ordini della tua rete ma non maturano perché non hai ancora i requisiti del livello. Se li raggiungi prima della chiusura del mese, al ricalcolo maturano.</p>
    </div>
    @if($ms['levels'])
     <div class="tbl-wrap"><table class="tbl cards">
      <thead><tr><th>Livello</th><th>Aliquota</th><th>Requisiti</th><th>Hai</th><th>Cosa manca</th><th class="num">Non maturato</th><th class="num">Ordini</th></tr></thead>
      <tbody>
       @foreach($ms['levels'] as $lv)
        <tr>
         <td data-l="Livello"><strong>{{ $lv['level'] }}</strong></td>
         <td data-l="Aliquota">{{ $lv['pct'] }}</td>
         <td data-l="Requisiti">almeno {{ $lv['min_clients'] }} {{ $lv['min_clients'] === 1 ? 'cliente' : 'clienti' }} · {{ $lv['min_pearl'] }} Pearl al 1° livello</td>
         <td data-l="Hai">{{ $lv['clients'] }} {{ $lv['clients'] === 1 ? 'cliente' : 'clienti' }} · {{ $lv['pearl'] }} Pearl</td>
         <td data-l="Cosa manca">{{ $lv['lack_text'] !== '' ? $lv['lack_text'] : '—' }}</td>
         <td data-l="Non maturato" class="num"><strong>€ {{ $eur($lv['amount']) }}</strong></td>
         <td data-l="Ordini" class="num">{{ $lv['count'] }}</td>
        </tr>
       @endforeach
      </tbody>
     </table></div>
    @endif
    @foreach($ms['other'] as $o)
     <div class="panel-body" style="padding-top:0"><p class="m" style="margin:0">{{ $o['reason'] }} <span style="color:var(--muted)">({{ $count($o['count']) }}, € {{ $eur($o['amount']) }})</span></p></div>
    @endforeach
    <details style="border-top:1px solid var(--hair)">
     <summary style="cursor:pointer;padding:14px 18px;font-weight:600">Vedi ogni ordine non maturato</summary>
     <div class="list">
      @foreach($ms['lines'] as $l)
       @include('site.area._earning-line', ['l' => $l, 'eur' => $eur, 'tone' => $tone])
      @endforeach
     </div>
    </details>
   </section>
  @endif

  {{-- Regole --}}
  @if($d['rules'])
   <section class="panel">
    <details>
     <summary style="cursor:pointer;padding:14px 18px;font-weight:600">Regole del piano usate per il calcolo</summary>
     <div class="panel-body"><ul style="margin:0;padding-left:18px;line-height:1.55">@foreach($d['rules'] as $r)<li>{{ $r }}</li>@endforeach</ul></div>
    </details>
   </section>
  @endif
 </div>
@endif
@endsection
