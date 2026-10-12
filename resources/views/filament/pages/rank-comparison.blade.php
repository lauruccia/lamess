<x-filament-panels::page>
    <div class="fi-section" style="padding:1.25rem 1.5rem">
        @if(! $version)
            <strong>Non c'è ancora una versione del piano Incaricati.</strong>
            <p style="margin:.35rem 0 0;font-size:.875rem;opacity:.75">Creala da Piano compensi → «Crea bozza Piano Incaricati».</p>
        @else
            <p style="margin:0 0 .5rem;font-size:.9rem">Piano usato per il confronto: <strong>{{ $version->name }}</strong> ({{ $version->status }}). Qualifiche precedenti salvate: <strong>{{ $frozen }}</strong> su {{ $total }}.</p>
            <label style="font-size:.85rem">Dati del periodo:
                <select wire:model.live="periodCode" style="margin-left:.4rem;padding:.2rem .5rem">
                    @foreach($periods as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </label>
            <label style="font-size:.85rem;margin-left:1.25rem">Conteggio clienti:
                <select wire:model.live="clientCount" style="margin-left:.4rem;padding:.2rem .5rem">
                    <option value="cumulative">Cumulativo (acquisti fino a fine mese)</option>
                    <option value="period">Solo acquisti del mese</option>
                </select>
            </label>
            <p style="margin:.5rem 0 0;font-size:.8rem;opacity:.7">Sola lettura: la nuova qualifica è calcolata ripartendo da zero con i clienti e la struttura del periodo scelto. Nessuna qualifica o compenso viene modificato.</p>
        @endif
    </div>
    @if($rows)
    <div class="fi-section" style="padding:0;overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <tr style="text-align:left;border-bottom:1px solid rgba(187,180,175,.5)">
                <th style="padding:.6rem 1rem">Incaricato</th><th>Precedente</th><th>Nuova qualifica</th><th>Clienti</th><th>Qualifica successiva</th><th>Cosa manca per la successiva</th>
            </tr>
            @foreach($rows as $r)
                <tr style="border-bottom:1px solid rgba(187,180,175,.25);{{ $r['changed'] ? 'background:rgba(255,243,230,.5)' : '' }}">
                    <td style="padding:.5rem 1rem">{{ $r['code'] }} — {{ $r['name'] }}</td>
                    <td>{{ $r['previous'] }}</td><td title="{{ $r['requirements'] }}" style="cursor:help"><strong>{{ $r['new_achieved'] }}</strong>@if($r['new_effective'] !== $r['new_achieved']) <span style="font-size:.75rem;opacity:.65" title="{{ $r['requirements_effective'] }}">(pagata {{ $r['new_effective'] }})</span>@endif</td>
                    <td>{{ $r['clients'] }}</td><td title="{{ $r['next_requirements'] }}" style="cursor:help"><strong>{{ $r['next_rank'] ?? '— (massima)' }}</strong></td>
                    <td style="opacity:.8">{{ $r['next_rank'] === null ? '' : ($r['missing'] !== '' ? $r['missing'] : 'requisiti soddisfatti') }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif
</x-filament-panels::page>
