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
                    <button type="button" wire:click="addChange('move')">+ Sposta nell'albero</button>
                </div>
                <datalist id="sim-incaricati">@foreach($options as $o)<option value="{{ $o }}"></option>@endforeach</datalist>
                @forelse($changes as $i => $c)
                    <div class="row" wire:key="change-{{ $i }}">
                        <span class="n">{{ $i + 1 }}</span>
                        <strong>{{ ['clients' => 'Aggiungi clienti', 'orders' => 'Aggiungi ordini', 'agents' => 'Aggiungi agenti', 'move' => 'Sposta nell\'albero'][$c['type']] ?? $c['type'] }}</strong>
                        @if($c['type'] === 'move')
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.target" placeholder="Incaricato da spostare (codice o nome)" aria-label="Incaricato da spostare">
                            <span>sotto lo sponsor</span>
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.sponsor" placeholder="Nuovo sponsor (codice o nome)" aria-label="Nuovo sponsor">
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
                <p class="muted" style="margin:.5rem 0 0">«Aggiungi agenti» crea agenti virtuali del livello scelto con tutto ciò che serve per raggiungerlo (clienti, Pearl e linee richieste dal piano). «Aggiungi ordini» usa i clienti che l'incaricato ha già, senza aumentarne il numero. Le modifiche si applicano nell'ordine in cui le scrivi.</p>
            </div>

            @if($error)<div class="err" role="alert">{{ $error }}</div>@endif

            @if($result)
                @php $s = $result['summary']; @endphp
                <div class="box">
                    <h3>Risultato per il periodo {{ $result['period'] }}</h3>
                    <div class="kpis">
                        <div class="kpi"><span class="muted">Modifiche simulate</span><b>{{ $s['clients'] }} clienti · {{ $s['orders'] }} ordini</b><span class="muted">{{ $s['virtual_agents'] }} agenti simulati · {{ $s['moves'] }} spostamenti</span></div>
                        <div class="kpi"><span class="muted">Qualifiche che salgono</span><b class="{{ $s['rank_up'] ? 'up' : '' }}">{{ $s['rank_up'] }}</b></div>
                        <div class="kpi"><span class="muted">Qualifiche che scendono</span><b class="{{ $s['rank_down'] ? 'down' : '' }}">{{ $s['rank_down'] }}</b></div>
                        <div class="kpi"><span class="muted">Compensi del mese</span><b>{{ $eur($s['total_before']) }} € → {{ $eur($s['total_after']) }} €</b><span class="{{ $cls($s['total_diff']) }}">{{ $signed($s['total_diff']) }} €</span></div>
                    </div>
                </div>

                @foreach($result['warnings'] as $w)<div class="warn" role="status">{{ $w }}</div>@endforeach
            @endif

            @php $treeRows = $result['tree'] ?? $baseTree; @endphp
                @if($treeRows)
                    @php
                        $parents = [];
                        $hasKids = [];
                        $labelOf = [];
                        foreach ($treeRows as $t) { $labelOf[$t['id']] = $t['label']; $parents[$t['id']] = $t['parent']; if ($t['parent'] !== null) { $hasKids[$t['parent']] = true; } }
                    @endphp
                    <div class="box" wire:key="tree-{{ md5(json_encode($treeRows)) }}"
                         x-data="{ closed: {}, only: false, parents: @js($parents), labels: @js($labelOf), kids: @js(array_keys($hasKids)),
                                   view: (() => { try { return localStorage.getItem('simTreeView') === 'list' ? 'list' : 'tree'; } catch (e) { return 'tree'; } })(),
                                   setView(v) { this.view = v; try { localStorage.setItem('simTreeView', v); } catch (e) {} },
                                   hidden(id) { if (this.view === 'list') { return false; } for (let p = this.parents[id]; p; p = this.parents[p]) { if (this.closed[p]) { return true; } } return false; },
                                   show(id, changed, sub) { return ! this.hidden(id) && (! this.only || changed || (this.view === 'tree' && sub > 0)); },
                                   closeAll() { this.kids.forEach(k => this.closed[k] = true); }, openAll() { this.closed = {}; } }">
                        <div class="bar" style="justify-content:space-between">
                            <h3 style="margin:0">{{ $result ? 'Tutta la rete dopo la simulazione' : 'La rete oggi — periodo '.$periodCode }}</h3>
                            <div class="bar">
                                @if($result)<label><input type="checkbox" x-model="only"> solo i rami con cambiamenti</label>@endif
                                <span role="group" aria-label="Tipo di vista" style="display:inline-flex">
                                    <button type="button" :class="view === 'tree' ? 'pri' : ''" style="border-radius:.5rem 0 0 .5rem" @click="setView('tree')" :aria-pressed="view === 'tree'">Vista albero</button>
                                    <button type="button" :class="view === 'list' ? 'pri' : ''" style="border-radius:0 .5rem .5rem 0;margin-left:-1px" @click="setView('list')" :aria-pressed="view === 'list'">Vista elenco</button>
                                </span>
                                <button type="button" x-show="view === 'tree'" @click="openAll()">Apri tutto</button>
                                <button type="button" x-show="view === 'tree'" @click="closeAll()">Chiudi tutto</button>
                            </div>
                        </div>
                        <p class="muted" style="margin:.3rem 0 .6rem">
                            @if($result)
                                Ogni incaricato mostra la qualifica <em>prima → dopo</em> (il colore è quello della pietra), i clienti personali e la variazione dei compensi del mese. Le righe evidenziate sono quelle che cambiano. Dopo ogni incaricato trovi cosa gli manca per la qualifica successiva. Usa i pulsanti a destra per aggiungere clienti, agenti o spostarlo.
                            @else
                                Questo è lo stato reale del periodo, senza nessuna modifica. Con i pulsanti in alto puoi passare dalla vista albero (a rami apribili) alla vista elenco (tutti in fila, con lo sponsor accanto al nome). Ogni incaricato mostra qualifica (colore della pietra), clienti personali e cosa gli manca per la qualifica successiva: è lì che conviene intervenire. Premi <strong>+ clienti</strong>, <strong>+ agenti</strong> o <strong>sposta</strong> accanto all'incaricato scelto e il risultato compare subito qui sotto.
                            @endif
                        </p>
                        <div class="tr">
                            @foreach($treeRows as $t)
                                <div class="node {{ $t['changed'] ? 'chg' : '' }}" x-show="show({{ \Illuminate\Support\Js::from($t['id']) }}, {{ $t['changed'] ? 'true' : 'false' }}, {{ $t['sub_changes'] }})" x-bind:style="view === 'tree' ? 'padding-left:{{ $t['depth'] * 1.3 }}rem' : ''">
                                    @if(isset($hasKids[$t['id']]))
                                        <button type="button" class="tg" @click="closed[{{ \Illuminate\Support\Js::from($t['id']) }}] = ! closed[{{ \Illuminate\Support\Js::from($t['id']) }}]" x-text="closed[{{ \Illuminate\Support\Js::from($t['id']) }}] ? '▸' : '▾'" x-show="view === 'tree'" aria-label="Apri o chiudi il ramo"></button>
                                    @else<span class="tg" x-show="view === 'tree'"></span>@endif
                                    <span class="dot" style="background:{{ $t['color_after'] }}"></span>
                                    <span class="nm" style="{{ $t['changed'] || $t['target'] ? 'font-weight:700' : '' }}">{{ $t['label'] }}</span>
                                    <span class="muted" x-show="view === 'list'" x-text="parents[{{ \Illuminate\Support\Js::from($t['id']) }}] ? '(sotto ' + labels[parents[{{ \Illuminate\Support\Js::from($t['id']) }}]] + ')' : '(in cima alla rete)'"></span>
                                    <span class="rk">
                                        @if($t['rank_before'] !== null && $t['rank_before'] !== $t['rank_after'])
                                            <span class="muted">{{ $t['rank_before'] }}</span> → <span class="{{ $t['dir'] >= 0 ? 'up' : 'down' }}">{{ $t['rank_after'] }} {{ $t['dir'] > 0 ? '▲' : '▼' }}</span>
                                        @else{{ $t['rank_after'] }}@endif
                                    </span>
                                    <span class="muted">· clienti @if($t['clients_before'] !== null && $t['clients_before'] !== $t['clients_after']){{ $t['clients_before'] }} → <strong>{{ $t['clients_after'] }}</strong>@else{{ $t['clients_after'] }}@endif</span>
                                    @if($t['next_rank'])<span class="muted">· per <strong>{{ $t['next_rank'] }}</strong>: {{ $t['missing'] !== '' ? 'mancano '.$t['missing'] : 'requisiti soddisfatti' }}</span>@endif
                                    @if((float) $t['earned_diff'] != 0)<span class="tip {{ $cls($t['earned_diff']) }}" tabindex="0">{{ $signed($t['earned_diff']) }} €
                                        <span class="tipbox" role="tooltip">
                                            @if($t['explain'])<strong>{{ $t['explain'][0] }}</strong><ul>@foreach(array_slice($t['explain'], 1) as $line)<li>{{ $line }}</li>@endforeach</ul>@else Variazione dei compensi del mese rispetto allo stato reale.@endif
                                        </span></span>@endif
                                    @if($t['tag'] === 'new')<span class="chip newc">nuovo{{ $t['extra'] > 0 ? ' · +'.$t['extra'].' agenti sotto' : '' }}</span>@endif
                                    @if($t['tag'] === 'moved')<span class="chip" style="color:var(--s-move)">spostato{{ $t['moved_from'] ? ' da '.$t['moved_from'] : '' }}</span>@endif
                                    @if($t['target'] && $t['tag'] !== 'moved')<span class="chip">scelto</span>@endif
                                    <span x-show="view === 'tree' && closed[{{ \Illuminate\Support\Js::from($t['id']) }}] && {{ $t['sub_changes'] }} > 0" class="chip" style="color:var(--s-acc)">{{ $t['sub_changes'] }} cambiamenti sotto</span>
                                    @unless($t['virtual'])
                                        <span class="acts">
                                            <button type="button" wire:click="addChangeFor('clients', {{ \Illuminate\Support\Js::from($t['label']) }})" title="Aggiungi clienti a questo incaricato">+ clienti</button>
                                            <button type="button" wire:click="addChangeFor('agents', {{ \Illuminate\Support\Js::from($t['label']) }})" title="Aggiungi agenti sotto questo incaricato">+ agenti</button>
                                            <button type="button" wire:click="addChangeFor('move', {{ \Illuminate\Support\Js::from($t['label']) }})" title="Sposta questo incaricato sotto un altro sponsor">sposta</button>
                                        </span>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            @if($result)



                @if($result['agents'])
                    <div class="box">
                        <h3>Agenti simulati</h3>
                        <div class="scroll"><table>
                            <tr><th>Sotto</th><th>Qualifica richiesta</th><th>Raggiunta</th><th>Struttura creata</th><th>Compensi suoi nel mese</th></tr>
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
                            <tr><th>Incaricato</th><th>Qualifica</th><th>Clienti</th><th>Pearl 1° liv.</th><th>Per la successiva</th><th>Compensi del mese</th></tr>
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

                @if($result['totals'])
                    <div class="box">
                        <h3>Compensi per tipo</h3>
                        <div class="scroll"><table>
                            <tr><th>Tipo</th><th>Prima</th><th>Dopo</th><th>Differenza</th></tr>
                            @foreach($result['totals'] as $t)
                                <tr><td>{{ $bonusNames[$t['bonus']] ?? $t['bonus'] }}</td><td>{{ $eur($t['before']) }} €</td><td>{{ $eur($t['after']) }} €</td><td class="{{ $cls($t['diff']) }}">{{ $signed($t['diff']) }} €</td></tr>
                            @endforeach
                        </table></div>
                    </div>
                @endif

            @endif
        @endif
    </div>
</x-filament-panels::page>
