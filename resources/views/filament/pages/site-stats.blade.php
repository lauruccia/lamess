@php
    $tz = 'Europe/Rome';
    $fmt = fn ($dt, string $f = 'j M, H:i') => $dt ? $dt->copy()->setTimezone($tz)->locale('it')->translatedFormat($f) : '—';
    $n0 = fn (float|int $v): string => number_format((float) $v, 0, ',', '.');
    $back = $area === 'back';
    $devices = ['desktop' => 'Computer', 'mobile' => 'Telefono', 'tablet' => 'Tablet'];
    $kindTone = ['Staff' => 'staff', 'Incaricato' => 'inc', 'Cliente' => 'cli', 'Visitatore' => 'vis'];
    if ($d) {
        $w = $d['window'];
        $from = $w['start']->setTimezone($tz)->locale('it')->translatedFormat('j M Y');
        $to = $w['end']->setTimezone($tz)->locale('it')->translatedFormat('j M Y');
        $daily = $d['daily'];
        $maxDay = max(1, ...array_map(fn ($x) => $x['visits'], $daily ?: [['visits' => 1]]));
        $tiles = [
            ['Visite', $n0($d['tiles']['visits']['value']), $d['tiles']['visits']['delta'], 'a'],
            ['Pagine viste', $n0($d['tiles']['hits']['value']), $d['tiles']['hits']['delta'], 'b'],
            ['Pagine per visita', number_format($d['tiles']['per_visit']['value'], 1, ',', '.'), null, 'b'],
            ['Visite di una sola pagina', number_format($d['tiles']['bounce']['value'], 0, ',', '.').'%', null, 'c'],
            [$back ? 'Visite di utenti collegati' : 'Visite di utenti collegati', $n0($d['tiles']['logged']['value']), $d['tiles']['logged']['delta'], 'c'],
        ];
        $mapDevice = fn (array $rows) => array_map(fn ($r) => ['label' => $devices[$r['label']] ?? $r['label']] + $r, $rows);
    }
@endphp
<x-filament-panels::page>
    <style>
        .lm-s{--s-gap:1rem;display:grid;gap:var(--s-gap);font-family:var(--lm-font-text,Inter,system-ui,sans-serif)}
        .lm-s *{box-sizing:border-box}
        .lm-s-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem .9rem}
        .lm-s-bar select{padding:.35rem .6rem;border:1px solid var(--lm-border,#BBB4AF);border-radius:.5rem;background:var(--lm-surface,#fff);color:inherit;font-size:.875rem}
        .lm-s-bar label{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem}
        .lm-s-range{font-size:.85rem;opacity:.75}
        .lm-s-tiles{display:grid;gap:var(--s-gap);grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr))}
        .lm-s-tile{padding:1rem 1.15rem;border-radius:.75rem;border:1px solid var(--lm-border,#BBB4AF);background:var(--lm-surface,#fff)}
        .lm-s-tile[data-tone=a]{background:color-mix(in srgb,var(--lm-chart-2,#4A7A9B) 12%,var(--lm-surface,#fff))}
        .lm-s-tile[data-tone=b]{background:color-mix(in srgb,var(--lm-accent-soft,#E3AA8D) 35%,var(--lm-surface,#fff))}
        .lm-s-tile h3{margin:0;font-size:.82rem;font-weight:600}
        .lm-s-tile .v{margin:.35rem 0 .2rem;font-family:var(--lm-font-display,Marcellus,Georgia,serif);font-size:1.9rem;line-height:1.1}
        .lm-s-tile .dl{font-size:.8rem;font-weight:600}
        .lm-s-tile .dl.up{color:var(--lm-ok,#2F6F4F)} .lm-s-tile .dl.down{color:var(--lm-err,#B3261E)} .lm-s-tile .dl.nd{font-weight:400;opacity:.65}
        .lm-s-card{padding:1rem 1.15rem 1.15rem;border-radius:.75rem;border:1px solid var(--lm-border,#BBB4AF);background:var(--lm-surface,#fff);min-width:0}
        .lm-s-card h2{margin:0 0 .6rem;padding-bottom:.45rem;border-bottom:1px solid var(--lm-border,#BBB4AF);font-family:var(--lm-font-display,Marcellus,Georgia,serif);font-size:1.15rem;font-weight:400;color:var(--lm-link,#894C2D)}
        .lm-s-card h2 small{font-family:var(--lm-font-text,Inter,system-ui,sans-serif);font-size:.75rem;opacity:.65;margin-left:.5rem}
        .lm-s-grid{display:grid;gap:var(--s-gap);grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr))}
        .lm-s-rank{list-style:none;margin:0;padding:0;display:grid;gap:.25rem}
        .lm-s-rank li{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:.75rem;align-items:center;padding:.3rem .5rem;font-size:.85rem;border-radius:.35rem;overflow:hidden}
        .lm-s-rank li::before{content:"";position:absolute;inset:0 auto 0 0;width:var(--w,0%);background:color-mix(in srgb,var(--lm-accent,#C2663A) 16%,transparent);z-index:0}
        .lm-s-rank li>*{position:relative;z-index:1}
        .lm-s-rank .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .lm-s-rank .n small{display:block;opacity:.65;font-size:.72rem}
        .lm-s-rank .c{opacity:.7;font-variant-numeric:tabular-nums;text-align:right;min-width:2.5rem}
        .lm-s-rank .e{font-variant-numeric:tabular-nums;text-align:right;min-width:3rem;font-weight:600}
        .lm-s-empty{margin:0;padding:.75rem .25rem;font-size:.85rem;opacity:.65}
        .lm-s-days{display:flex;align-items:flex-end;gap:2px;height:9rem;padding-top:.5rem}
        .lm-s-days>div{flex:1 1 0;min-width:2px;height:100%;display:flex;align-items:flex-end}
        .lm-s-days i{display:block;width:100%;min-height:2px;height:var(--h,0%);border-radius:2px 2px 0 0;background:var(--lm-chart-1,#C2663A)}
        .lm-s-axis{display:flex;justify-content:space-between;font-size:.72rem;opacity:.65;margin-top:.3rem}
        .lm-s-tw{overflow-x:auto}
        .lm-s-t{width:100%;border-collapse:collapse;font-size:.85rem}
        .lm-s-t th{padding:.35rem .6rem;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.7;white-space:nowrap;border-bottom:1px solid var(--lm-border,#BBB4AF)}
        .lm-s-t td{padding:.45rem .6rem;vertical-align:top;border-bottom:1px solid color-mix(in srgb,var(--lm-border,#BBB4AF) 45%,transparent)}
        .lm-s-t small{display:block;opacity:.65;font-size:.72rem}
        .lm-s-t .path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;word-break:break-all}
        .lm-s-tag{display:inline-block;padding:.05rem .45rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid var(--lm-border,#BBB4AF)}
        .lm-s-tag[data-k=staff]{background:color-mix(in srgb,var(--lm-chart-2,#4A7A9B) 18%,#fff)}
        .lm-s-tag[data-k=inc]{background:color-mix(in srgb,var(--lm-accent-soft,#E3AA8D) 55%,#fff)}
        .lm-s-tag[data-k=cli]{background:color-mix(in srgb,var(--lm-ok,#2F6F4F) 14%,#fff)}
        .lm-s-dot{display:inline-block;width:.55rem;height:.55rem;border-radius:99px;background:var(--lm-ok,#2F6F4F);margin-right:.4rem}
        .lm-s-warn{padding:.8rem 1rem;border-radius:.6rem;border:1px solid var(--lm-border,#BBB4AF);background:color-mix(in srgb,var(--lm-accent-soft,#E3AA8D) 30%,#fff);font-size:.88rem}
        .lm-s-warn code{font-size:.8rem}
        .lm-s-note{font-size:.78rem;opacity:.65;margin:0}
    </style>

    <div class="lm-s">
        <div class="lm-s-bar" role="group" aria-label="Filtri">
            <select wire:model.live="area" aria-label="Che cosa guardare">
                <option value="front">Sito pubblico e area riservata</option>
                <option value="back">Back-office</option>
            </select>
            <select wire:model.live="days" aria-label="Periodo">
                @foreach($ranges as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            @unless($back)
                <label><input type="checkbox" wire:model.live="includeStaff"> Includi le visite dello staff</label>
            @endunless
            @if($d)<span class="lm-s-range">{{ $from }} – {{ $to }} · confronto con il periodo precedente di pari durata</span>@endif
        </div>

        @unless($enabled)
            <div class="lm-s-warn" role="status">Il rilevamento è spento (<code>LAMESS_ANALYTICS=false</code> nel file <code>.env</code>): non si registrano nuove visite.</div>
        @endunless

        @if(! $ready)
            <div class="lm-s-warn" role="alert"><b>Mancano le tabelle delle statistiche.</b> Il sito funziona normalmente, ma non registra ancora nulla. Esegui lo script SQL della migrazione <code>2026_11_15_100000_site_analytics</code> in phpMyAdmin (lo trovi in «Registro aggiornamenti» e in «Messa online») e ricarica questa pagina.</div>
        @else
            @if(! $back && ! $siteOpen)
                <div class="lm-s-warn" role="status">Il sito è ancora chiuso al pubblico (pagina «in arrivo»): qui compaiono solo le visite di chi ha già un accesso al back-office. Le visite del pubblico si contano da quando apri il sito.</div>
            @endif

            <section class="lm-s-card" aria-labelledby="st-online" wire:poll.30s>
                <h2 id="st-online">Online adesso <small>{{ count($d['online']) }} @if(count($d['online']) === 1) persona attiva @else persone attive @endif negli ultimi {{ (int) config('lamess.analytics.online_minutes', 5) }} minuti · si aggiorna da solo</small></h2>
                @if(empty($d['online']))
                    <p class="lm-s-empty">Nessuno in questo momento.</p>
                @else
                    <div class="lm-s-tw"><table class="lm-s-t">
                        <thead><tr><th>Chi</th><th>Dove si trova</th><th>Da dove arriva</th><th>Dispositivo</th><th>Luogo</th><th>Ultima attività</th></tr></thead>
                        <tbody>
                        @foreach($d['online'] as $o)
                            <tr>
                                <td><span class="lm-s-dot" aria-hidden="true"></span><b>{{ $o['who'] }}</b> <span class="lm-s-tag" data-k="{{ $kindTone[$o['kind']] ?? 'vis' }}">{{ $o['kind'] }}@if($o['code']) {{ $o['code'] }}@endif</span>@if($o['email'])<small>{{ $o['email'] }}</small>@endif</td>
                                <td><span class="path">{{ $o['path'] }}</span><small>{{ $o['area'] === 'back' ? 'Back-office' : 'Sito' }} · {{ $o['hits'] }} {{ $o['hits'] === 1 ? 'pagina' : 'pagine' }} in questa visita</small></td>
                                <td>{{ $o['source'] }}@if($o['referrer_host'])<small>{{ $o['referrer_host'] }}</small>@endif</td>
                                <td>{{ $devices[$o['device']] ?? $o['device'] }}<small>{{ $o['browser'] }} · {{ $o['os'] }}</small></td>
                                <td>{{ $o['place'] ?? '—' }}</td>
                                <td>{{ $o['last_seen_at']->copy()->locale('it')->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <div class="lm-s-tiles">
                @foreach($tiles as [$label, $value, $delta, $tone])
                    <div class="lm-s-tile" data-tone="{{ $tone }}">
                        <h3>{{ $label }}</h3>
                        <p class="v">{{ $value }}</p>
                        @if($delta === null)
                            <span class="dl nd">—</span>
                        @else
                            <span class="dl {{ $delta >= 0 ? 'up' : 'down' }}">{{ $delta >= 0 ? '↑' : '↓' }} {{ number_format(abs($delta), 1, ',', '.') }}% <span style="font-weight:400;opacity:.7">dal periodo precedente</span></span>
                        @endif
                    </div>
                @endforeach
            </div>

            <section class="lm-s-card" aria-labelledby="st-days">
                <h2 id="st-days">Visite giorno per giorno</h2>
                @if($d['tiles']['visits']['value'] === 0)
                    <p class="lm-s-empty">Nessuna visita nel periodo.</p>
                @else
                    <div class="lm-s-days" role="img" aria-label="Visite per giorno">
                        @foreach($daily as $day)
                            <div title="{{ $day['label'] }}: {{ $day['visits'] }} visite · {{ $day['hits'] }} pagine viste"><i style="--h:{{ round($day['visits'] * 100 / $maxDay) }}%"></i></div>
                        @endforeach
                    </div>
                    <div class="lm-s-axis"><span>{{ $daily[0]['label'] }}</span><span>{{ $daily[intdiv(count($daily), 2)]['label'] }}</span><span>{{ end($daily)['label'] }}</span></div>
                @endif
            </section>

            @if($back)
                <section class="lm-s-card" aria-labelledby="st-people">
                    <h2 id="st-people">Chi ha lavorato nel back-office</h2>
                    @if(empty($d['people']))
                        <p class="lm-s-empty">Nessuna attività nel periodo.</p>
                    @else
                        <div class="lm-s-tw"><table class="lm-s-t">
                            <thead><tr><th>Utente</th><th>Pagine viste</th><th>Pagine più usate</th><th>Ultimo accesso</th></tr></thead>
                            <tbody>
                            @foreach($d['people'] as $p)
                                <tr>
                                    <td><b>{{ $p['name'] }}</b>@if($p['email'])<small>{{ $p['email'] }}</small>@endif</td>
                                    <td>{{ $n0($p['hits']) }}</td>
                                    <td>@foreach($p['top_pages'] as $pg)<span class="path">{{ $pg }}</span>@unless($loop->last)<br>@endunless @endforeach</td>
                                    <td>{{ $fmt($p['last_at']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>
            @endif

            <div class="lm-s-grid">
                @unless($back)
                    <section class="lm-s-card" aria-labelledby="st-src"><h2 id="st-src">Come arrivano <small>visite per fonte</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['sources'], 'empty' => 'Nessuna visita nel periodo.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-med"><h2 id="st-med">Tipo di traffico</h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['mediums']])</section>
                    <section class="lm-s-card" aria-labelledby="st-ref"><h2 id="st-ref">Siti che ci mandano visite</h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['referrers'], 'empty' => 'Nessun sito di provenienza nel periodo.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-camp"><h2 id="st-camp">Campagne <small>parametro utm_campaign</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['campaigns'], 'empty' => 'Nessuna campagna: aggiungi ?utm_source=…&utm_campaign=… ai link che condividi.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-code"><h2 id="st-code">Link degli incaricati <small>codice referral</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['referral_codes'], 'empty' => 'Nessuna visita da link referral nel periodo.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-land"><h2 id="st-land">Pagine d'ingresso <small>dove atterrano</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['landing']])</section>
                @endunless
                <section class="lm-s-card" aria-labelledby="st-pages"><h2 id="st-pages">Pagine più viste</h2>
                    @include('filament.pages.partials.stats-list', ['rows' => array_map(fn ($r) => $r + ['sub' => $n0($r['visits']).' '.($r['visits'] === 1 ? 'visita' : 'visite')], $d['pages'])])</section>
                @unless($back)
                    <section class="lm-s-card" aria-labelledby="st-search"><h2 id="st-search">Ricerche nel negozio <small>{{ $n0($d['searches']['total']) }} nel periodo</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => array_map(fn ($r) => ['label' => $r['label'], 'count' => $r['count'], 'share' => null, 'sub' => $r['empty'] > 0 ? $r['empty'].' senza risultati' : null], $d['searches']['top']), 'empty' => 'Nessuna ricerca nel periodo.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-empty"><h2 id="st-empty">Ricerche senza risultati <small>cosa cercano e non trovano</small></h2>
                        @include('filament.pages.partials.stats-list', ['rows' => array_map(fn ($r) => $r + ['share' => null], $d['searches']['empty']), 'empty' => 'Tutte le ricerche hanno dato risultati.'])</section>
                    <section class="lm-s-card" aria-labelledby="st-geo"><h2 id="st-geo">Paesi</h2>
                        @if(! $d['has_geo'])
                            <p class="lm-s-empty">Il tuo hosting non comunica il paese dei visitatori. Compare se il sito passa da un servizio come Cloudflare (intestazione <code>CF-IPCountry</code>).</p>
                        @else
                            @include('filament.pages.partials.stats-list', ['rows' => $d['countries']])
                        @endif</section>
                    <section class="lm-s-card" aria-labelledby="st-city"><h2 id="st-city">Città</h2>
                        @include('filament.pages.partials.stats-list', ['rows' => $d['cities'], 'empty' => 'Le città si vedono solo se l\'hosting le comunica (Cloudflare: «Aggiungi le intestazioni di localizzazione»).'])</section>
                @endunless
                <section class="lm-s-card" aria-labelledby="st-dev"><h2 id="st-dev">Dispositivi</h2>
                    @include('filament.pages.partials.stats-list', ['rows' => $mapDevice($d['devices'])])</section>
                <section class="lm-s-card" aria-labelledby="st-br"><h2 id="st-br">Browser e app</h2>
                    @include('filament.pages.partials.stats-list', ['rows' => $d['browsers']])</section>
                <section class="lm-s-card" aria-labelledby="st-os"><h2 id="st-os">Sistemi operativi</h2>
                    @include('filament.pages.partials.stats-list', ['rows' => $d['systems']])</section>
            </div>

            <section class="lm-s-card" aria-labelledby="st-recent">
                <h2 id="st-recent">Ultime visite</h2>
                @if(empty($d['recent']))
                    <p class="lm-s-empty">Nessuna visita nel periodo.</p>
                @else
                    <div class="lm-s-tw"><table class="lm-s-t">
                        <thead><tr><th>Quando</th><th>Chi</th><th>Entrata</th><th>Pagine</th><th>Fonte</th><th>Dispositivo</th><th>Luogo</th></tr></thead>
                        <tbody>
                        @foreach($d['recent'] as $v)
                            <tr>
                                <td>{{ $fmt($v['started_at']) }}</td>
                                <td><b>{{ $v['who'] }}</b> <span class="lm-s-tag" data-k="{{ $kindTone[$v['kind']] ?? 'vis' }}">{{ $v['kind'] }}</span></td>
                                <td><span class="path">{{ $v['landing'] }}</span></td>
                                <td>{{ $v['hits'] }}</td>
                                <td>{{ $v['source'] }}@if($v['referrer_host'])<small>{{ $v['referrer_host'] }}</small>@endif</td>
                                <td>{{ $devices[$v['device']] ?? $v['device'] }}<small>{{ $v['browser'] }}</small></td>
                                <td>{{ $v['place'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <p class="lm-s-note">Senza servizi esterni e senza cookie in più. Si registrano solo pagine normali (niente bot, immagini o richieste interne), l'indirizzo IP si conserva troncato e i dati si cancellano da soli dopo {{ $retention }} giorni. Le visite sono sessioni con pause sotto i 30 minuti. Orari in ora italiana.</p>
        @endif
    </div>
</x-filament-panels::page>
