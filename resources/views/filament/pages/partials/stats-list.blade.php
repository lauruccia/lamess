{{-- Elenco con barra di proporzione. $rows: [label, count, share, (sub), (extra)] — $empty: testo se vuoto; $unit: colonna dei numeri --}}
@if(empty($rows))
    <p class="lm-s-empty">{{ $empty ?? 'Nessun dato nel periodo.' }}</p>
@else
    <ul class="lm-s-rank">
        @foreach($rows as $r)
            <li style="--w:{{ min(100, max(0, $r['share'] ?? 0)) }}%">
                <span class="n" title="{{ $r['label'] }}">{{ $r['label'] !== '' ? $r['label'] : '—' }}@if(! empty($r['sub']))<small>{{ $r['sub'] }}</small>@endif</span>
                <span class="c">{{ isset($r['share']) ? number_format($r['share'], 0, ',', '.').'%' : '' }}</span>
                <span class="e">{{ number_format($r['count'], 0, ',', '.') }}</span>
            </li>
        @endforeach
    </ul>
@endif
