@php
    $n = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $k = $p['kosmos'];
    $stateLabel = ['done' => 'raggiunta', 'current' => 'la tua', 'next' => 'prossima', 'future' => ''];
@endphp
<style>
    .ck { font-size:.92rem; line-height:1.45; }
    .ck h4 { margin:1rem 0 .4rem; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
    .ck .row { display:flex; justify-content:space-between; gap:.75rem; padding:.14rem 0; }
    .ck .row > span:last-child { font-weight:600; text-align:right; }
    .ck .bar { height:.55rem; border-radius:.3rem; background:rgba(127,127,127,.22); overflow:hidden; margin:.15rem 0 .1rem; }
    .ck .bar > i { display:block; height:100%; background:#C2663A; }
    .ck .bar.ok > i { background:#2E9E5B; }
    .ck .todo { border-left:3px solid #C2663A; padding:.1rem 0 .1rem .7rem; margin:.4rem 0; list-style:none; }
    .ck .todo li { margin:.25rem 0; }
    .ck .req { padding:.5rem 0; border-top:1px solid rgba(127,127,127,.2); }
    .ck .req:first-of-type { border-top:0; }
    .ck .req .hint { opacity:.7; font-size:.8rem; }
    .ck .tick { display:inline-block; width:1.2rem; font-weight:700; color:#2E9E5B; }
    .ck .tick.no { color:#C2663A; }
    .ck table { width:100%; border-collapse:collapse; font-size:.84rem; }
    .ck td, .ck th { padding:.2rem .3rem; text-align:right; } .ck td:first-child, .ck th:first-child { text-align:left; }
    .ck tr.now td { font-weight:700; background:rgba(194,102,58,.1); }
    .ck tr.off td { opacity:.55; }
    .ck .pill { display:inline-block; padding:.03rem .5rem; border-radius:999px; font-size:.72rem; font-weight:600; border:1px solid currentColor; }
    .ck .ladder { list-style:none; margin:0; padding:0; }
    .ck .ladder li { padding:.35rem 0 .35rem .7rem; border-left:3px solid rgba(127,127,127,.3); margin:.15rem 0; }
    .ck .ladder li.done { border-color:#2E9E5B; } .ck .ladder li.current { border-color:#C2663A; background:rgba(194,102,58,.08); } .ck .ladder li.next { border-color:#d98a1b; }
    .ck .ladder small { display:block; opacity:.7; }
</style>
<div class="ck">
    <div class="row"><span>Qualifica</span><span>{{ $p['rank']['name'] }}@if($p['effective_rank'] !== $p['rank']['name']) <span class="pill" title="Qualifica effettiva nel mese">effettiva: {{ $p['effective_rank'] }}</span>@endif</span></div>
    <div class="row"><span>Clienti personali</span><span>{{ $k['known'] ? $k['clients'] : '—' }}</span></div>
    <div class="row"><span>Diretti Pearl (1° livello)</span><span>{{ $k['pearl'] ?? '—' }}</span></div>
    <div class="row"><span>Compenso diretto</span><span>{{ $k['known'] ? $n($p['rank']['direct_pct']).'%' : '—' }}</span></div>
    <div style="opacity:.65;font-size:.8rem">Cliente è {{ $k['client_rule'] }}. Dati del calcolo di {{ $k['period'] ?? '—' }}.</div>

    @if(! empty($p['todo']))
        <h4>Cosa fare</h4>
        <ul class="todo">@foreach($p['todo'] as $t)<li>{{ $t }}</li>@endforeach</ul>
    @endif

    @if(! empty($k['current_requirements']))
        <h4>Requisiti della tua qualifica attuale ({{ $p['rank']['name'] }})</h4>
        @foreach($k['current_requirements'] as $r)
            @php($cpct = $r['required'] > 0 ? (int) min(100, floor($r['counted'] / $r['required'] * 100)) : 100)
            <div class="req">
                <div class="row"><span><span class="tick {{ ($r['ok'] || $r['disabled']) ? '' : 'no' }}">{{ ($r['ok'] || $r['disabled']) ? '✓' : '•' }}</span>{{ $r['label'] }}@if($r['disabled']) <span class="pill">non richiesto ora</span>@endif</span><span>{{ $r['counted'] }} / {{ $r['required'] }}</span></div>
                <div class="bar {{ ($r['ok'] || $r['disabled']) ? 'ok' : '' }}"><i style="width:{{ $cpct }}%"></i></div>
                @if($r['detail'] !== '')<div class="hint">{{ $r['detail'] }}</div>@endif
            </div>
        @endforeach
    @endif

    @if($k['next_name'])
        <h4>Requisiti per {{ $k['next_name'] }} · {{ $k['percent'] }}%</h4>
        <div class="bar {{ $k['percent'] >= 100 ? 'ok' : '' }}" role="progressbar" aria-valuenow="{{ $k['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $k['percent'] }}%"></i></div>
        @foreach($k['requirements'] as $r)
            @php($pct = $r['required'] > 0 ? (int) min(100, floor($r['counted'] / $r['required'] * 100)) : 100)
            <div class="req">
                <div class="row"><span><span class="tick {{ ($r['ok'] || $r['disabled']) ? '' : 'no' }}">{{ ($r['ok'] || $r['disabled']) ? '✓' : '•' }}</span>{{ $r['label'] }}@if($r['disabled']) <span class="pill">non richiesto ora</span>@endif</span><span>{{ $r['counted'] }} / {{ $r['required'] }}</span></div>
                <div class="bar {{ ($r['ok'] || $r['disabled']) ? 'ok' : '' }}"><i style="width:{{ $pct }}%"></i></div>
                @if($r['detail'] !== '')<div class="hint">{{ $r['detail'] }}</div>@endif
            </div>
        @endforeach
        @if($k['requirements'] === [])<div class="hint" style="opacity:.7">Nessun requisito attivo per questa qualifica.</div>@endif
    @endif

    @if($k['maintenance'])
        <h4>Mantenere la tua qualifica</h4>
        <div class="row"><span>Clienti per restare {{ $k['maintenance']['rank'] }}</span><span>{{ $k['maintenance']['counted'] }} / {{ $k['maintenance']['required'] }}</span></div>
        <div class="bar {{ $k['maintenance']['ok'] ? 'ok' : '' }}"><i style="width:{{ min(100, (int) floor($k['maintenance']['counted'] / max(1, $k['maintenance']['required']) * 100)) }}%"></i></div>
        @if($k['maintenance']['protected_until'])<div style="opacity:.7;font-size:.8rem">Le qualifiche attuali sono protette fino al {{ \Carbon\Carbon::parse($k['maintenance']['protected_until'])->format('d/m/Y') }}.</div>@endif
    @endif

    <h4>Compensi diretti per clienti</h4>
    <table><tr><th>Clienti</th><th>Percentuale</th><th></th></tr>
    @foreach($k['tiers'] as $t)
        <tr class="{{ $t['current'] ? 'now' : ($t['reached'] ? '' : 'off') }}"><td>{{ $t['min_clients'] }}+</td><td>{{ $n($t['pct']) }}%</td><td>@if($t['current'])sei qui @elseif(! $t['reached'] && $k['known'])ne mancano {{ $t['missing'] }}@endif</td></tr>
    @endforeach
    </table>

    <h4>Compensi indiretti per livello</h4>
    <table><tr><th>Livello</th><th>%</th><th>Clienti</th><th>Pearl 1°</th><th></th></tr>
    @foreach($k['levels'] as $l)
        <tr class="{{ $l['unlocked'] ? '' : 'off' }}"><td>{{ $l['level'] }}</td><td>{{ $n($l['pct']) }}%</td><td>{{ $l['min_clients'] }}</td><td>{{ $l['min_pearl'] }}</td><td>{{ $l['unlocked'] ? '✓' : ($k['known'] ? 'mancano '.collect([$l['missing_clients'] ? $l['missing_clients'].' cl.' : null, $l['missing_pearl'] ? $l['missing_pearl'].' Pearl' : null])->filter()->implode(' + ') : '') }}</td></tr>
    @endforeach
    </table>
    @if($k['extended'])
        <div style="margin-top:.4rem;font-size:.84rem;opacity:.85">Oltre il 5° livello (indiretti estesi): @foreach($k['extended'] as $e){{ $e['rank'] }} {{ $n($e['pct']) }}%{!! $e['active'] ? ' <span class="pill">attivo</span>' : '' !!}{{ ! $loop->last ? ' · ' : '' }}@endforeach</div>
    @endif

    <h4>Tutte le qualifiche</h4>
    <ul class="ladder">
    @foreach($k['ladder'] as $r)
        <li class="{{ $r['state'] }}"><strong>{{ $r['name'] }}</strong> @if($stateLabel[$r['state']])<span class="pill">{{ $stateLabel[$r['state']] }}</span>@endif
            <small>{{ $r['requirements'] ? implode(' · ', $r['requirements']) : 'nessun requisito' }}@if($r['structure_bonus'] > 0) · Bonus di Struttura € {{ $n($r['structure_bonus']) }}@endif @if($r['extended_pct'] > 0) · estesi {{ $n($r['extended_pct']) }}%@endif</small></li>
    @endforeach
    </ul>
</div>
