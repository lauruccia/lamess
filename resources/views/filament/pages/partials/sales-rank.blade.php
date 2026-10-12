@php($max = max(1.0, ...array_map(fn ($r) => (float) $r['revenue'], $rows ?: [['revenue' => 1]])))
@if(! $rows)
    <p class="lm-s-empty">Nessun dato nel periodo.</p>
@else
    <div class="lm-s-head"><span>{{ $label }}</span><span>{{ $countLabel }}</span><span>Ricavo</span></div>
    <ol class="lm-s-rank">
        @foreach($rows as $r)
            <li style="--w:{{ round($r['revenue'] / $max * 100, 1) }}%">
                <span class="n" title="{{ $r['label'] }}">{{ $r['label'] }}</span>
                <span class="c">{{ number_format($r['orders'], 0, ',', '.') }}</span>
                <span class="e">{{ $eur2($r['revenue']) }}</span>
            </li>
        @endforeach
    </ol>
@endif
