@if(empty($p))
<p style="opacity:.7">Nessun dato di carriera: l’incaricato non è ancora nel calcolo del periodo.</p>
@else
@php
    $n = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $next = $p['next'];
    $leg = \App\Domain\Plan\LegacyPlan::visible();
@endphp
<style>
    .cp { font-size:.92rem; line-height:1.45; }
    .cp h4 { margin:.9rem 0 .35rem; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
    .cp .cp-bar { height:.6rem; border-radius:.3rem; background:rgba(127,127,127,.22); overflow:hidden; }
    .cp .cp-bar > i { display:block; height:100%; background:#C2663A; }
    .cp .cp-bar.ok > i { background:#2E9E5B; }
    .cp .cp-row { display:flex; justify-content:space-between; gap:.75rem; padding:.12rem 0; }
    .cp .cp-row span:last-child { font-weight:600; text-align:right; }
    .cp ul { margin:.2rem 0 0 1.1rem; padding:0; }
    .cp li { margin:.18rem 0; }
    .cp .cp-pill { display:inline-block; padding:.05rem .5rem; border-radius:999px; font-size:.75rem; font-weight:600; border:1px solid currentColor; }
    .cp .cp-todo { border-left:3px solid #C2663A; padding:.1rem 0 .1rem .7rem; margin:.5rem 0; }
    .cp .cp-todo { padding-left:.7rem; } .cp .cp-todo li { list-style:none; margin:.25rem 0; padding-left:1.1rem; text-indent:-1.1rem; }
    .cp .cp-todo li::before { content:'→'; display:inline-block; width:1.1rem; text-indent:0; color:#C2663A; font-weight:700; }
    .cp table { width:100%; border-collapse:collapse; font-size:.82rem; }
    .cp td, .cp th { padding:.15rem .3rem; text-align:right; } .cp td:first-child, .cp th:first-child { text-align:left; }
    .cp tr.cap td { color:#C2663A; }
</style>
<div class="cp">
    <div class="cp-row"><span>Qualifica</span><span>{{ $p['rank']['name'] }}@if($p['effective_rank'] !== $p['rank']['name']) <span class="cp-pill" title="Qualifica effettiva nel mese">effettiva: {{ $p['effective_rank'] }}</span>@endif</span></div>
    @if($leg)<div class="cp-row"><span>Percentuale Direct</span><span>{{ $n($p['rank']['direct_pct']) }}%</span></div>@endif

    @if(! empty($p['todo']))
        <h4>Cosa fare</h4>
        <ul class="cp-todo" style="margin-left:0">@foreach($p['todo'] as $t)<li>{{ $t }}</li>@endforeach</ul>
    @endif

    @if($next)
        <h4>Verso {{ $next['name'] }}</h4>
        @if($leg)
        <div class="cp-row"><span>PR di gruppo conteggiati</span><span>{{ $n($p['pr']['counted']) }} / {{ $n($p['pr']['required']) }}</span></div>
        <div class="cp-bar {{ $p['pr']['missing'] <= 0 ? 'ok' : '' }}" role="progressbar" aria-valuenow="{{ $p['pr']['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $p['pr']['percent'] }}%"></i></div>
        <div class="cp-row"><span>Mancano</span><span>{{ $n($p['pr']['missing']) }} PR</span></div>
        <div class="cp-row"><span>PR personali</span><span>{{ $n($p['pr']['personal']) }}</span></div>
        @endif
        <div class="cp-row"><span>Struttura</span><span>
            @if($p['structure']['required'] === []) nessun requisito
            @elseif($p['structure']['ok']) soddisfatta
            @else da completare @endif</span></div>
        @if($p['structure']['required'] !== [])
            <div style="opacity:.75;font-size:.82rem">Richiesto: {{ implode(', ', $p['structure']['required']) }}@if($p['structure']['missing'] !== []) · mancano: {{ implode(', ', $p['structure']['missing']) }}@endif</div>
        @endif
        @if($leg && $p['fifty'])
            <h4>Regola del 50% (tetto {{ $n($p['fifty']['cap']) }} PR per linea)</h4>
            <table><tr><th>Linea</th><th>PR</th><th>Contati</th></tr>
            @foreach(array_slice($p['fifty']['lines'], 0, 8) as $l)
                <tr class="{{ $l['capped'] ? 'cap' : '' }}"><td>{{ $l['name'] }}@if($l['rank']) <span style="opacity:.6">· {{ $l['rank'] }}</span>@endif</td><td>{{ $n($l['pr']) }}</td><td>{{ $n($l['counted']) }}</td></tr>
            @endforeach
            </table>
            @if(count($p['fifty']['lines']) > 8)<div style="opacity:.6;font-size:.8rem">+ {{ count($p['fifty']['lines']) - 8 }} altre linee</div>@endif
        @endif
    @endif

    @if($leg && ($p['activity']['applies'] ?? true))
    <h4>Attività del mese</h4>
    <div class="cp-row"><span>Punti</span><span>{{ $n($p['activity']['points']) }} / {{ $n($p['activity']['threshold']) }}</span></div>
    <div class="cp-bar {{ $p['activity']['active'] ? 'ok' : '' }}"><i style="width:{{ $p['activity']['threshold'] > 0 ? (int) min(100, floor($p['activity']['points'] / $p['activity']['threshold'] * 100)) : 100 }}%"></i></div>
    <div class="cp-row"><span>Stato</span><span>{{ $p['activity']['active'] ? 'Attivo' : 'Non attivo' }}@if($p['activity']['reason'] && ! $p['activity']['active']) <div style="font-weight:400;opacity:.7;font-size:.8rem">{{ preg_replace(['/^Non attivo:\s*/i', '/\s*\(regola:[^)]*\)/'], '', $p['activity']['reason']) }}</div>@endif</span></div>
    @endif

    <h4>Bonus</h4>
    @foreach($p['gates'] as $g)
        <div class="cp-row"><span>{{ $g['label'] }}</span><span>{{ $g['reached'] ? 'sbloccato' : 'dalla qualifica '.$g['rank'] }}</span></div>
    @endforeach
    @if($leg && $next && $next['rank_bonus'] > 0)
        <div class="cp-row"><span>Rank Bonus {{ $next['name'] }}</span><span>€ {{ $n($next['rank_bonus']) }}@if($next['installments'] > 1) in {{ $next['installments'] }} rate @endif</span></div>
    @endif
    @foreach($p['bonuses']['installments'] as $i)
        <div class="cp-row"><span>Rata {{ $i['rank'] }} {{ $i['number'] }}/{{ $i['of'] }} · {{ $i['due'] }}</span><span>€ {{ $n($i['amount']) }}@if($i['status'] === 'held') (trattenuta)@endif</span></div>
    @endforeach
    @foreach($p['bonuses']['awards'] as $a)
        <div class="cp-row"><span>Premio {{ $a['rank'] }}</span><span>€ {{ $n($a['amount']) }} · {{ ['awarded' => 'assegnato', 'held' => 'trattenuto', 'forfeited' => 'decaduto', 'not_awarded' => 'non assegnato'][$a['status']] ?? $a['status'] }}</span></div>
    @endforeach
</div>
@endif
