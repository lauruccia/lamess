<x-filament-panels::page>
    <style>
        .lv-card { padding:1rem 1.25rem; }
        .lv-bar { display:flex; flex-wrap:wrap; gap:1rem 1.5rem; align-items:flex-end; font-size:.85rem; }
        .lv-bar label { display:flex; flex-direction:column; gap:.2rem; }
        .lv-bar input, .lv-bar select { padding:.3rem .55rem; border:1px solid rgba(187,180,175,.8); border-radius:.4rem; background:transparent; color:inherit; min-width:11rem; }
        .lv-sug { margin:.35rem 0 0; padding:0; list-style:none; font-size:.85rem; }
        .lv-sug button { background:none; border:0; padding:.2rem 0; cursor:pointer; color:#894C2D; text-decoration:underline; }
        .lv-chip { display:inline-flex; gap:.4rem; align-items:center; padding:.2rem .6rem; border-radius:999px; background:rgba(194,102,58,.12); font-weight:600; }
        .lv-chip button { background:none; border:0; cursor:pointer; font-size:1rem; line-height:1; color:inherit; }
        .lv-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .lv-table th { text-align:left; padding:.55rem .75rem; border-bottom:1px solid rgba(187,180,175,.6); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; opacity:.7; white-space:nowrap; }
        .lv-table td { padding:.4rem .75rem; border-bottom:1px solid rgba(187,180,175,.25); vertical-align:middle; }
        .lv-table td.num, .lv-table th.num { text-align:right; }
        .lv-name { display:flex; align-items:center; min-height:1.5rem; }
        .lv-guide { flex:none; display:inline-block; width:1.5rem; height:1.5rem; border-left:1px dashed rgba(187,180,175,.9); }
        .lv-guide:first-child { border-left:0; }
        .lv-elbow { flex:none; display:inline-block; width:.9rem; height:.8rem; margin-right:.4rem; border-left:1px solid rgba(187,180,175,.9); border-bottom:1px solid rgba(187,180,175,.9); transform:translateY(-.3rem); }
        .lv-name a { font-weight:600; color:inherit; text-decoration:none; }
        .lv-name a:hover { text-decoration:underline; color:#894C2D; }
        .lv-code { margin-left:.5rem; font-size:.75rem; opacity:.6; }
        .lv-level { display:inline-block; min-width:1.7rem; text-align:center; padding:.05rem .4rem; border-radius:.4rem; background:rgba(187,180,175,.25); font-size:.75rem; font-weight:700; }
        .lv-row-0 td { background:rgba(194,102,58,.06); }
        .lv-muted { opacity:.6; }
        .lv-tg { flex:none; width:1.4rem; height:1.4rem; margin-right:.3rem; display:inline-flex; align-items:center; justify-content:center; border:1px solid rgba(187,180,175,.9); border-radius:.35rem; background:transparent; cursor:pointer; color:inherit; font-size:.8rem; line-height:1; }
        .lv-tg:hover { background:rgba(194,102,58,.12); }
        .lv-tg-sp { flex:none; display:inline-block; width:1.4rem; margin-right:.3rem; }
        .lv-tools { display:flex; gap:.5rem; padding:.6rem .75rem 0; }
        .lv-tools button { background:none; border:1px solid rgba(187,180,175,.8); border-radius:.4rem; padding:.2rem .6rem; font-size:.78rem; cursor:pointer; color:inherit; }
        .lv-note { margin:.6rem 0 0; font-size:.8rem; opacity:.7; }
    </style>

    <div class="fi-section lv-card">
        <div class="lv-bar">
            <div>
                <span style="display:block;margin-bottom:.2rem">Parti da</span>
                @if($root)
                    <span class="lv-chip">{{ $root->code }} — {{ $root->name }} <button type="button" wire:click="clearRoot" title="Torna a tutta la rete" aria-label="Torna a tutta la rete">×</button></span>
                @else
                    <input type="search" wire:model.live.debounce.300ms="rootSearch" placeholder="Cerca per nome o codice…" aria-label="Cerca l'incaricato da cui partire">
                    @if($suggestions->isNotEmpty())
                        <ul class="lv-sug">@foreach($suggestions as $s)<li><button type="button" wire:click="selectRoot({{ $s->id }})">{{ $s->code }} — {{ $s->name }}</button></li>@endforeach</ul>
                    @elseif(trim($rootSearch) !== '')
                        <p class="lv-note">Nessun incaricato trovato.</p>
                    @else
                        <span class="lv-muted" style="font-size:.8rem;display:block;margin-top:.2rem">Tutta la rete</span>
                    @endif
                @endif
            </div>
            <label>Fino al livello
                <select wire:model.live="maxLevel">
                    <option value="">Tutti i livelli</option>
                    @foreach(range(1, 10) as $n)<option value="{{ $n }}">{{ $n }}{{ $n === 1 ? ' (solo diretti)' : '' }}</option>@endforeach
                </select>
            </label>
            <label>Stato
                <select wire:model.live="status">
                    <option value="all">Tutti</option>
                    <option value="not_terminated">Senza i cessati</option>
                    <option value="active">Solo attivi</option>
                    <option value="terminated">Solo cessati</option>
                </select>
            </label>
            <label>Hanno acquistato nel periodo
                <select wire:model.live="buyPeriod">
                    <option value="month">Mese in corso</option>
                    <option value="last_month">Mese scorso</option>
                    <option value="last_3_months">Ultimi 3 mesi</option>
                    <option value="year">Anno in corso</option>
                    <option value="custom">Periodo a scelta…</option>
                </select>
            </label>
            @if($buyPeriod === 'custom')
                <label>Dal <input type="date" wire:model.live="buyFrom"></label>
                <label>Al <input type="date" wire:model.live="buyTo"></label>
            @endif
        </div>
        <p class="lv-note">Il livello conta da chi scegli: lui è 0, i suoi diretti 1, i diretti dei diretti 2… Senza scelta si parte da chi non ha sponsor. «Hanno acquistato» conta i clienti assegnati all'incaricato con almeno un ordine pagato nel periodo scelto (un cliente vale uno anche con più ordini). <strong>{{ $list['total'] }}</strong> {{ $list['total'] === 1 ? 'incaricato' : 'incaricati' }}@if($list['truncated']) — mostrati i primi {{ $limit }}: restringi con «Parti da» o i livelli @endif.</p>
    </div>

    @php
        $parents = []; $kids = [];
        foreach ($list['rows'] as $r) { $parents[$r['id']] = $r['parent']; if ($r['parent'] !== null) { $kids[$r['parent']] = true; } }
        $openByDefault = collect($list['rows'])->where('level', 0)->pluck('id')->mapWithKeys(fn ($id) => [$id => true])->all();
    @endphp
    <div class="fi-section" style="padding:0;overflow:auto" wire:key="lv-{{ md5(json_encode(array_keys($parents))) }}"
         x-data="{ open: @js((object) $openByDefault), parents: @js((object) $parents), kids: @js((object) $kids),
                   shown(id) { let p = this.parents[id]; while (p !== null && p !== undefined) { if (!this.open[p]) return false; p = this.parents[p]; } return true; },
                   all() { Object.keys(this.kids).forEach(k => this.open[k] = true); },
                   none() { Object.keys(this.open).forEach(k => this.open[k] = false); } }">
        @if($list['rows'] === [])
            <p style="padding:1.25rem 1.5rem" class="lv-muted">Nessun incaricato da mostrare.</p>
        @else
            <div class="lv-tools"><button type="button" x-on:click="all()">Apri tutto</button><button type="button" x-on:click="none()">Chiudi tutto</button></div>
            <table class="lv-table">
                <thead><tr><th>Incaricato</th><th>Livello</th><th>Qualifica</th><th>Stato</th><th class="num">1° liv.</th><th class="num">Clienti</th><th class="num" title="Clienti assegnati con almeno un ordine pagato nel periodo scelto ({{ $buyLabel }})">Hanno acquistato <span class="lv-muted" style="text-transform:none">({{ $buyLabel }})</span></th><th>Dal</th></tr></thead>
                <tbody>
                @foreach($list['rows'] as $r)
                    <tr class="lv-row-{{ $r['level'] === 0 ? 0 : 'n' }}" x-show="shown({{ $r['id'] }})" @if($r['parent'] !== null) x-cloak style="display:none" @endif>
                        <td>
                            <div class="lv-name">
                                @for($i = 1; $i < $r['level']; $i++)<span class="lv-guide" aria-hidden="true"></span>@endfor
                                @if($r['level'] > 0)<span class="lv-elbow" aria-hidden="true"></span>@endif
                                @if(isset($kids[$r['id']]))
                                    <button type="button" class="lv-tg" x-on:click="open[{{ $r['id'] }}] = !open[{{ $r['id'] }}]" x-bind:aria-expanded="!!open[{{ $r['id'] }}]" aria-label="Apri o chiudi i diretti di {{ $r['name'] }}" x-text="open[{{ $r['id'] }}] ? '−' : '+'">+</button>
                                @else
                                    <span class="lv-tg-sp" aria-hidden="true"></span>
                                @endif
                                <a href="{{ $url($r['id']) }}">{{ $r['name'] }}</a><span class="lv-code">{{ $r['code'] }}</span>
                            </div>
                        </td>
                        <td><span class="lv-level">{{ $r['level'] }}</span></td>
                        <td>{{ $r['rank'] ?? '—' }}</td>
                        <td>{{ \App\Support\StatusLabels::label($r['status']) }}</td>
                        <td class="num">{{ $r['directs'] }}</td>
                        <td class="num">{{ $r['clients'] }}</td>
                        <td class="num">{{ $r['buyers'] }}</td>
                        <td>{{ $r['since'] ? \Carbon\Carbon::parse($r['since'])->format('d/m/Y') : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
