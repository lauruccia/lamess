<x-filament-panels::page>
    @php
        $d = $this->data();
        $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
        $p = $d['period'];
    @endphp
    <style>
        .cm-card { padding:1rem 1.25rem; }
        .cm-bar { display:flex; flex-wrap:wrap; gap:1rem 1.5rem; align-items:flex-end; font-size:.85rem; }
        .cm-bar label { display:flex; flex-direction:column; gap:.2rem; }
        .cm-bar input[type=search], .cm-bar select { padding:.3rem .55rem; border:1px solid rgba(187,180,175,.8); border-radius:.4rem; background:transparent; color:inherit; min-width:11rem; }
        .cm-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.75rem; margin-top:1rem; }
        .cm-kpi { border:1px solid rgba(187,180,175,.5); border-radius:.6rem; padding:.7rem .9rem; }
        .cm-kpi small { display:block; opacity:.65; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
        .cm-kpi strong { font-size:1.25rem; }
        .cm-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .cm-table th { text-align:left; padding:.55rem .75rem; border-bottom:1px solid rgba(187,180,175,.6); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; opacity:.7; white-space:nowrap; }
        .cm-table td { padding:.45rem .75rem; border-bottom:1px solid rgba(187,180,175,.25); }
        .cm-table .num { text-align:right; font-variant-numeric:tabular-nums; }
        .cm-table a { font-weight:600; color:inherit; text-decoration:none; }
        .cm-table a:hover { text-decoration:underline; color:#894C2D; }
        .cm-badge { display:inline-block; padding:.05rem .5rem; border-radius:999px; background:rgba(187,180,175,.25); font-size:.75rem; }
        .cm-up { color:#2f7d32; font-size:.75rem; margin-left:.35rem; }
        .cm-muted { opacity:.6; }
        .cm-warn { padding:.5rem .8rem; border-radius:.5rem; background:rgba(194,102,58,.12); font-size:.85rem; margin-top:.8rem; }
        .cm-scroll { overflow-x:auto; }
    </style>

    <div class="fi-section cm-card">
        <div class="cm-bar">
            <label>Mese
                <select wire:model.live="periodCode">
                    @foreach($this->periods() as $pp)
                        <option value="{{ $pp->code }}" @selected($p && $pp->code === $p->code)>{{ $pp->code }}{{ $pp->status === 'closed' ? ' (chiuso)' : ' (in corso)' }}</option>
                    @endforeach
                </select>
            </label>
            <label>Cerca incaricato
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Nome o codice…">
            </label>
            <label>Ordina per
                <select wire:model.live="sort">
                    <option value="total">Totale maturato</option>
                    <option value="held">Trattenuto</option>
                    <option value="name">Nome</option>
                </select>
            </label>
            <label style="flex-direction:row;align-items:center;gap:.4rem"><input type="checkbox" wire:model.live="onlyNonZero"> Solo chi ha importi</label>
            <button type="button" wire:click="exportCsv" class="fi-btn" style="padding:.35rem .8rem;border:1px solid rgba(187,180,175,.8);border-radius:.4rem">Esporta CSV</button>
        </div>

        @if($d['run'] === null)
            <p class="cm-warn">Nessun calcolo disponibile per questo mese: lancia «Stima (live)» dal mese in <a href="{{ \App\Filament\Resources\Periods\PeriodResource::getUrl('index') }}" style="text-decoration:underline">Periodi</a>.</p>
        @else
            <p class="cm-warn" style="{{ $d['closed'] ? 'background:rgba(47,125,50,.12)' : '' }}">
                @if($d['closed'])
                    Mese chiuso: importi definitivi.
                @else
                    Stima non definitiva, calcolo del {{ $d['run']->finished_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i') ?? '—' }}: si aggiorna a ogni ordine, pagamento o movimento e può cambiare fino alla chiusura del mese. Le qualifiche mostrate sono quelle del calcolo attuale.
                @endif
            </p>
            <div class="cm-kpis">
                <div class="cm-kpi"><small>Totale maturato</small><strong>€ {{ $eur($d['totals']['total']) }}</strong></div>
                <div class="cm-kpi"><small>di cui Bonus di Struttura</small><strong>€ {{ $eur($d['totals']['structure']) }}</strong></div>
                <div class="cm-kpi"><small>Trattenuto</small><strong>€ {{ $eur($d['totals']['held']) }}</strong></div>
                <div class="cm-kpi"><small>Saldo non ancora pagato</small><strong>€ {{ $eur($d['totals']['open']) }}</strong></div>
                <div class="cm-kpi"><small>Incaricati con importi</small><strong>{{ count($d['rows']) }}</strong></div>
            </div>
        @endif
    </div>

    @if($d['run'] !== null)
    <div class="fi-section cm-card cm-scroll">
        <table class="cm-table">
            <thead>
                <tr>
                    <th>Incaricato</th><th>Qualifica</th>
                    @foreach($d['types'] as $label)<th class="num">{{ $label }}</th>@endforeach
                    <th class="num">Bonus di Struttura</th><th class="num">Totale</th><th class="num">Trattenuto</th><th class="num">Saldo da pagare</th><th>Pagabile</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($d['rows'] as $r)
                    <tr wire:key="cm-{{ $r['id'] }}">
                        <td><a href="{{ $r['url'] }}">{{ $r['name'] }}</a> <span class="cm-muted" style="font-size:.75rem">{{ $r['code'] }}</span></td>
                        <td><span class="cm-badge">{{ $r['rank'] }}</span>@if($r['rank_from'])<span class="cm-up" title="Mese precedente: {{ $r['rank_from'] }}">↑ da {{ $r['rank_from'] }}</span>@endif</td>
                        @foreach(array_keys($d['types']) as $t)<td class="num {{ ($r['types'][$t] ?? 0) == 0 ? 'cm-muted' : '' }}">{{ $eur($r['types'][$t] ?? 0) }}</td>@endforeach
                        <td class="num {{ $r['structure'] == 0 ? 'cm-muted' : '' }}">{{ $eur($r['structure']) }}</td>
                        <td class="num"><strong>{{ $eur($r['total']) }}</strong></td>
                        <td class="num {{ $r['held'] == 0 ? 'cm-muted' : '' }}">{{ $eur($r['held']) }}</td>
                        <td class="num">{{ $eur($r['open']) }}</td>
                        <td>{{ $r['payable'] ? 'Sì' : 'No (IBAN/KYC)' }}</td>
                        <td>@if($r['statement'])<a href="{{ $r['statement'] }}" target="_blank">Statino</a>@else<a href="{{ $r['url'] }}">Scheda</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 8 + count($d['types']) }}" class="cm-muted">Nessun incaricato con importi in questo mese.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</x-filament-panels::page>
