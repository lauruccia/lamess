<x-filament-panels::page>
    <style>
        .sim { --s-card:#fff; --s-line:#BBB4AF; --s-muted:#6b635d; --s-acc:#C2663A; --s-cream:#FCF6F3; --s-ink:#13110E; --s-up:#1f7a3d; --s-down:#b3261e; --s-new:#6b4fa3; --s-move:#a06a00; color:var(--s-ink); display:flex; flex-direction:column; gap:.8rem; }
        .dark .sim { --s-card:#201C18; --s-line:#4a423b; --s-muted:#a79d94; --s-cream:#1b1814; --s-ink:#F3ECE7; --s-up:#59c27d; --s-down:#f2857e; --s-new:#b79cf0; --s-move:#e3b04d; }
        .sim .box { background:var(--s-card); border:1px solid var(--s-line); border-radius:.75rem; padding:1rem 1.25rem; }
        .sim h3 { font-size:1rem; font-weight:700; margin:0 0 .6rem; }
        .sim .muted { color:var(--s-muted); font-size:.8rem; }
        .sim input, .sim select { font:inherit; color:inherit; background:var(--s-cream); border:1px solid var(--s-line); border-radius:.5rem; padding:.3rem .55rem; }
        .sim input.target { width:19rem; max-width:100%; } .sim input.num { width:5rem; } .sim input.eur { width:6.5rem; }
        .sim button { font:inherit; font-weight:600; cursor:pointer; background:var(--s-cream); border:1px solid var(--s-line); border-radius:.5rem; padding:.35rem .8rem; color:inherit; }
        .sim button:hover { border-color:var(--s-acc); } .sim button.pri { background:var(--s-ink); color:var(--s-card); border-color:var(--s-ink); }
        .sim .row { display:flex; flex-wrap:wrap; gap:.45rem .6rem; align-items:center; padding:.5rem 0; border-top:1px solid var(--s-line); } .sim .row:first-of-type { border-top:0; }
        .sim .row .n { font-weight:700; color:var(--s-muted); width:1.4rem; }
        .sim .bar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
        .sim table { width:100%; border-collapse:collapse; font-size:.86rem; } .sim th { text-align:left; padding:.45rem .6rem; border-bottom:1px solid var(--s-line); color:var(--s-muted); font-weight:600; white-space:nowrap; }
        .sim td { padding:.45rem .6rem; border-bottom:1px solid color-mix(in srgb, var(--s-line) 40%, transparent); vertical-align:top; } .sim .scroll { overflow:auto; }
        .sim .up { color:var(--s-up); font-weight:700; } .sim .down { color:var(--s-down); font-weight:700; } .sim .newc { color:var(--s-new); font-weight:700; }
        .sim tr.hl td { background:color-mix(in srgb, var(--s-acc) 9%, transparent); }
        .sim .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(10rem,1fr)); gap:.6rem; }
        .sim .kpi { background:var(--s-cream); border:1px solid var(--s-line); border-radius:.6rem; padding:.55rem .8rem; } .sim .kpi b { display:block; font-size:1.25rem; }
        .sim .banner { background:color-mix(in srgb, var(--s-acc) 12%, var(--s-card)); border:1px solid var(--s-acc); border-radius:.75rem; padding:.7rem 1rem; font-size:.88rem; }
        .sim .err { background:color-mix(in srgb, var(--s-down) 12%, var(--s-card)); border:1px solid var(--s-down); border-radius:.75rem; padding:.7rem 1rem; }
        .sim .warn { background:color-mix(in srgb, var(--s-move) 14%, var(--s-card)); border:1px solid var(--s-move); border-radius:.75rem; padding:.6rem 1rem; font-size:.86rem; }
        .sim .tr { font-size:.88rem; line-height:1.5; }
        .sim .tr .node { display:flex; flex-wrap:wrap; align-items:center; gap:.2rem .55rem; padding-top:.2rem; padding-bottom:.2rem; border-bottom:1px solid color-mix(in srgb, var(--s-line) 25%, transparent); }
        .sim .tr .node.chg { background:color-mix(in srgb, var(--s-acc) 9%, transparent); }
        .sim .tr .tg { width:1.3rem; padding:0; text-align:center; border:0; background:none; }
        .sim .tr .dot { width:.75rem; height:.75rem; border-radius:50%; border:1px solid var(--s-line); flex:none; }
        .sim .tr .acts { margin-left:auto; display:flex; gap:.25rem; } .sim .tr .acts button { font-size:.72rem; padding:.1rem .45rem; font-weight:500; }
        .sim .tip { position:relative; cursor:help; border-bottom:1px dotted currentColor; outline:none; }
        .sim .tip .tipbox { display:none; position:absolute; left:0; top:calc(100% + .3rem); z-index:50; width:min(32rem,82vw); background:var(--s-card); color:var(--s-ink); border:1px solid var(--s-line); border-radius:.6rem; padding:.6rem .8rem; box-shadow:0 8px 24px rgba(0,0,0,.2); font-weight:400; font-size:.8rem; line-height:1.45; white-space:normal; text-align:left; }
        .sim .tip:hover .tipbox, .sim .tip:focus .tipbox { display:block; } .sim .tip .tipbox ul { margin:.3rem 0 0 1rem; padding:0; list-style:disc; } .sim .tip .tipbox li { margin:.2rem 0; }
        .sim .cv { position:relative; background:var(--s-cream); border:1px solid var(--s-line); border-radius:.75rem; overflow:hidden; cursor:grab; touch-action:none; height:68vh; min-height:26rem; background-image:radial-gradient(var(--s-line) .6px, transparent .6px); background-size:22px 22px; }
        .sim .cv.drag { cursor:grabbing; } .sim .cv svg { width:100%; height:100%; display:block; }
        .sim .zoom { position:absolute; left:.6rem; top:.6rem; display:flex; gap:.25rem; align-items:center; background:var(--s-card); border:1px solid var(--s-line); border-radius:.6rem; padding:.2rem; z-index:5; flex-wrap:wrap; }
        .sim .zoom span { min-width:2.6rem; text-align:center; color:var(--s-muted); font-weight:600; font-size:.8rem; }
        .sim .hint { position:absolute; right:.8rem; top:.8rem; color:var(--s-muted); font-size:.75rem; z-index:5; }
        .sim .leg { display:flex; flex-wrap:wrap; gap:.3rem 1.2rem; justify-content:center; font-size:.8rem; margin-top:.5rem; } .sim .leg i { display:inline-block; width:.7rem; height:.7rem; border-radius:.2rem; margin-right:.35rem; vertical-align:-1px; }
        .sim .detail { margin-top:.6rem; border:1px solid var(--s-acc); background:color-mix(in srgb, var(--s-acc) 7%, var(--s-card)); border-radius:.7rem; padding:.7rem .9rem; font-size:.86rem; }
        .sim .nd { cursor:pointer; } .sim .nd rect.b { stroke-width:1.5; filter:drop-shadow(0 1px 2px rgba(0,0,0,.14)); } .sim .nd.chg rect.b { stroke:var(--s-acc) !important; stroke-width:3; } .sim .nd.sel rect.b { stroke:var(--s-ink) !important; stroke-width:3.5; }
        .sim .nd text { font-family:inherit; pointer-events:none; } .sim .tg2 { cursor:pointer; } .sim .e { fill:none; stroke:var(--s-line); stroke-width:1.4; }
        .sim .lv-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; } .sim .lv-table td { vertical-align:middle; padding:.35rem .6rem; } .sim .lv-table .num { text-align:right; }
        .sim .lv-table tr.chg td { background:color-mix(in srgb, var(--s-acc) 9%, transparent); } .sim .lv-row-0 td { background:color-mix(in srgb, var(--s-acc) 6%, transparent); }
        .sim .lv-name { display:flex; align-items:center; flex-wrap:wrap; min-height:1.5rem; gap:.1rem 0; }
        .sim .lv-guide { flex:none; display:inline-block; width:1.5rem; height:1.5rem; border-left:1px dashed var(--s-line); } .sim .lv-guide:first-child { border-left:0; }
        .sim .lv-elbow { flex:none; display:inline-block; width:.9rem; height:.8rem; margin-right:.4rem; border-left:1px solid var(--s-line); border-bottom:1px solid var(--s-line); transform:translateY(-.3rem); }
        .sim .lv-tg { flex:none; width:1.4rem; height:1.4rem; margin-right:.4rem; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:.35rem; font-size:.8rem; line-height:1; } .sim .lv-tg-sp { flex:none; display:inline-block; width:1.4rem; margin-right:.4rem; }
        .sim .lv-code { margin-left:.5rem; font-size:.75rem; color:var(--s-muted); }
        .sim .lv-level { display:inline-block; min-width:1.7rem; text-align:center; padding:.05rem .4rem; border-radius:.4rem; background:color-mix(in srgb, var(--s-line) 35%, transparent); font-size:.75rem; font-weight:700; }
        .sim [x-cloak] { display:none !important; }
        .sim .chip { display:inline-block; font-size:.7rem; font-weight:700; padding:.05rem .45rem; border-radius:99px; border:1px solid currentColor; margin-left:.35rem; vertical-align:1px; }
    </style>

    @php
        $eur = static fn ($v): string => number_format((float) (string) $v, 2, ',', '.');
        $signed = static fn ($v): string => ((float) (string) $v > 0 ? '+' : '').number_format((float) (string) $v, 2, ',', '.');
        $cls = static fn ($v): string => (float) (string) $v > 0 ? 'up' : ((float) (string) $v < 0 ? 'down' : '');
        $bonusNames = ['direct' => 'Compensi diretti', 'indirect' => 'Compensi indiretti', 'indirect_ext' => 'Indiretti estesi', 'structure' => 'Bonus di Struttura'];
    @endphp

    <div class="sim">
        <div class="banner"><strong>Solo simulazione.</strong> Le modifiche che inserisci qui esistono soltanto in questa pagina: nessun cliente, ordine, agente, qualifica o compenso viene scritto nel database. Il calcolo usa lo stesso motore ufficiale del piano Incaricati, prima e dopo le modifiche.</div>

        @if(! $version)
            <div class="box"><strong>Non c'è ancora una versione del piano Incaricati.</strong><p class="muted" style="margin:.35rem 0 0">Creala da Piano compensi → «Crea bozza Piano Incaricati».</p></div>
        @else
            <div class="box">
                <div class="bar">
                    <label>Dati del periodo:
                        <select wire:model="periodCode">@foreach($periods as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select>
                    </label>
                    <label>Conteggio clienti:
                        <select wire:model="clientCount">
                            <option value="cumulative">Cumulativo (acquisti fino a fine mese)</option>
                            <option value="period">Solo acquisti del mese</option>
                        </select>
                    </label>
                    <label><input type="checkbox" wire:model="newAgents"> gli agenti aggiunti sono nuovi nel mese</label>
                </div>
                <p class="muted" style="margin:.4rem 0 0">Piano: <strong>{{ $version->name }}</strong> ({{ $version->status }}). Le qualifiche sono ricalcolate da zero con clienti e struttura del periodo, come in «Confronto qualifiche». Un ordine rende cliente se raggiunge {{ $minAmount ? $eur($minAmount).' €' : 'l\'importo minimo del piano' }}.</p>
            </div>

            <div class="box">
                <h3>Cosa vuoi cambiare?</h3>
                <div class="bar" style="margin-bottom:.4rem">
                    <button type="button" wire:click="addChange('clients')">+ Aggiungi clienti</button>
                    <button type="button" wire:click="addChange('orders')">+ Aggiungi ordini</button>
                    <button type="button" wire:click="addChange('agents')">+ Aggiungi agenti (numero e livello)</button>
                    <button type="button" wire:click="addChange('columns')">+ Aggiungi colonne (da {{ $columnClients }} clienti)</button>
                    <button type="button" wire:click="addChange('move')">+ Sposta nell'albero</button>
                </div>
                <datalist id="sim-incaricati">@foreach($options as $o)<option value="{{ $o }}"></option>@endforeach</datalist>
                @forelse($changes as $i => $c)
                    <div class="row" wire:key="change-{{ $i }}">
                        <span class="n">{{ $i + 1 }}</span>
                        <strong>{{ ['clients' => 'Aggiungi clienti', 'orders' => 'Aggiungi ordini', 'agents' => 'Aggiungi agenti', 'columns' => 'Aggiungi colonne', 'move' => 'Sposta nell\'albero'][$c['type']] ?? $c['type'] }}</strong>
                        @if($c['type'] === 'move')
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.target" placeholder="Incaricato da spostare (codice o nome)" aria-label="Incaricato da spostare">
                            <span>sotto lo sponsor</span>
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.sponsor" placeholder="Nuovo sponsor (codice o nome)" aria-label="Nuovo sponsor">
                        @elseif($c['type'] === 'columns')
                            <input class="num" type="number" min="1" max="10" wire:model="changes.{{ $i }}.count" aria-label="Numero di colonne">
                            <span>colonne, ciascuna con almeno</span>
                            <input class="num" type="number" min="1" max="500" wire:model="changes.{{ $i }}.clients" placeholder="{{ $columnClients }}" aria-label="Clienti per colonna">
                            <span>clienti, sotto</span>
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.target" placeholder="Incaricato (codice o nome)" aria-label="Incaricato">
                        @else
                            <input class="num" type="number" min="1" max="{{ $c['type'] === 'agents' ? 20 : 500 }}" wire:model="changes.{{ $i }}.count" aria-label="Quantità">
                            @if($c['type'] === 'agents')
                                <span>agenti di livello</span>
                                <select wire:model="changes.{{ $i }}.rank" aria-label="Livello degli agenti">@foreach($ranks as $r)<option value="{{ $r['code'] }}">{{ $r['name'] }}</option>@endforeach</select>
                                <span>sotto</span>
                            @else
                                <span>{{ $c['type'] === 'clients' ? 'clienti, ciascuno con un ordine da €' : 'ordini da €' }}</span>
                                <input class="eur" wire:model="changes.{{ $i }}.amount" placeholder="{{ $minAmount ? $eur($minAmount) : '150,00' }}" aria-label="Importo dell'ordine">
                                <span>a</span>
                            @endif
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.target" placeholder="Incaricato (codice o nome)" aria-label="Incaricato">
                        @endif
                        <button type="button" wire:click="removeChange({{ $i }})" aria-label="Togli la modifica {{ $i + 1 }}">✕</button>
                    </div>
                @empty
                    <p class="muted" style="margin:.2rem 0">Scegli un'azione qui sopra, oppure usa i pulsanti accanto a ogni incaricato nell'albero qui sotto.</p>
                @endforelse
                <div class="bar" style="margin-top:.7rem">
                    <button type="button" class="pri" wire:click="simulate" wire:loading.attr="disabled" wire:target="simulate,addChangeFor">Simula</button>
                    <button type="button" wire:click="resetSimulation">Azzera tutto</button>
                    <span class="muted" wire:loading wire:target="simulate,addChangeFor">Calcolo in corso…</span>
                </div>
                <p class="muted" style="margin:.5rem 0 0">«Aggiungi agenti» crea agenti virtuali del livello scelto con tutto ciò che serve per raggiungerlo (clienti, Pearl e linee richieste dal piano). «Aggiungi colonne» crea altrettante linee dirette sotto l'incaricato, ciascuna con un agente Pearl e almeno i clienti indicati ({{ $columnClients }} se lasci vuoto, il minimo di colonna del piano): servono per le qualifiche che richiedono colonne. «Aggiungi ordini» usa i clienti che l'incaricato ha già, senza aumentarne il numero. Le modifiche si applicano nell'ordine in cui le scrivi.</p>
            </div>

            @if($error)<div class="err" role="alert">{{ $error }}</div>@endif

            @if($result)
                @php
                    $s = $result['summary'];
                    $ups = array_values(array_filter($result['rows'], static fn (array $r) => $r['dir'] > 0));
                    $downs = array_values(array_filter($result['rows'], static fn (array $r) => $r['dir'] < 0));
                    $earners = array_values(array_filter($result['rows'], static fn (array $r) => (float) $r['earned_diff'] != 0));
                    usort($earners, static fn (array $x, array $y) => abs((float) $y['earned_diff']) <=> abs((float) $x['earned_diff']));
                    $moreEarners = max(0, count($earners) - 10);
                    $earners = array_slice($earners, 0, 10);
                @endphp
                <div class="box">
                    <h3>Risultato per il periodo {{ $result['period'] }}</h3>
                    <div class="kpis">
                        <div class="kpi"><span class="muted">Modifiche simulate</span><b>{{ $s['clients'] }} clienti · {{ $s['orders'] }} ordini</b><span class="muted">{{ $s['virtual_agents'] }} agenti simulati · {{ $s['moves'] }} spostamenti</span></div>
                        <div class="kpi"><span class="muted">Qualifiche che salgono</span>
                            <b class="tip {{ $s['rank_up'] ? 'up' : '' }}" tabindex="0">{{ $s['rank_up'] }}
                                <span class="tipbox" role="tooltip">
                                    @if($ups)<strong>Chi sale di qualifica</strong><ul>@foreach($ups as $r)<li>{{ $r['label'] }}: {{ $r['rank_before'] }} → <span class="up">{{ $r['rank_after'] }} ▲</span>@if($r['clients_before'] !== $r['clients_after']) (clienti {{ $r['clients_before'] }} → {{ $r['clients_after'] }})@endif</li>@endforeach</ul>@else Nessun incaricato sale di qualifica.@endif
                                </span>
                            </b>
                            <span class="muted">@if($ups){{ implode(', ', array_map(static fn (array $r) => explode(' — ', $r['label'])[0], array_slice($ups, 0, 4))) }}{{ count($ups) > 4 ? ' e altri '.(count($ups) - 4) : '' }}@else nessuno @endif</span></div>
                        <div class="kpi"><span class="muted">Qualifiche che scendono</span>
                            <b class="tip {{ $s['rank_down'] ? 'down' : '' }}" tabindex="0">{{ $s['rank_down'] }}
                                <span class="tipbox" role="tooltip">
                                    @if($downs)<strong>Chi scende di qualifica</strong><ul>@foreach($downs as $r)<li>{{ $r['label'] }}: {{ $r['rank_before'] }} → <span class="down">{{ $r['rank_after'] }} ▼</span>@if($r['clients_before'] !== $r['clients_after']) (clienti {{ $r['clients_before'] }} → {{ $r['clients_after'] }})@endif</li>@endforeach</ul>@else Nessun incaricato scende di qualifica.@endif
                                </span>
                            </b>
                            <span class="muted">@if($downs){{ implode(', ', array_map(static fn (array $r) => explode(' — ', $r['label'])[0], array_slice($downs, 0, 4))) }}{{ count($downs) > 4 ? ' e altri '.(count($downs) - 4) : '' }}@else nessuno @endif</span></div>
                        <div class="kpi"><span class="muted">Provvigioni degli incaricati nel mese</span>
                            <b class="tip" tabindex="0">{{ $eur($s['total_before']) }} € → {{ $eur($s['total_after']) }} €
                                <span class="tipbox" role="tooltip" style="left:auto;right:0">
                                    <strong>Come sono suddivise le provvigioni del mese</strong>
                                    @if($result['totals'])
                                        <ul>@foreach($result['totals'] as $t)<li>{{ $bonusNames[$t['bonus']] ?? $t['bonus'] }}: {{ $eur($t['before']) }} € → {{ $eur($t['after']) }} € (<span class="{{ $cls($t['diff']) }}">{{ $signed($t['diff']) }} €</span>)</li>@endforeach</ul>
                                    @endif
                                    @if($earners)
                                        <strong style="display:block;margin-top:.5rem">A chi cambiano (variazione maggiore)</strong>
                                        <ul>@foreach($earners as $r)<li>{{ $r['label'] }}: {{ $eur($r['earned_before']) }} € → {{ $eur($r['earned_after']) }} € (<span class="{{ $cls($r['earned_diff']) }}">{{ $signed($r['earned_diff']) }} €</span>)</li>@endforeach @if($moreEarners)<li>… e altri {{ $moreEarners }}</li>@endif</ul>
                                    @endif
                                </span>
                            </b>
                            <span class="{{ $cls($s['total_diff']) }}">{{ $signed($s['total_diff']) }} €</span>
                            @if($s['virtual_agents'] > 0)<div class="muted" style="font-size:.78rem">di cui agenti simulati <strong>{{ $signed($s['total_virtual']) }} €</strong> · incaricati reali <strong class="{{ $cls($s['total_real_diff']) }}">{{ $signed($s['total_real_diff']) }} €</strong></div>@endif</div>
                    </div>
                    <p class="muted" style="margin:.5rem 0 0">Passa il mouse (o tocca) sui numeri per vedere chi sale o scende di qualifica e a chi cambiano le provvigioni.</p>

                    @php
                        $bonusInfo = [
                            'direct' => ['A chi', "All'incaricato che ha il cliente.", "Percentuale sul V.A.P. (la quota del prezzo IVA inclusa che dà diritto a provvigione, di norma il 66,67%) degli ordini dei suoi clienti. La percentuale sale a scaglioni col numero dei suoi clienti personali."],
                            'indirect' => ['A chi', "Agli incaricati sopra (upline), fino al 5° livello.", "Percentuale del livello sul V.A.P. delle vendite dei clienti degli incaricati sotto di lui. La matura solo se ha i clienti personali e i Pearl al 1° livello richiesti da quel livello."],
                            'indirect_ext' => ['A chi', "A chi ha qualifica Ruby, Emerald o Diamond.", "1% oltre il 5° livello, fino al successivo incaricato con qualifica uguale o più alta."],
                            'structure' => ['A chi', "All'upline di chi porta 3 clienti entro 30 giorni dall'ingresso in rete.", "Importo fisso, non dipende dalle vendite: la differenza tra il bonus della propria qualifica (da Crystal a Diamond) e il più alto già riconosciuto più in basso."],
                        ];
                    @endphp
                    <h3 style="margin:1rem 0 .3rem">Che cosa sono queste provvigioni e come maturano</h3>
                    <p class="muted" style="margin:0 0 .5rem">Sono le provvigioni <strong>lorde</strong> che il Piano Incaricati riconosce agli incaricati per le vendite del mese (compresi gli importi eventualmente trattenuti). Non sono il fatturato né i bonifici: ritenute e trattenute non sono considerate. Maturano sul V.A.P. degli ordini pagati dei clienti, tranne il Bonus di Struttura che è un importo fisso. <strong>Il totale comprende anche le provvigioni degli agenti simulati</strong> (e dei loro clienti), non solo quelle dell'incaricato a cui hai aggiunto clienti: qui sotto trovi la quota degli agenti simulati separata da quella degli incaricati reali.</p>
                    <div class="scroll" x-data="{ open: null }"><table>
                        <tr><th>Tipo</th><th>Chi le riceve</th><th>Come maturano</th><th>Prima</th><th>Dopo</th><th>Differenza</th></tr>
                        @foreach($result['totals'] as $t)
                            @php $bd = $result['breakdown'][$t['bonus']] ?? ['rows' => [], 'more' => 0]; @endphp
                            <tr style="cursor:pointer" @click="open = open === '{{ $t['bonus'] }}' ? null : '{{ $t['bonus'] }}'" @keydown.enter="open = open === '{{ $t['bonus'] }}' ? null : '{{ $t['bonus'] }}'" tabindex="0" role="button" :aria-expanded="open === '{{ $t['bonus'] }}'" title="Clicca per vedere chi le matura e da quali vendite">
                                <td><span x-text="open === '{{ $t['bonus'] }}' ? '▾' : '▸'">▸</span> <strong>{{ $bonusNames[$t['bonus']] ?? $t['bonus'] }}</strong><div class="muted" style="font-size:.74rem">{{ count($bd['rows']) + $bd['more'] }} {{ count($bd['rows']) + $bd['more'] === 1 ? 'incaricato' : 'incaricati' }} · clicca per il dettaglio</div></td>
                                <td>{{ $bonusInfo[$t['bonus']][1] ?? '—' }}</td>
                                <td class="muted">{{ $bonusInfo[$t['bonus']][2] ?? '' }}</td>
                                <td>{{ $eur($t['before']) }} €</td><td>{{ $eur($t['after']) }} €</td><td class="{{ $cls($t['diff']) }}">{{ $signed($t['diff']) }} €</td>
                            </tr>
                            <tr x-show="open === '{{ $t['bonus'] }}'" x-cloak>
                                <td colspan="6" style="background:var(--s-cream)">
                                    <div class="muted" style="margin-bottom:.4rem">Chi matura <strong>{{ $bonusNames[$t['bonus']] ?? $t['bonus'] }}</strong> (dal maggiore al minore per variazione). Apri un incaricato per vedere da quali vendite nascono gli importi.</div>
                                    @foreach($bd['rows'] as $person)
                                        <details style="border-top:1px solid var(--s-line);padding:.3rem 0" @if($loop->first) open @endif>
                                            <summary style="cursor:pointer">
                                                <strong>{{ $person['label'] }}</strong>
                                                @if($person['virtual'])<span class="chip newc">simulato</span>@endif
                                                @if($person['rank'])<span class="muted"> · {{ $person['rank'] }}</span>@endif
                                                @if($person['clients'] !== null)<span class="muted"> · clienti {{ $person['clients'] }}</span>@endif
                                                — {{ $eur($person['before']) }} € → <strong>{{ $eur($person['after']) }} €</strong> <span class="{{ $cls($person['diff']) }}">({{ $signed($person['diff']) }} €)</span>
                                            </summary>
                                            <div class="scroll"><table style="margin:.3rem 0 .5rem">
                                                <tr><th>Da</th><th>Livello</th><th>Vendite</th><th>Ordini</th><th>Base V.A.P.</th><th>Aliquota</th><th>Matura</th><th>Prima</th><th>Note</th></tr>
                                                @foreach($person['lines'] as $ln)
                                                    <tr>
                                                        <td>{{ $t['bonus'] === 'structure' ? 'Bonus per '.$ln['source'] : ($ln['own'] ? 'Vendite ai suoi clienti' : 'Vendite dei clienti di '.$ln['source']) }}</td>
                                                        <td>{{ $ln['level'] !== null ? $ln['level'] : '—' }}</td>
                                                        <td>{{ $ln['sales'] }}</td>
                                                        <td>{{ $t['bonus'] === 'structure' ? '—' : $eur($ln['orders']).' €' }}</td>
                                                        <td>{{ $t['bonus'] === 'structure' ? '—' : $eur($ln['base']).' €' }}</td>
                                                        <td>{{ $ln['rate'] ?? '—' }}@if($ln['rate_before'])<div class="muted">prima {{ $ln['rate_before'] }}</div>@endif</td>
                                                        <td><strong>{{ $eur($ln['after']) }} €</strong> <span class="{{ $cls($ln['diff']) }}">({{ $signed($ln['diff']) }})</span></td>
                                                        <td>{{ $eur($ln['before']) }} €</td>
                                                        <td class="muted">{{ $ln['detail'] }}@if($ln['held']) · trattenuto @endif</td>
                                                    </tr>
                                                @endforeach
                                            </table></div>
                                        </details>
                                    @endforeach
                                    @if($bd['more'] > 0)<div class="muted">… e altri {{ $bd['more'] }} incaricati con importi minori.</div>@endif
                                </td>
                            </tr>
                        @endforeach
                        @if($s['virtual_agents'] > 0)
                            <tr><td colspan="3" class="muted">di cui agenti simulati (nuovi, con i loro clienti)</td><td class="muted">0,00 €</td><td class="muted">{{ $eur($s['total_virtual']) }} €</td><td class="muted">{{ $signed($s['total_virtual']) }} €</td></tr>
                            <tr><td colspan="3" class="muted">di cui incaricati reali (differenza)</td><td class="muted" colspan="2"></td><td class="{{ $cls($s['total_real_diff']) }}">{{ $signed($s['total_real_diff']) }} €</td></tr>
                        @endif
                        <tr><td colspan="3"><strong>Totale provvigioni del mese</strong></td><td><strong>{{ $eur($s['total_before']) }} €</strong></td><td><strong>{{ $eur($s['total_after']) }} €</strong></td><td class="{{ $cls($s['total_diff']) }}"><strong>{{ $signed($s['total_diff']) }} €</strong></td></tr>
                    </table></div>
                    <p class="muted" style="margin:.4rem 0 0"><strong>Clicca su una riga della tabella</strong> (Compensi diretti, indiretti…) per vedere chi matura quegli importi, con qualifica, clienti e da quali vendite nascono (livello, base V.A.P., aliquota, requisiti). Anche la variazione accanto a ogni incaricato nella rete ha il suo tooltip.</p>
                </div>

                @foreach($result['warnings'] as $w)<div class="warn" role="status">{{ $w }}</div>@endforeach
            @endif

            @php $treeRows = $result['tree'] ?? $baseTree; @endphp
            @if($treeRows)
                @php
                    $parents = [];
                    $hasKids = [];
                    $directs = [];
                    foreach ($treeRows as $t) {
                        $parents[$t['id']] = $t['parent'];
                        if ($t['parent'] !== null) { $hasKids[$t['parent']] = true; $directs[$t['parent']] = ($directs[$t['parent']] ?? 0) + 1; }
                    }
                    $js = static fn ($v) => \Illuminate\Support\Js::from($v);
                @endphp
                <div class="box" wire:key="tree-{{ md5(json_encode($treeRows)) }}"
                     x-data="{ closed: {}, only: false, parents: @js($parents), kids: @js(array_keys($hasKids)),
                               view: (() => { try { return localStorage.getItem('simTreeView2') === 'levels' ? 'levels' : 'tree'; } catch (e) { return 'tree'; } })(),
                               setView(v) { this.view = v; try { localStorage.setItem('simTreeView2', v); } catch (e) {} $dispatch('simview', v); },
                               hidden(id) { for (let p = this.parents[id]; p; p = this.parents[p]) { if (this.closed[p]) { return true; } } return false; },
                               show(id, changed, sub) { return ! this.hidden(id) && (! this.only || changed || sub > 0); },
                               closeAll() { this.kids.forEach(k => this.closed[k] = true); }, openAll() { this.closed = {}; } }">
                    <div class="bar" style="justify-content:space-between">
                        <h3 style="margin:0">{{ $result ? 'Tutta la rete dopo la simulazione' : 'La rete oggi — periodo '.$periodCode }}</h3>
                        <div class="bar">
                            <span role="group" aria-label="Tipo di vista" style="display:inline-flex">
                                <button type="button" :class="view === 'tree' ? 'pri' : ''" style="border-radius:.5rem 0 0 .5rem" @click="setView('tree')" :aria-pressed="view === 'tree'">Vista albero</button>
                                <button type="button" :class="view === 'levels' ? 'pri' : ''" style="border-radius:0 .5rem .5rem 0;margin-left:-1px" @click="setView('levels')" :aria-pressed="view === 'levels'">Elenco per livelli</button>
                            </span>
                            @if($result)<label><input type="checkbox" x-model="only"> solo i rami con cambiamenti</label>@endif
                            <span class="bar" x-show="view === 'levels'">
                                <button type="button" @click="openAll()">Apri tutto</button>
                                <button type="button" @click="closeAll()">Chiudi tutto</button>
                            </span>
                        </div>
                    </div>
                    <p class="muted" style="margin:.3rem 0 .6rem">
                        @if($result)
                            Ogni incaricato mostra la qualifica <em>prima → dopo</em> (il colore è quello della pietra), i clienti personali e la variazione dei compensi del mese; i riquadri evidenziati sono quelli che cambiano. Clicca un incaricato per vedere cosa gli manca per la qualifica successiva e aggiungere clienti, agenti o spostarlo.
                        @else
                            Stato reale del periodo, senza modifiche. <strong>Vista albero</strong>: il grafico della rete (trascina per spostarti, rotella per zoomare, clic su un incaricato per i dettagli). <strong>Elenco per livelli</strong>: la tabella con livello, diretti e clienti. In entrambe vedi cosa manca a ognuno per la qualifica successiva, cioè dove intervenire, e puoi aggiungere clienti, agenti o spostare l'incaricato: il risultato compare subito.
                        @endif
                    </p>

                    {{-- Vista albero: grafico --}}
                    <div x-show="view === 'tree'" x-data="simChart(@js($treeRows))" x-on:simview.window="onView($event.detail)" wire:ignore>
                        <div class="cv" x-ref="cv">
                            <div class="zoom"><button type="button" @click="zoom(.8)" aria-label="Riduci">−</button><span x-text="Math.round(k*100)+'%'"></span><button type="button" @click="zoom(1.25)" aria-label="Ingrandisci">+</button><button type="button" @click="fit()">Adatta</button><button type="button" @click="levels(3)">Livelli 1–3</button><button type="button" @click="expandAll()">Espandi tutto</button></div>
                            <div class="hint">Trascina per spostarti · rotella per zoomare</div>
                            <svg x-ref="svg" role="img" aria-label="Albero della rete"><g x-ref="g"></g></svg>
                        </div>
                        <div class="leg" x-html="legend"></div>
                        <template x-if="sel">
                            <div class="detail">
                                <div style="display:flex;justify-content:space-between;gap:.6rem;align-items:flex-start">
                                    <div><strong x-text="sel.label"></strong><div class="muted" x-text="selSummary()"></div></div>
                                    <button type="button" @click="sel = null; draw()" aria-label="Chiudi il riquadro">✕</button>
                                </div>
                                <div class="muted" style="margin-top:.35rem" x-show="sel.next_rank"><span>Per <strong x-text="sel.next_rank"></strong>: </span><span x-text="sel.missing ? 'mancano ' + sel.missing : 'requisiti soddisfatti'"></span></div>
                                <div class="muted" x-show="sel.explain && sel.explain.length" style="margin-top:.35rem"><strong x-text="sel.explain[0]"></strong><ul style="margin:.2rem 0 0 1rem;list-style:disc"><template x-for="line in (sel.explain || []).slice(1)"><li x-text="line"></li></template></ul></div>
                                <div class="bar" style="margin-top:.5rem" x-show="! sel.virtual">
                                    <button type="button" @click="$wire.addChangeFor('clients', sel.label)">+ clienti</button>
                                    <button type="button" @click="$wire.addChangeFor('agents', sel.label)">+ agenti</button>
                                    <button type="button" @click="$wire.addChangeFor('columns', sel.label)" title="Aggiungi 3 colonne da {{ $columnClients }} clienti">+ colonne</button>
                                    <button type="button" @click="$wire.addChangeFor('move', sel.label)">sposta</button>
                                    <span class="muted">«sposta» aggiunge la modifica nel modulo in alto: indica lì il nuovo sponsor.</span>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Elenco per livelli: tabella come «Elenco incaricati per livelli» --}}
                    <div class="scroll" x-show="view === 'levels'" x-cloak>
                        <table class="lv-table">
                            <thead><tr><th>Incaricato</th><th>Livello</th><th>Qualifica</th><th class="num">1° liv.</th><th class="num">Clienti</th><th>Per la successiva</th><th class="num">Compensi del mese</th><th></th></tr></thead>
                            <tbody>
                            @foreach($treeRows as $t)
                                @php [$code, $nm] = str_contains($t['label'], ' — ') ? explode(' — ', $t['label'], 2) : [null, $t['label']]; @endphp
                                <tr class="{{ $t['depth'] === 0 ? 'lv-row-0' : '' }} {{ $t['changed'] ? 'chg' : '' }}" x-show="show({{ $js($t['id']) }}, {{ $t['changed'] ? 'true' : 'false' }}, {{ $t['sub_changes'] }})">
                                    <td>
                                        <div class="lv-name">
                                            @for($i = 1; $i < $t['depth']; $i++)<span class="lv-guide" aria-hidden="true"></span>@endfor
                                            @if($t['depth'] > 0)<span class="lv-elbow" aria-hidden="true"></span>@endif
                                            @if(isset($hasKids[$t['id']]))
                                                <button type="button" class="lv-tg" @click="closed[{{ $js($t['id']) }}] = ! closed[{{ $js($t['id']) }}]" x-text="closed[{{ $js($t['id']) }}] ? '+' : '−'" aria-label="Apri o chiudi i diretti di {{ $nm }}">−</button>
                                            @else<span class="lv-tg-sp" aria-hidden="true"></span>@endif
                                            <span class="dot" style="background:{{ $t['color_after'] }}"></span>
                                            <strong style="margin-left:.35rem">{{ $nm }}</strong>@if($code)<span class="lv-code">{{ $code }}</span>@endif
                                            @if($t['tag'] === 'new')<span class="chip newc">nuovo</span>@endif
                                            @if($t['tag'] === 'moved')<span class="chip" style="color:var(--s-move)">spostato{{ $t['moved_from'] ? ' da '.$t['moved_from'] : '' }}</span>@endif
                                            @if($t['target'] && $t['tag'] !== 'moved')<span class="chip">scelto</span>@endif
                                            <span x-show="closed[{{ $js($t['id']) }}] && {{ $t['sub_changes'] }} > 0" class="chip" style="color:var(--s-acc)">{{ $t['sub_changes'] }} cambiamenti sotto</span>
                                        </div>
                                    </td>
                                    <td><span class="lv-level">{{ $t['depth'] }}</span></td>
                                    <td>@if($t['rank_before'] !== null && $t['rank_before'] !== $t['rank_after'])<span class="muted">{{ $t['rank_before'] }}</span> → <span class="{{ $t['dir'] >= 0 ? 'up' : 'down' }}">{{ $t['rank_after'] }} {{ $t['dir'] > 0 ? '▲' : '▼' }}</span>@else{{ $t['rank_after'] ?? '—' }}@endif</td>
                                    <td class="num">{{ $directs[$t['id']] ?? 0 }}</td>
                                    <td class="num">@if($t['clients_before'] !== null && $t['clients_before'] !== $t['clients_after']){{ $t['clients_before'] }} → <strong>{{ $t['clients_after'] }}</strong>@else{{ $t['clients_after'] }}@endif</td>
                                    <td class="muted">@if($t['next_rank'])<strong>{{ $t['next_rank'] }}</strong>: {{ $t['missing'] !== '' ? 'mancano '.$t['missing'] : 'requisiti soddisfatti' }}@else — @endif</td>
                                    <td class="num {{ (float) $t['earned_diff'] != 0 ? $cls($t['earned_diff']) : '' }}" @if($t['explain']) title="{{ implode(' · ', $t['explain']) }}" style="cursor:help" @endif>{{ (float) $t['earned_diff'] != 0 ? $signed($t['earned_diff']).' €' : '—' }}</td>
                                    <td>
                                        @unless($t['virtual'])
                                            <span class="acts" style="display:flex;gap:.25rem;justify-content:flex-end">
                                                <button type="button" wire:click="addChangeFor('clients', {{ $js($t['label']) }})" title="Aggiungi clienti a questo incaricato">+ clienti</button>
                                                <button type="button" wire:click="addChangeFor('agents', {{ $js($t['label']) }})" title="Aggiungi agenti sotto questo incaricato">+ agenti</button>
                                                <button type="button" wire:click="addChangeFor('columns', {{ $js($t['label']) }})" title="Aggiungi 3 colonne da {{ $columnClients }} clienti sotto questo incaricato">+ colonne</button>
                                                <button type="button" wire:click="addChangeFor('move', {{ $js($t['label']) }})" title="Sposta questo incaricato sotto un altro sponsor">sposta</button>
                                            </span>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if($result)



                @if($result['agents'])
                    <div class="box">
                        <h3>Agenti simulati</h3>
                        <div class="scroll"><table>
                            <tr><th>Sotto</th><th>Qualifica richiesta</th><th>Raggiunta</th><th>Struttura creata</th><th>Provvigioni sue nel mese</th></tr>
                            @foreach($result['agents'] as $ag)
                                <tr>
                                    <td>{{ $ag['sponsor'] }}</td><td>{{ $ag['requested'] }}</td>
                                    <td class="{{ $ag['ok'] ? 'up' : 'down' }}">{{ $ag['reached'] }} {{ $ag['ok'] ? '✓' : '✗' }}</td>
                                    <td class="muted">{{ $ag['sub_clients'] }} clienti{{ $ag['sub_agents'] > 0 ? ' e '.$ag['sub_agents'].' agenti sotto di lui' : '' }}</td>
                                    <td>{{ $eur($ag['earned']) }} €</td>
                                </tr>
                            @endforeach
                        </table></div>
                    </div>
                @endif

                <div class="box">
                    <h3>Cosa cambia per gli incaricati reali</h3>
                    @if($result['rows'])
                        <div class="scroll"><table>
                            <tr><th>Incaricato</th><th>Qualifica</th><th>Clienti</th><th>Pearl 1° liv.</th><th>Per la successiva</th><th>Provvigioni del mese</th></tr>
                            @foreach($result['rows'] as $r)
                                <tr class="{{ $r['dir'] !== 0 ? 'hl' : '' }}">
                                    <td>{{ $r['label'] }}@if($r['target'])<span class="chip">scelto</span>@endif @if($r['moved'])<span class="chip" style="color:var(--s-move)">spostato</span>@endif</td>
                                    <td>@if($r['dir'] === 0){{ $r['rank_after'] }}@else<span class="{{ $r['dir'] > 0 ? 'up' : 'down' }}">{{ $r['rank_before'] }} → {{ $r['rank_after'] }} {{ $r['dir'] > 0 ? '▲' : '▼' }}</span>@endif
                                        @if($r['paid_after'] !== $r['rank_after'] || $r['paid_before'] !== $r['rank_before'])<div class="muted">pagata: {{ $r['paid_before'] }} → {{ $r['paid_after'] }}</div>@endif</td>
                                    <td>@if($r['clients_before'] !== $r['clients_after']){{ $r['clients_before'] }} → <strong>{{ $r['clients_after'] }}</strong>@else{{ $r['clients_after'] }}@endif</td>
                                    <td>@if($r['pearl_before'] !== $r['pearl_after']){{ $r['pearl_before'] }} → <strong>{{ $r['pearl_after'] }}</strong>@else{{ $r['pearl_after'] }}@endif</td>
                                    <td>@if($r['next_after'] === null)<span class="muted">qualifica massima</span>@else<strong>{{ $r['next_after'] }}</strong>: {{ $r['missing_after'] !== '' ? 'mancano '.$r['missing_after'] : 'requisiti soddisfatti' }}@endif</td>
                                    <td title="{{ implode(' · ', $r['explain']) }}" style="cursor:help">{{ $eur($r['earned_before']) }} → {{ $eur($r['earned_after']) }} € <span class="{{ $cls($r['earned_diff']) }}">({{ $signed($r['earned_diff']) }})</span></td>
                                </tr>
                            @endforeach
                        </table></div>
                    @else
                        <p class="muted" style="margin:0">Nessun incaricato reale cambia qualifica, clienti o compensi con queste modifiche.</p>
                    @endif
                </div>


            @endif
        @endif
    </div>

    <script>
        function simChart(rows) {
            const NS = 'http://www.w3.org/2000/svg', W = 214, H = 66, GX = 22, GY = 62;
            return {
                rows, by: {}, kids: {}, open: {}, sel: null, k: 1, tx: 0, ty: 0, legend: '', ready: false,
                init() {
                    this.rows.forEach(n => { this.by[n.id] = n; this.kids[n.id] = []; });
                    this.rows.forEach(n => { if (n.parent !== null && this.kids[n.parent]) { this.kids[n.parent].push(n.id); } });
                    const seen = {};
                    this.rows.forEach(n => { if (n.rank_after && n.color_after && !seen[n.rank_after]) { seen[n.rank_after] = n.color_after; } });
                    this.legend = Object.keys(seen).map(r => '<span><i style="background:' + seen[r] + '"><\/i>' + this.esc(r) + '<\/span>').join('')
                        + '<span><i style="background:var(--s-acc)"><\/i>Evidenziato = cambia con la simulazione<\/span>';
                    const cv = this.$refs.cv;
                    cv.addEventListener('wheel', e => { e.preventDefault(); const r = cv.getBoundingClientRect(); this.zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX - r.left, e.clientY - r.top); }, { passive: false });
                    let dr = null;
                    cv.addEventListener('pointerdown', e => { dr = { x: e.clientX, y: e.clientY, tx: this.tx, ty: this.ty }; cv.classList.add('drag'); });
                    addEventListener('pointermove', e => { if (dr) { this.tx = dr.tx + e.clientX - dr.x; this.ty = dr.ty + e.clientY - dr.y; this.tf(); } });
                    addEventListener('pointerup', () => { dr = null; cv.classList.remove('drag'); });
                    this.expandAll(false);
                    if (cv.offsetParent !== null) { this.$nextTick(() => this.fit()); }
                },
                onView(v) { if (v === 'tree') { this.$nextTick(() => this.fit()); } },
                esc(t) { return String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); },
                num(v) { const n = Number(v); return (n > 0 ? '+' : '') + n.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                el(n, a, p) { const e = document.createElementNS(NS, n); for (const x in a) e.setAttribute(x, a[x]); p.appendChild(e); return e; },
                fitText(el, keep = '') {
                    const max = W - 52 - 10;
                    if (el.getComputedTextLength() <= max) { return; }
                    let head = el.textContent.slice(0, el.textContent.length - keep.length);
                    while (head.length > 1) {
                        head = head.slice(0, -1);
                        el.textContent = head.trimEnd() + '…' + keep;
                        if (el.getComputedTextLength() <= max) { break; }
                    }
                },
                ini(n) { return (n.replace(/[^\p{L} ]/gu, '').trim().split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase()) || '?'; },
                split(label) { const i = label.indexOf(' — '); return i < 0 ? [null, label] : [label.slice(0, i), label.slice(i + 3)]; },
                selSummary() {
                    const n = this.sel, r = (n.rank_before && n.rank_before !== n.rank_after) ? n.rank_before + ' → ' + n.rank_after : (n.rank_after || '—');
                    const c = (n.clients_before !== null && n.clients_before !== n.clients_after) ? n.clients_before + ' → ' + n.clients_after : n.clients_after;
                    return 'Qualifica ' + r + ' · clienti ' + c + ' · diretti ' + (this.kids[n.id] || []).length + (Number(n.earned_diff) !== 0 ? ' · compensi ' + this.num(n.earned_diff) + ' €' : '');
                },
                layout() {
                    const pos = {}; let x = 0; const self = this;
                    const roots = this.rows.filter(n => n.parent === null).map(n => n.id);
                    (function w(id, d) {
                        const c = self.open[id] ? self.kids[id] : [];
                        if (!c.length) { pos[id] = { x: x, y: d }; x += W + GX; return; }
                        c.forEach(i => w(i, d + 1));
                        pos[id] = { x: (pos[c[0]].x + pos[c[c.length - 1]].x) / 2, y: d };
                    })(roots[0], 0);
                    roots.slice(1).forEach(r => (function w(id, d) {
                        const c = self.open[id] ? self.kids[id] : [];
                        if (!c.length) { pos[id] = { x: x, y: d }; x += W + GX; return; }
                        c.forEach(i => w(i, d + 1));
                        pos[id] = { x: (pos[c[0]].x + pos[c[c.length - 1]].x) / 2, y: d };
                    })(r, 0));
                    return pos;
                },
                draw() {
                    const g = this.$refs.g; g.innerHTML = '';
                    const pos = this.layout(), eg = this.el('g', {}, g), ng = this.el('g', {}, g);
                    for (const id in pos) {
                        const d = this.by[id], P = pos[id];
                        if (this.open[id]) this.kids[id].forEach(c => {
                            const q = pos[c], x1 = P.x + W / 2, y1 = P.y * (H + GY) + H, x2 = q.x + W / 2, y2 = q.y * (H + GY), m = (y1 + y2) / 2;
                            this.el('path', { class: 'e', d: `M${x1},${y1}V${m}H${x2}V${y2}` }, eg);
                        });
                        const col = d.color_after || '#B9B1A5', [code, nm] = this.split(d.label);
                        const gr = this.el('g', { class: 'nd' + (d.changed ? ' chg' : '') + (this.sel && this.sel.id === d.id ? ' sel' : ''), transform: `translate(${P.x},${P.y * (H + GY)})` }, ng);
                        this.el('rect', { class: 'b', width: W, height: H, rx: 10, fill: 'var(--s-card)', stroke: col, 'stroke-dasharray': d.virtual ? '5 3' : '' }, gr);
                        this.el('rect', { x: 0, y: 0, width: 6, height: H, rx: 3, fill: col }, gr);
                        this.el('circle', { cx: 28, cy: H / 2, r: 16, fill: col }, gr);
                        this.el('text', { x: 28, y: H / 2 + 4, 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 700, fill: '#fff' }, gr).textContent = this.ini(nm);
                        const nameEl = this.el('text', { x: 52, y: 17, 'font-size': 12, 'font-weight': 700, fill: 'var(--s-ink)' }, gr);
                        nameEl.textContent = nm;
                        this.fitText(nameEl);
                        const rankChanged = d.rank_before && d.rank_before !== d.rank_after;
                        const t2 = this.el('text', { x: 52, y: 32, 'font-size': 10.5, 'font-weight': 600, fill: rankChanged ? (d.dir >= 0 ? 'var(--s-up)' : 'var(--s-down)') : 'var(--s-muted)' }, gr);
                        t2.textContent = (rankChanged ? d.rank_before + ' → ' + d.rank_after + (d.dir > 0 ? ' ▲' : ' ▼') : (d.rank_after || '—')) + (code ? ' · ' + code : '');
                        this.fitText(t2, code ? ' · ' + code : '');
                        const clients = (d.clients_before !== null && d.clients_before !== d.clients_after) ? d.clients_before + '→' + d.clients_after : d.clients_after;
                        const diff = Number(d.earned_diff) !== 0 ? ' · ' + this.num(d.earned_diff) + ' €' : '';
                        const clientsEl = this.el('text', { x: 52, y: 46, 'font-size': 10.5, fill: 'var(--s-muted)' }, gr);
                        clientsEl.textContent = 'clienti ' + clients + diff;
                        this.fitText(clientsEl);
                        if (d.next_rank) { let m = 'per ' + d.next_rank + ': ' + (d.missing ? d.missing : 'ok'); const nextEl = this.el('text', { x: 52, y: 59, 'font-size': 9.5, fill: 'var(--s-muted)' }, gr); nextEl.textContent = m; this.fitText(nextEl); }
                        const tt = this.el('title', {}, gr); tt.textContent = d.label + (d.next_rank ? ' — per ' + d.next_rank + ': ' + (d.missing ? 'mancano ' + d.missing : 'requisiti soddisfatti') : '');
                        if (d.tag === 'new') { this.el('text', { x: W - 8, y: 14, 'text-anchor': 'end', 'font-size': 9.5, 'font-weight': 700, fill: 'var(--s-new)' }, gr).textContent = 'nuovo'; }
                        if (d.tag === 'moved') { this.el('text', { x: W - 8, y: 14, 'text-anchor': 'end', 'font-size': 9.5, 'font-weight': 700, fill: 'var(--s-move)' }, gr).textContent = 'spostato'; }
                        const nk = this.kids[id].length;
                        if (nk) {
                            const o = !!this.open[id], t = this.el('g', { class: 'tg2', transform: `translate(${W / 2},${H + 1})` }, gr);
                            this.el('rect', { x: -28, y: -1, width: 56, height: 17, rx: 8.5, fill: 'var(--s-card)', stroke: 'var(--s-line)' }, t);
                            this.el('text', { 'text-anchor': 'middle', y: 11, 'font-size': 10, 'font-weight': 700, fill: 'var(--s-acc)' }, t).textContent = (o ? '▾ ' : '▸ ') + nk + (!o && d.sub_changes ? ' · ' + d.sub_changes + '!' : '');
                            t.addEventListener('click', e => { e.stopPropagation(); this.open[id] = !this.open[id]; this.draw(); });
                            t.addEventListener('pointerdown', e => e.stopPropagation());
                        }
                        gr.addEventListener('click', () => { this.sel = this.by[id]; this.draw(); });
                        gr.addEventListener('pointerdown', e => e.stopPropagation());
                    }
                    this.tf();
                },
                tf() { this.$refs.g.setAttribute('transform', `translate(${this.tx},${this.ty}) scale(${this.k})`); },
                fit() {
                    const g = this.$refs.g, cv = this.$refs.cv, r = cv.getBoundingClientRect(); if (!r.width) { return; }
                    if (!g.firstChild) { this.draw(); }
                    const b = g.getBBox(); if (!b.width) { return; }
                    this.k = Math.max(.06, Math.min(1.1, (r.width - 40) / b.width, (r.height - 90) / b.height));
                    this.tx = (r.width - b.width * this.k) / 2 - b.x * this.k;
                    this.ty = 60 - b.y * this.k;
                    this.tf();
                },
                zoom(f, cx, cy) {
                    const r = this.$refs.cv.getBoundingClientRect(); cx = cx ?? r.width / 2; cy = cy ?? r.height / 2;
                    const nk = Math.min(2.5, Math.max(.04, this.k * f));
                    this.tx = cx - (cx - this.tx) * nk / this.k; this.ty = cy - (cy - this.ty) * nk / this.k; this.k = nk; this.tf();
                },
                expandAll(refit = true) { this.rows.forEach(n => { if (this.kids[n.id].length) this.open[n.id] = true; }); this.draw(); if (refit) this.fit(); },
                levels(m) { this.open = {}; this.rows.forEach(n => { if (this.kids[n.id].length && n.depth < m) this.open[n.id] = true; }); this.draw(); this.fit(); },
            };
        }
    </script>
</x-filament-panels::page>
