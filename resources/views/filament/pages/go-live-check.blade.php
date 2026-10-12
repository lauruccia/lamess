<x-filament-panels::page>
    @php($bad = collect($checks)->contains('status', 'fail'))
    <div class="fi-section" style="padding:1.25rem 1.5rem">
        <strong style="font-size:1.05rem;color:{{ $bad ? '#B3261E' : '#2F6F4F' }}">{{ $summary }}</strong>
        <p style="margin:.35rem 0 0;font-size:.875rem;opacity:.75">Ricarica la pagina dopo ogni correzione. «Blocco» = da sistemare prima di aprire ai clienti; «Attenzione» = da valutare.</p>
    </div>
    <div class="fi-section" style="padding:0;overflow:hidden">
        <table style="width:100%;border-collapse:collapse;font-size:.9rem">
            @foreach($checks as $c)
                <tr style="border-bottom:1px solid rgba(187,180,175,.35)">
                    <td style="padding:.75rem 1.25rem;width:8.5rem">
                        @php($col = ['ok' => ['#E8F3EC', '#2F6F4F', 'OK'], 'warn' => ['#FFF3E6', '#9A5B12', 'ATTENZIONE'], 'fail' => ['#FBE9E7', '#B3261E', 'BLOCCO']][$c['status']])
                        <span style="display:inline-block;padding:.12rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;letter-spacing:.05em;background:{{ $col[0] }};color:{{ $col[1] }}">{{ $col[2] }}</span>
                    </td>
                    <td style="padding:.75rem .5rem;font-weight:600">{{ $c['label'] }}</td>
                    <td style="padding:.75rem 1.25rem;opacity:.8">{{ $c['detail'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</x-filament-panels::page>
