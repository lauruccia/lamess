{{-- Punti assegnati dall'azienda (assegnazione libera). $g = PointsGrant::summaryFor(); $admin = true mostra anche il cliente e gli annullati. --}}
@php($admin = $admin ?? false)
<div class="cp">
    <h4 style="margin:.2rem 0 .5rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;opacity:.65">Punti assegnati dall'azienda</h4>
    @if($g['count'] === 0)
        <p style="opacity:.7;font-size:.92rem">Nessun punto assegnato.</p>
    @else
        <div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.92rem;margin-bottom:.4rem"><span>Totale punti assegnati (validi)</span><strong>{{ $g['total'] }}</strong></div>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="text-align:left;opacity:.65"><th style="padding:.2rem .3rem">Data</th><th style="padding:.2rem .3rem">Causale</th><th style="padding:.2rem .3rem;text-align:right">Punti</th>@if($admin)<th style="padding:.2rem .3rem">Effetto</th><th style="padding:.2rem .3rem">Cliente</th>@endif</tr></thead>
            <tbody>
            @foreach($g['rows'] as $r)
                <tr @if($r['cancelled']) style="opacity:.55;text-decoration:line-through" @endif>
                    <td style="padding:.2rem .3rem;white-space:nowrap">{{ $r['date'] }}</td>
                    <td style="padding:.2rem .3rem">{{ $r['reason'] }}@if($r['cancelled']) <em>(annullata)</em>@endif</td>
                    <td style="padding:.2rem .3rem;text-align:right;font-weight:600">{{ $r['points'] }}</td>
                    @if($admin)<td style="padding:.2rem .3rem">{{ $r['effect'] }}</td><td style="padding:.2rem .3rem">{{ $r['client'] ?? '—' }}</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($g['count'] > count($g['rows']))<p style="opacity:.7;font-size:.8rem">Ultime {{ count($g['rows']) }} di {{ $g['count'] }}.</p>@endif
    @endif
</div>
