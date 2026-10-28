<x-filament-panels::page>
    @php
        $d = $this->data();
        $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
        $p = $this->period();
        $c = $this->consultant();
    @endphp
    <style>
        .cd-card { padding:1rem 1.25rem; }
        .cd-bar { display:flex; flex-wrap:wrap; gap:1rem 1.5rem; align-items:flex-end; font-size:.85rem; }
        .cd-bar label { display:flex; flex-direction:column; gap:.2rem; }
        .cd-bar select { padding:.3rem .55rem; border:1px solid rgba(187,180,175,.8); border-radius:.4rem; background:transparent; color:inherit; min-width:9rem; }
        .cd-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.75rem; margin-top:1rem; }
        .cd-kpi { border:1px solid rgba(187,180,175,.5); border-radius:.6rem; padding:.7rem .9rem; }
        .cd-kpi small { display:block; opacity:.65; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
        .cd-kpi strong { font-size:1.25rem; }
        .cd-warn { padding:.5rem .8rem; border-radius:.5rem; background:rgba(194,102,58,.12); font-size:.85rem; margin-top:.8rem; }
        .cd-rules { margin:.6rem 0 0; padding-left:1.1rem; font-size:.8rem; opacity:.8; }
        .cd-group h3 { margin:0 0 .6rem; font-size:1.05rem; display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .cd-group h3 span { font-weight:400; font-size:.85rem; opacity:.75; }
        .cd-line { border-top:1px solid rgba(187,180,175,.35); padding:.65rem 0; display:grid; grid-template-columns:1fr auto; gap:.2rem 1rem; }
        .cd-line:first-of-type { border-top:0; }
        .cd-title { font-weight:600; font-size:.9rem; }
        .cd-sentence { font-size:.88rem; line-height:1.35; }
        .cd-details { margin:.3rem 0 0; padding-left:1.1rem; font-size:.8rem; opacity:.8; }
        .cd-amount { text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap; }
        .cd-badge { display:inline-block; padding:.05rem .5rem; border-radius:999px; background:rgba(187,180,175,.25); font-size:.72rem; margin-left:.4rem; font-weight:400; }
        .cd-badge.held { background:rgba(194,102,58,.2); }
        .cd-badge.excluded { background:rgba(180,40,40,.15); }
        .cd-line.is-warning { background:rgba(194,102,58,.1); border-radius:.4rem; padding-left:.6rem; padding-right:.6rem; }
        .cd-muted { opacity:.6; }
        .cd-link { color:inherit; font-weight:600; text-decoration:underline; }
    </style>

    <div class="fi-section cd-card">
        <div class="cd-bar">
            <div>
                <div style="font-size:1.2rem;font-weight:700">{{ $c?->name ?? 'Incaricato non trovato' }} <span class="cd-muted" style="font-size:.85rem">{{ $c?->code }}</span></div>
                @if($this->consultantUrl())<a class="cd-link" style="font-size:.8rem" href="{{ $this->consultantUrl() }}">Apri la scheda incaricato →</a>@endif
                <a class="cd-link" style="font-size:.8rem;margin-left:1rem" href="{{ \App\Filament\Pages\CompensiMesePage::getUrl() }}">← Compensi del mese</a>
            </div>
            <label>Mese
                <select wire:model.live="periodCode">
                    @foreach($this->periods() as $pp)
                        <option value="{{ $pp->code }}" @selected($p && $pp->code === $p->code)>{{ $pp->code }}{{ $pp->status === 'closed' ? ' (chiuso)' : ' (in corso)' }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if($d === null)
            <p class="cd-warn">Scegli un incaricato da «Compensi del mese».</p>
        @elseif($d['run_id'] === null)
            <p class="cd-warn">Nessun calcolo disponibile per questo mese: lancia «Stima (live)» dal mese in Periodi.</p>
        @else
            <p class="cd-warn" style="{{ $d['closed'] ? 'background:rgba(47,125,50,.12)' : '' }}">
                {{ $d['closed'] ? 'Mese chiuso: importi definitivi.' : 'Stima non definitiva dell\'ultimo calcolo: si aggiorna a ogni ordine, pagamento o movimento e può cambiare fino alla chiusura del mese.' }}
            </p>
            <div class="cd-kpis">
                <div class="cd-kpi"><small>Maturato</small><strong>€ {{ $eur($d['accrued']) }}</strong></div>
                <div class="cd-kpi"><small>Trattenuto</small><strong>€ {{ $eur($d['held']) }}</strong></div>
                <div class="cd-kpi"><small>Non spettante</small><strong>€ {{ $eur($d['excluded']) }}</strong></div>
            </div>
            @if($d['rules'])
                <details style="margin-top:.8rem"><summary style="cursor:pointer;font-size:.85rem;font-weight:600">Regole del piano usate per il calcolo</summary>
                    <ul class="cd-rules">@foreach($d['rules'] as $r)<li>{{ $r }}</li>@endforeach</ul>
                </details>
            @endif
        @endif
    </div>

    @if($d !== null && $d['run_id'] !== null)
        @forelse($d['groups'] as $g)
            <div class="fi-section cd-card cd-group">
                <h3>{{ $g['label'] }} <span>maturato € {{ $eur($g['accrued']) }}@if($g['held'] != 0) · trattenuto € {{ $eur($g['held']) }}@endif @if($g['excluded'] != 0) · non spettante € {{ $eur($g['excluded']) }}@endif · {{ count($g['lines']) }} {{ count($g['lines']) === 1 ? 'riga' : 'righe' }}</span></h3>
                @foreach($g['lines'] as $l)
                    <div class="cd-line {{ $l['warning'] ? 'is-warning' : '' }}" wire:key="cd-{{ $l['type'] }}-{{ $l['id'] }}">
                        <div>
                            <div class="cd-title">{{ $l['title'] }}<span class="cd-badge {{ $l['status'] }}">{{ $l['status_label'] }}</span>@if($l['order_url'])<a class="cd-link" style="font-size:.75rem;font-weight:400;margin-left:.5rem" href="{{ $l['order_url'] }}">apri ordine</a>@endif</div>
                            <div class="cd-sentence">{{ $l['sentence'] }}</div>
                            @if($l['details'])<ul class="cd-details">@foreach($l['details'] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
                        </div>
                        <div class="cd-amount">€ {{ $eur($l['amount']) }}@if($l['pct'])<div class="cd-muted" style="font-weight:400;font-size:.75rem">{{ $l['pct'] }}@if($l['base'] !== null) su {{ $eur($l['base']) }}@endif</div>@endif</div>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="fi-section cd-card"><p class="cd-muted">Nessun compenso per questo incaricato nel mese.</p></div>
        @endforelse
    @endif
</x-filament-panels::page>
