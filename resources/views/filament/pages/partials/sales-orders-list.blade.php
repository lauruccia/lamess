@php($max = max(1.0, ...array_map(fn ($r) => (float) $r['total'], $rows ?: [['total' => 1]])))
@if(! $rows)
    <p class="lm-s-empty">Nessun ordine nel periodo.</p>
@else
    <div class="lm-s-head"><span>Cliente</span><span>Incaricato</span><span>Totale</span></div>
    <ol class="lm-s-rank">
        @foreach($rows as $r)
            <li style="--w:{{ round($r['total'] / $max * 100, 1) }}%">
                <span class="n" title="{{ $r['customer'] }}">{{ $r['customer'] }}<small>{{ $r['number'] }}</small></span>
                <span class="c" style="text-align:left">{{ $r['seller'] }}</span>
                <span class="e">{{ $eur2($r['total']) }}</span>
            </li>
        @endforeach
    </ol>
@endif
