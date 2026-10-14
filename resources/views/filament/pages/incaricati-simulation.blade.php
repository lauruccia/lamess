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
        .sim .tr { font-size:.88rem; line-height:1.7; } .sim .tr > div { border-left:1px solid var(--s-line); padding-left:.7rem; margin-left:.3rem; }
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
                <h3>Cosa vuoi simulare?</h3>
                <datalist id="sim-incaricati">@foreach($options as $o)<option value="{{ $o }}"></option>@endforeach</datalist>
                @foreach($changes as $i => $c)
                    <div class="row" wire:key="change-{{ $i }}">
                        <span class="n">{{ $i + 1 }}</span>
                        <select wire:model.live="changes.{{ $i }}.type" aria-label="Tipo di modifica">
                            <option value="clients">Dai clienti a…</option>
                            <option value="orders">Aggiungi ordini a…</option>
                            <option value="agents">Dai agenti di livello a…</option>
                            <option value="move">Sposta nell'albero…</option>
                        </select>
                        @if($c['type'] === 'move')
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.target" placeholder="Incaricato da spostare (codice o nome)" aria-label="Incaricato da spostare">
                            <span>sotto lo sponsor</span>
                            <input class="target" list="sim-incaricati" wire:model="changes.{{ $i }}.sponsor" placeholder="Nuovo sponsor (codice o nome)" aria-label="Nuovo sponsor">
                        @else
                            <input class="num" type="number" min="1" max="{{ $c['type'] === 'agents' ? 20 : 500 }}" wire:model="changes.{{ $i }}.count" aria-label="Quantità">
                            @if($c['type'] === 'agents')
                                <select wire:model="changes.{{ $i }}.rank" aria-label="Qualifica degli agenti">@foreach($ranks as $r)<option value="{{ $r['code'] }}">{{ $r['name'] }}</option>@endforeach</select>
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
                @endforeach
                <div class="bar" style="margin-top:.7rem">
                    <button type="button" wire:click="addChange">+ Aggiungi un'altra modifica</button>
                    <button type="button" class="pri" wire:click="simulate" wire:loading.attr="disabled" wire:target="simulate">Simula</button>
                    <button type="button" wire:click="resetSimulation">Azzera</button>
                    <span class="muted" wire:loading wire:target="simulate">Calcolo in corso…</span>
                </div>
                <p class="muted" style="margin:.5rem 0 0">«Dai agenti di livello» crea agenti virtuali con tutto ciò che serve per raggiungere quella qualifica (clienti, Pearl e linee richieste dal piano). «Aggiungi ordini» usa i clienti che l'incaricato ha già, senza aumentarne il numero. Le modifiche si applicano nell'ordine in cui le scrivi.</p>
            </div>

            @if($error)<div class="err" role="alert">{{ $error }}</div>@endif

            @if($result)
                @php($s = $result['summary'])
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
                                    <td>{{ $eur($r['earned_before']) }} → {{ $eur($r['earned_after']) }} € <span class="{{ $cls($r['earned_diff']) }}">({{ $signed($r['earned_diff']) }})</span></td>
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

                @if($result['tree'])
                    <div class="box">
                        <h3>Albero dopo la simulazione</h3>
                        <p class="muted" style="margin:0 0 .5rem">Sono mostrati solo i rami toccati dalle modifiche. Tra parentesi: clienti personali.</p>
                        <div class="tr">
                            @foreach($result['tree'] as $t)
                                <div style="margin-left:{{ $t['depth'] * 1.1 }}rem">
                                    <span style="{{ $t['highlight'] ? 'font-weight:700' : 'opacity:.75' }}">{{ $t['label'] }}</span>
                                    <span class="muted">({{ $t['clients'] }})</span>
                                    — @if($t['rank_before'] !== null && $t['rank_before'] !== $t['rank_after'])<span class="{{ $t['tag'] === 'down' ? 'down' : 'up' }}">{{ $t['rank_before'] }} → {{ $t['rank_after'] }}</span>@else{{ $t['rank_after'] }}@endif
                                    @if($t['tag'] === 'new')<span class="chip newc">nuovo{{ $t['extra'] > 0 ? ' · +'.$t['extra'].' agenti sotto' : '' }}</span>@endif
                                    @if($t['tag'] === 'moved')<span class="chip" style="color:var(--s-move)">spostato</span>@endif
                                    @if($t['tag'] === 'target')<span class="chip">scelto</span>@endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
</x-filament-panels::page>
