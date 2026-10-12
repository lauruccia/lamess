@php
    $hl = $d['seller_highlights'];
    $cards = [
        ['Più ricavo', $hl['revenue'], fn ($v) => $eur0($v)],
        ['Più ordini', $hl['orders'], fn ($v) => number_format($v, 0, ',', '.').' ordini'],
        ['Più nuovi clienti', $hl['new_customers'], fn ($v) => number_format($v, 0, ',', '.').' clienti'],
        ['Più clienti serviti', $hl['customers'], fn ($v) => number_format($v, 0, ',', '.').' clienti'],
    ];
    $maxShare = max(1.0, ...array_map(fn ($r) => (float) $r['share'], $d['seller_stats'] ?: [['share' => 1]]));
@endphp
<section class="lm-s-card" aria-labelledby="s-sellers">
    <h2 id="s-sellers">Performance incaricati</h2>
    <p class="lm-s-note" style="margin-bottom:.75rem">{{ $d['sellers_with_sales'] }} incaricati con vendite nel periodo su {{ $d['sellers_active'] }} attivi.</p>

    <div class="lm-s-hl">
        @foreach($cards as [$title, $best, $fmt])
            <div>
                <small>{{ $title }}</small>
                @if($best)<b>{{ $best['label'] }}</b><span>{{ $fmt($best['value']) }}</span>@else<b>—</b><span>nessun dato</span>@endif
            </div>
        @endforeach
    </div>

    @if(! $d['seller_stats'])
        <p class="lm-s-empty">Nessuna vendita nel periodo.</p>
    @else
        <div class="lm-s-tw">
            <table class="lm-s-t">
                <thead>
                    <tr><th class="pos">#</th><th>Incaricato</th><th>Ordini</th><th>Ricavo</th><th>Quota</th><th>Ordine medio</th><th>Clienti serviti</th><th>Nuovi clienti</th><th>Vs periodo prec.</th></tr>
                </thead>
                <tbody>
                    @php($pos = 0)
                    @foreach($d['seller_stats'] as $r)
                        <tr @class(['dim' => $r['id'] === null])>
                            <td class="pos">{{ $r['id'] === null ? '' : ++$pos }}</td>
                            <td>{{ $r['label'] }}@if($r['rank'])<small>{{ $r['rank'] }}</small>@elseif($r['id'] === null)<small>ordini senza incaricato</small>@endif</td>
                            <td>{{ number_format($r['orders'], 0, ',', '.') }}</td>
                            <td><strong>{{ $eur2($r['revenue']) }}</strong></td>
                            <td><span class="sh" aria-hidden="true"><i style="--w:{{ round($r['share'] / $maxShare * 100, 1) }}%"></i></span>{{ number_format($r['share'], 1, ',', '.') }}%</td>
                            <td>{{ $r['orders'] ? $eur2($r['avg']) : '—' }}</td>
                            <td>{{ number_format($r['customers'], 0, ',', '.') }}</td>
                            <td>{{ $r['id'] === null ? '—' : number_format($r['new_customers'], 0, ',', '.') }}</td>
                            <td>
                                @if($r['delta'] === null)<span class="dim">—</span>
                                @else<span class="{{ $r['delta'] >= 0 ? 'up' : 'down' }}">{{ $r['delta'] >= 0 ? '↑' : '↓' }} {{ number_format(abs($r['delta']), 1, ',', '.') }}%</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="lm-s-note" style="margin-top:.6rem">Quota = parte del ricavo del periodo. «Nuovi clienti» = clienti assegnati all'incaricato e registrati nel periodo. Primi {{ count($d['seller_stats']) }} per ricavo.</p>
    @endif
</section>
