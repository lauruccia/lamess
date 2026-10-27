@php
    $eur0 = fn (float|int $v): string => '€ '.number_format((float) $v, 0, ',', '.');
    $eur2 = fn (float|int $v): string => '€ '.number_format((float) $v, 2, ',', '.');
    $w = $d['window'];
    $from = $w['start']->locale('it')->translatedFormat('j M Y');
    $to = $w['end']->locale('it')->translatedFormat('j M Y');
    $chart = $d['chart'];
    $months = $d['monthly'];
    $maxOf = fn (array $rows): float => max(1.0, ...array_map(fn ($r) => (float) $r['revenue'], $rows ?: [['revenue' => 1]]));
    $tile = function (string $label, string $value, ?float $delta, bool $hasPrev, string $tone, bool $lowerIsBetter = false) {
        return compact('label', 'value', 'delta', 'hasPrev', 'tone', 'lowerIsBetter');
    };
    $hasPrev = $w['prev_start'] !== null;
    $tiles = [
        $tile('Ordini', number_format($d['tiles']['orders']['value'], 0, ',', '.'), $d['tiles']['orders']['delta'], $hasPrev, 'a'),
        $tile('Ricavo (IVA incl.)', $eur0($d['tiles']['revenue']['value']), $d['tiles']['revenue']['delta'], $hasPrev, 'b'),
        $tile('Ordine medio', $eur0($d['tiles']['avg']['value']), $d['tiles']['avg']['delta'], $hasPrev, 'b'),
        $tile('Nuovi clienti', number_format($d['extra']['new_customers']['value'], 0, ',', '.'), $d['extra']['new_customers']['delta'], $hasPrev, 'c'),
        $tile('Resi e rimborsi', $eur0($d['extra']['refunds']['value']), $d['extra']['refunds']['delta'], $hasPrev, 'c', true),
        $tile('Pagato in KMoney', $eur0($d['extra']['kmoney']['value']), $d['extra']['kmoney']['delta'], $hasPrev, 'c'),
    ];
@endphp
<x-filament-panels::page>
    <style>
        .lm-s{--s-gap:1rem;display:grid;gap:var(--s-gap);font-family:var(--lm-font-text,Inter,system-ui,sans-serif)}
        .lm-s *{box-sizing:border-box}
        .lm-s-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem .75rem}
        .lm-s-bar select{padding:.35rem .6rem;border:1px solid var(--lm-border,#BBB4AF);border-radius:.5rem;background:var(--lm-surface,#fff);color:inherit;font-size:.875rem}
        .lm-s-bar button{display:inline-flex;align-items:center;justify-content:center;width:2.1rem;height:2.1rem;border:1px solid var(--lm-border,#BBB4AF);border-radius:.5rem;background:var(--lm-surface,#fff);color:inherit;cursor:pointer;font-size:1rem}
        .lm-s-bar button:disabled{opacity:.35;cursor:not-allowed}
        .lm-s-range{font-size:.85rem;opacity:.75}
        .lm-s-tiles{display:grid;gap:var(--s-gap);grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr))}
        .lm-s-tile{padding:1rem 1.15rem;border-radius:.75rem;border:1px solid var(--lm-border,#BBB4AF);background:var(--lm-surface,#fff)}
        .lm-s-tile[data-tone=a]{background:color-mix(in srgb,var(--lm-chart-2,#4A7A9B) 12%,var(--lm-surface,#fff))}
        .lm-s-tile[data-tone=b]{background:color-mix(in srgb,var(--lm-accent-soft,#E3AA8D) 35%,var(--lm-surface,#fff))}
        .lm-s-tile h3{margin:0;font-size:.82rem;font-weight:600;letter-spacing:.01em}
        .lm-s-tile .v{margin:.35rem 0 .2rem;font-family:var(--lm-font-display,Marcellus,Georgia,serif);font-size:1.9rem;line-height:1.1}
        .lm-s-tile .dl{font-size:.8rem;font-weight:600}
        .lm-s-tile .dl.up{color:var(--lm-ok,#2F6F4F)} .lm-s-tile .dl.down{color:var(--lm-err,#B3261E)} .lm-s-tile .dl.nd{font-weight:400;opacity:.65}
        .lm-s-card{padding:1rem 1.15rem 1.15rem;border-radius:.75rem;border:1px solid var(--lm-border,#BBB4AF);background:var(--lm-surface,#fff);min-width:0}
        .lm-s-card h2{margin:0 0 .6rem;padding-bottom:.45rem;border-bottom:1px solid var(--lm-border,#BBB4AF);font-family:var(--lm-font-display,Marcellus,Georgia,serif);font-size:1.15rem;font-weight:400;color:var(--lm-link,#894C2D)}
        .lm-s-grid{display:grid;gap:var(--s-gap);grid-template-columns:repeat(auto-fit,minmax(min(100%,24rem),1fr))}
        .lm-s-rank{list-style:none;margin:0;padding:0;display:grid;gap:.25rem}
        .lm-s-rank li{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:.75rem;align-items:center;padding:.3rem .5rem;font-size:.85rem;border-radius:.35rem;overflow:hidden}
        .lm-s-rank li::before{content:"";position:absolute;inset:0 auto 0 0;width:var(--w,0%);background:color-mix(in srgb,var(--lm-accent,#C2663A) 16%,transparent);z-index:0}
        .lm-s-rank li>*{position:relative;z-index:1}
        .lm-s-rank .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .lm-s-rank .n small{display:block;opacity:.65;font-size:.72rem}
        .lm-s-rank .c{opacity:.7;font-variant-numeric:tabular-nums;text-align:right;min-width:2.5rem}
        .lm-s-rank .e{font-variant-numeric:tabular-nums;text-align:right;min-width:5.5rem;font-weight:600}
        .lm-s-head{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:.75rem;padding:0 .5rem .25rem;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.65}
        .lm-s-head span:nth-child(n+2){text-align:right}
        .lm-s-empty{margin:0;padding:.75rem .25rem;font-size:.85rem;opacity:.65}
        .lm-s-tree{display:flex;gap:3px;min-height:11rem;border-radius:.5rem;overflow:hidden}
        .lm-s-tree>div{flex:var(--g,1) 1 0;min-width:4.5rem;padding:.55rem .6rem;display:flex;flex-direction:column;justify-content:flex-end;color:#fff;font-size:.8rem;line-height:1.25}
        .lm-s-tree b{font-weight:600} .lm-s-tree span{opacity:.92}
        .lm-s svg text{fill:currentColor;font-size:12px}
        .lm-s .grid-l{stroke:var(--lm-border,#BBB4AF);stroke-opacity:.55;stroke-width:1}
        .lm-s .area{fill:var(--lm-chart-1,#C2663A);fill-opacity:.2}
        .lm-s .line{fill:none;stroke:var(--lm-chart-1,#C2663A);stroke-width:2.5;stroke-linejoin:round}
        .lm-s .dot{fill:var(--lm-surface,#fff);stroke:var(--lm-chart-1,#C2663A);stroke-width:2.5}
        .lm-s-sub{display:flex;flex-wrap:wrap;gap:.4rem 1.25rem;font-size:.85rem;margin-top:.6rem;opacity:.85}
        .lm-s-hl{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));margin-bottom:1rem}
        .lm-s-hl div{padding:.7rem .85rem;border-radius:.6rem;background:color-mix(in srgb,var(--lm-accent-soft,#E3AA8D) 28%,var(--lm-surface,#fff));border:1px solid var(--lm-border,#BBB4AF)}
        .lm-s-hl small{display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.7}
        .lm-s-hl b{display:block;margin:.15rem 0;font-size:1rem}
        .lm-s-hl span{font-size:.8rem;opacity:.8}
        .lm-s-tw{overflow-x:auto}
        .lm-s-t{width:100%;border-collapse:collapse;font-size:.85rem;font-variant-numeric:tabular-nums}
        .lm-s-t th{padding:.35rem .6rem;text-align:right;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.7;white-space:nowrap;border-bottom:1px solid var(--lm-border,#BBB4AF)}
        .lm-s-t td{padding:.4rem .6rem;text-align:right;white-space:nowrap;border-bottom:1px solid color-mix(in srgb,var(--lm-border,#BBB4AF) 45%,transparent)}
        .lm-s-t th:first-child,.lm-s-t td:first-child,.lm-s-t th:nth-child(2),.lm-s-t td:nth-child(2){text-align:left}
        .lm-s-t .pos{opacity:.55;width:2rem}
        .lm-s-t .dim{opacity:.65}
        .lm-s-t small{display:block;opacity:.65;font-size:.72rem}
        .lm-s-t .sh{display:inline-block;vertical-align:middle;width:3.5rem;height:.4rem;margin-right:.4rem;border-radius:99px;background:color-mix(in srgb,var(--lm-border,#BBB4AF) 45%,transparent);overflow:hidden}
        .lm-s-t .sh i{display:block;height:100%;width:var(--w,0%);background:var(--lm-accent,#C2663A)}
        .lm-s-t .up{color:var(--lm-ok,#2F6F4F);font-weight:600} .lm-s-t .down{color:var(--lm-err,#B3261E);font-weight:600}
        .lm-s-note{font-size:.78rem;opacity:.65;margin:0}
    </style>

    <div class="lm-s">
        <div class="lm-s-bar" role="group" aria-label="Periodo">
            <select wire:model.live="days" aria-label="Durata del periodo">
                @foreach($ranges as $k => $label)<option value="{{ $k }}" @selected($k === $days)>{{ $label }}</option>@endforeach
            </select>
            <button type="button" wire:click="previous" @disabled($days === 0) aria-label="Periodo precedente" title="Periodo precedente">‹</button>
            <button type="button" wire:click="next" @disabled($days === 0 || $offset === 0) aria-label="Periodo successivo" title="Periodo successivo">›</button>
            <span class="lm-s-range">{{ $from }} – {{ $to }}@if($hasPrev) · confronto con i {{ $days }} giorni prima @endif</span>
        </div>

        <div class="lm-s-tiles">
            @foreach($tiles as $t)
                <div class="lm-s-tile" data-tone="{{ $t['tone'] }}">
                    <h3>{{ $t['label'] }}</h3>
                    <p class="v">{{ $t['value'] }}</p>
                    @if(! $t['hasPrev'])
                        <span class="dl nd">—</span>
                    @elseif($t['delta'] === null)
                        <span class="dl nd">nessun dato nel periodo precedente</span>
                    @else
                        @php($good = $t['lowerIsBetter'] ? $t['delta'] <= 0 : $t['delta'] >= 0)
                        <span class="dl {{ $good ? 'up' : 'down' }}">{{ $t['delta'] >= 0 ? '↑' : '↓' }} {{ number_format(abs($t['delta']), 1, ',', '.') }}% <span style="font-weight:400;opacity:.7">dal periodo precedente</span></span>
                    @endif
                </div>
            @endforeach
            <div class="lm-s-tile" data-tone="c">
                <h3>Abbonamenti</h3>
                <p class="v">{{ number_format($d['extra']['subscriptions_active'], 0, ',', '.') }}</p>
                <span class="dl nd">attivi · {{ number_format($d['extra']['subscriptions_paused'], 0, ',', '.') }} in pausa (oggi)</span>
            </div>
        </div>

        <section class="lm-s-card" aria-labelledby="s-monthly">
            <h2 id="s-monthly">Vendite mensili</h2>
            @if(array_sum(array_column($months, 'orders')) === 0)
                <p class="lm-s-empty">Nessun ordine pagato nel periodo.</p>
            @else
                <svg viewBox="0 0 {{ $chart['w'] }} {{ $chart['h'] }}" width="100%" role="img" aria-label="Ricavo mensile in euro, IVA inclusa" style="max-height:20rem">
                    @foreach($chart['ticks'] as $tk)
                        <line class="grid-l" x1="{{ $chart['left'] }}" x2="{{ $chart['w'] - 24 }}" y1="{{ $tk['y'] }}" y2="{{ $tk['y'] }}"/>
                        <text x="{{ $chart['left'] - 8 }}" y="{{ $tk['y'] + 4 }}" text-anchor="end">{{ number_format($tk['value'], 0, ',', '.') }}</text>
                    @endforeach
                    <path class="area" d="{{ $chart['area'] }}"/>
                    <path class="line" d="{{ $chart['line'] }}"/>
                    @foreach($chart['points'] as $i => $p)
                        <circle class="dot" cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4.5"><title>{{ $months[$i]['label'] }}: {{ $eur2($months[$i]['revenue']) }} · {{ $months[$i]['orders'] }} ordini</title></circle>
                        <text x="{{ $p['x'] }}" y="{{ $chart['h'] - 12 }}" text-anchor="middle">{{ $months[$i]['label'] }}</text>
                        @if(count($months) <= 8)
                            <text x="{{ $p['x'] }}" y="{{ max(12, $p['y'] - 10) }}" text-anchor="middle" style="font-weight:600">{{ $eur0($months[$i]['revenue']) }}</text>
                        @endif
                    @endforeach
                </svg>
            @endif
        </section>

        <div class="lm-s-grid">
            <section class="lm-s-card" aria-labelledby="s-orders">
                <h2 id="s-orders">Ordini di vendita migliori</h2>
                @include('filament.pages.partials.sales-orders-list', ['rows' => $d['top_orders'], 'eur2' => $eur2])
            </section>
        </div>

        <div class="lm-s-grid">
            <section class="lm-s-card" aria-labelledby="s-products">
                <h2 id="s-products">Prodotti principali</h2>
                @include('filament.pages.partials.sales-rank', ['rows' => $d['top_products'], 'eur2' => $eur2, 'countLabel' => 'Ordini', 'label' => 'Prodotto'])
            </section>
            <section class="lm-s-card" aria-labelledby="s-customers">
                <h2 id="s-customers">Clienti migliori</h2>
                @include('filament.pages.partials.sales-customers', ['rows' => $d['top_customers'], 'eur2' => $eur2])
            </section>
        </div>

        <section class="lm-s-card" aria-labelledby="s-cats">
            <h2 id="s-cats">Categorie principali</h2>
            @if($d['categories'])
                <div class="lm-s-tree">
                    @foreach($d['categories'] as $i => $c)
                        <div style="--g:{{ max($c['share'], 6) }};background:var(--lm-chart-{{ ($i % 6) + 1 }},#80776D)"><b>{{ $c['label'] }}</b><span>{{ $eur0($c['revenue']) }} · {{ number_format($c['share'], 1, ',', '.') }}%</span></div>
                    @endforeach
                </div>
            @else
                <p class="lm-s-empty">Nessuna vendita nel periodo.</p>
            @endif
        </section>

        @include('filament.pages.partials.sales-sellers', ['d' => $d, 'eur2' => $eur2, 'eur0' => $eur0])

        <div class="lm-s-grid">
            <section class="lm-s-card" aria-labelledby="s-channels">
                <h2 id="s-channels">Canali di vendita</h2>
                @include('filament.pages.partials.sales-rank', ['rows' => $d['channels'], 'eur2' => $eur2, 'countLabel' => 'Ordini', 'label' => 'Canale'])
            </section>
            <section class="lm-s-card" aria-labelledby="s-pay">
                <h2 id="s-pay">Metodi di pagamento</h2>
                @include('filament.pages.partials.sales-rank', ['rows' => $d['payments'], 'eur2' => $eur2, 'countLabel' => 'Ordini', 'label' => 'Metodo'])
            </section>
            <section class="lm-s-card" aria-labelledby="s-countries">
                <h2 id="s-countries">Paesi</h2>
                @include('filament.pages.partials.sales-rank', ['rows' => $d['countries'], 'eur2' => $eur2, 'countLabel' => 'Ordini', 'label' => 'Paese'])
            </section>
        </div>

        <p class="lm-s-note">Importi IVA inclusa. «Ordini» = pagati e non resi del tutto; gli ordini in attesa di pagamento e gli annullati non contano. L'incaricato di un ordine è quello indicato sull'ordine, altrimenti quello assegnato al cliente; senza nessuno dei due è «vendita diretta». Il periodo è confrontato con quello di pari durata immediatamente precedente.</p>
    </div>
</x-filament-panels::page>
