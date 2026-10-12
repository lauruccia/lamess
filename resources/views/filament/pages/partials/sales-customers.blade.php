@php($max = max(1.0, ...array_map(fn ($r) => (float) $r['revenue'], $rows ?: [['revenue' => 1]])))
@if(! $rows)
    <p class="lm-s-empty">Nessun dato nel periodo.</p>
@else
    <div class="lm-s-head" style="grid-template-columns:minmax(0,1fr) auto auto auto"><span>Cliente</span><span>Ordini</span><span>Incaricato</span><span>Ricavo</span></div>
    <ol class="lm-s-rank">
        @foreach($rows as $r)
            <li style="--w:{{ round($r['revenue'] / $max * 100, 1) }}%;grid-template-columns:minmax(0,1fr) auto auto auto">
                <span class="n" title="{{ $r['label'] }}">{{ $r['label'] }}</span>
                <span class="c">{{ number_format($r['orders'], 0, ',', '.') }}</span>
                <span class="c" style="text-align:left;min-width:7rem" title="Incaricato del cliente">{{ $r['seller'] }}</span>
                <span class="e">{{ $eur2($r['revenue']) }}</span>
            </li>
        @endforeach
    </ol>
@endif
