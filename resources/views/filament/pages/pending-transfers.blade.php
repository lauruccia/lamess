<x-filament-panels::page>
    @php
        $d = $this->data();
        $p = $d['period'];
        $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
        $rome = fn ($dt) => $dt?->copy()->setTimezone('Europe/Rome')->format('d/m/Y H:i');
    @endphp
    <style>
        .pt-card { padding:1rem 1.25rem; }
        .pt-bar { display:flex; flex-wrap:wrap; gap:1rem 1.5rem; align-items:flex-end; font-size:.85rem; }
        .pt-bar select { padding:.3rem .55rem; border:1px solid rgba(187,180,175,.8); border-radius:.4rem; background:transparent; color:inherit; min-width:11rem; }
        .pt-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .pt-table th { text-align:left; padding:.55rem .75rem; border-bottom:1px solid rgba(187,180,175,.6); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; opacity:.7; white-space:nowrap; }
        .pt-table td { padding:.5rem .75rem; border-bottom:1px solid rgba(187,180,175,.25); vertical-align:middle; }
        .pt-table .num { text-align:right; font-variant-numeric:tabular-nums; }
        .pt-table a { font-weight:600; color:inherit; text-decoration:none; }
        .pt-table a:hover { text-decoration:underline; }
        .pt-actions { display:flex; flex-wrap:wrap; gap:.4rem; }
        .pt-note { padding:.6rem .9rem; border-radius:.5rem; background:rgba(194,102,58,.12); font-size:.85rem; margin-top:.8rem; }
        .pt-ok { background:rgba(47,125,50,.12); }
        .pt-scroll { overflow-x:auto; }
    </style>

    <div class="fi-section pt-card">
        <div class="pt-bar">
            <label>Mese
                <select wire:model.live="periodCode">
                    @foreach($this->periods() as $pp)
                        <option value="{{ $pp->code }}" @selected($p && $pp->code === $p->code)>{{ $pp->code }}{{ $pp->status === 'closed' ? ' (chiuso)' : ' (aperto)' }}</option>
                    @endforeach
                </select>
            </label>
            @if($p)
                <span>{{ $d['rows']->count() }} bonifici in attesa · mese successivo: <strong>{{ $d['next'] }}</strong></span>
            @endif
        </div>

        <div class="pt-note">
            Un ordine con bonifico conta nel mese in cui viene confermato. Qui, prima del runtime ufficiale, puoi farlo valere nel mese dell'ordine
            (se il bonifico è arrivato dopo la fine del mese), rimandarlo al mese dopo oppure annullarlo.
            La fattura e la data di incasso restano quelle reali.
            @if($p && ! $d['closed'] && $d['ended'])
                Il mese è finito: il runtime ufficiale parte solo quando qui non resta nessun bonifico.
            @endif
        </div>

        @if(! $p)
            <p style="margin-top:1rem">Nessun mese disponibile.</p>
        @elseif($d['closed'])
            <div class="pt-note">Il mese {{ $p->code }} è chiuso: i bonifici non si decidono più da qui.</div>
        @elseif($d['rows']->isEmpty())
            <div class="pt-note pt-ok">Nessun bonifico in sospeso nel mese {{ $p->code }}: puoi lanciare il runtime ufficiale.</div>
        @else
            <div class="pt-scroll" style="margin-top:1rem">
                <table class="pt-table">
                    <thead>
                        <tr><th>Ordine</th><th>Data ordine</th><th>Cliente</th><th class="num">Totale €</th><th class="num">Punti</th><th class="num">Giorni</th><th>Scelta</th></tr>
                    </thead>
                    <tbody>
                        @foreach($d['rows'] as $o)
                            <tr wire:key="pt-{{ $o->id }}">
                                <td><a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $o]) }}">{{ $o->number }}</a>
                                    @if($o->deferred_to_period)<br><small>rimandato a {{ $o->deferred_to_period }}</small>@endif</td>
                                <td>{{ $rome($o->created_at) }}</td>
                                <td>{{ $o->buyer?->name }}</td>
                                <td class="num">{{ $eur($o->total) }}</td>
                                <td class="num">{{ $eur($o->pv_total) }}</td>
                                <td class="num">{{ (int) $o->created_at->diffInDays(now()) }}</td>
                                <td>
                                    <div class="pt-actions">
                                        <x-filament::button size="xs" color="success" wire:click="mountAction('confirm', { order: {{ $o->id }} })">Conferma in {{ $p->code }}</x-filament::button>
                                        <x-filament::button size="xs" color="warning" wire:click="mountAction('defer', { order: {{ $o->id }} })">Sposta a {{ $d['next'] }}</x-filament::button>
                                        <x-filament::button size="xs" color="danger" outlined wire:click="mountAction('cancel', { order: {{ $o->id }} })">Annulla</x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
