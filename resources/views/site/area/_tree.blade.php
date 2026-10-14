{{-- Albero grafico del proprio ramo: stessa grafica dell'albero del back-office, radice = l'incaricato. Dati in $tree (NetworkTree). Se mancano, resta l'elenco ad albero. --}}
@if($tree)
@php($counts = collect($tree['nodes'])->countBy('rank'))
<section class="panel">
 <header><h2>Albero della rete</h2><span class="muted" style="font-size:13px">Clic su una persona per i dettagli · ▾/▸ apre e chiude il ramo</span></header>
 <div class="panel-body" style="padding:12px">
  <div class="lt" id="lt" data-detail="/area/rete/__ID__/dettaglio">
   <div class="lt-bar">
    <span class="lt-sub" data-r="total"></span><span class="lt-sub">· dati di carriera: {{ $tree['meta']['period'] ?? 'nessun periodo' }}</span>
    <span class="sp"></span>
    <input type="search" data-r="q" placeholder="Cerca incaricato o codice…" autocomplete="off" aria-label="Cerca nell'albero">
    <div class="lt-sr" data-r="sr"></div>
    <button type="button" data-a="l3">Livelli 1–3</button>
    <button type="button" data-a="l6">Livelli 1–6</button>
    <button type="button" data-a="all">Espandi tutto</button>
    <label class="lt-sub"><input type="checkbox" data-r="term"> mostra cessati</label>
    <button type="button" class="pri" data-a="full">⛶ Schermo intero</button>
   </div>
   <div class="lt-bar"><span data-r="crumb"></span><span class="lt-sub">Il colore indica la qualifica · barra in basso = avanzamento verso la qualifica successiva · doppio clic apre l'albero di quella persona</span></div>
   <div class="lt-cv" data-r="cv">
    <div class="lt-zoom"><button type="button" data-a="zout" aria-label="Riduci">−</button><span data-r="zoom">100%</span><button type="button" data-a="zin" aria-label="Ingrandisci">+</button><button type="button" data-a="fit">Adatta</button></div>
    <div class="lt-hint">Trascina per spostarti · rotella per zoomare</div>
    <svg role="img" aria-label="Albero della rete"><g data-r="g"></g></svg>
    <div class="lt-mini" data-r="mini" title="Panoramica: clic per spostarti"><svg data-r="msvg"></svg></div>
   </div>
   <div class="lt-leg">
    <div class="rank-legend">
     @foreach($tree['ranks'] as $r)<span class="rank-chip" style="--stone: {{ $r['color'] }}">{{ $r['name'] }} ({{ $counts[$r['code']] ?? 0 }})</span>@endforeach
    </div>
    <span><i class="dot" style="background:#2E9E5B"></i>Attivo nel mese</span>
    <span><i class="dot" style="background:#C62828"></i>Sospeso / cessato</span>
   </div>
   <div class="lt-bg" data-r="bg" hidden>
    <div class="lt-modal" role="dialog" aria-modal="true" aria-live="polite">
     <button type="button" class="x" data-r="x" aria-label="Chiudi">✕</button>
     <div data-r="mbody"></div>
    </div>
   </div>
  </div>
 </div>
</section>
<script type="application/json" id="lt-data">@json($tree)</script>
<style>
 .lt{--lt-card:var(--white,#fff);--lt-line:#BBB4AF;--lt-muted:var(--muted,#6a615a);--lt-acc:#C2663A;--lt-link:var(--link,#894C2D);--lt-cream:#FCF6F3;--lt-ink:var(--ink,#13110E);--lt-node:#fff;color:var(--lt-ink);display:flex;flex-direction:column;gap:.6rem;position:relative}
 .lt-bar{background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.75rem;padding:.5rem .75rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center}
 .lt-bar .sp{flex:1}.lt-sub{color:var(--lt-muted);font-size:.78rem}
 .lt button,.lt input[type=search]{font:inherit;font-size:.85rem;color:inherit;background:var(--lt-cream);border:1px solid var(--lt-line);border-radius:.5rem;padding:.3rem .7rem}
 .lt button{cursor:pointer;font-weight:600;margin:0;box-shadow:none}.lt button:hover{border-color:var(--lt-acc)}
 .lt button.pri{background:var(--lt-ink);color:var(--lt-card);border-color:var(--lt-ink)}
 .lt input[type=search]{flex:1 1 11rem;min-width:9rem;max-width:16rem}
 .lt-sr{position:absolute;z-index:30;background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.5rem;max-height:16rem;overflow:auto;min-width:18rem;display:none;box-shadow:0 4px 14px rgba(0,0,0,.15)}
 .lt-sr div{padding:.35rem .7rem;cursor:pointer;font-size:.88rem}.lt-sr div:hover{background:var(--lt-cream)}
 .lt-cv{position:relative;background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.75rem;overflow:hidden;cursor:grab;touch-action:none;height:min(72vh,680px);min-height:380px;background-image:radial-gradient(var(--lt-line) .6px,transparent .6px);background-size:22px 22px}
 .lt-cv.drag{cursor:grabbing}.lt-cv>svg{width:100%;height:100%;display:block}
 .lt-zoom{position:absolute;left:.6rem;top:.6rem;display:flex;gap:.25rem;align-items:center;background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.6rem;padding:.2rem;z-index:5}
 .lt-zoom span{min-width:2.6rem;text-align:center;color:var(--lt-muted);font-weight:600;font-size:.8rem}
 .lt-hint{position:absolute;right:.8rem;top:.8rem;color:var(--lt-muted);font-size:.75rem;z-index:5}
 .lt-mini{position:absolute;right:.7rem;bottom:.7rem;width:10rem;height:6.5rem;background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.5rem;z-index:5;opacity:.92;cursor:pointer}
 .lt-mini svg{width:100%;height:100%;display:block}
 .lt.full{position:fixed;inset:0;z-index:9999;background:var(--lt-cream);padding:.7rem;overflow:hidden}
 .lt.full .lt-cv{flex:1;height:auto;min-height:0}
 .lt-leg{background:var(--lt-card);border:1px solid var(--lt-line);border-radius:.75rem;padding:.5rem .9rem;display:flex;flex-wrap:wrap;gap:.3rem 1.2rem;justify-content:center;align-items:center;font-size:.8rem}
 .lt-leg .rank-legend{margin:0;justify-content:center}
 .lt-leg .dot{display:inline-block;width:.7rem;height:.7rem;border-radius:50%;margin-right:.35rem;vertical-align:-1px}
 .lt a.crumb{color:var(--lt-link);cursor:pointer;font-weight:600}
 .lt .nd{cursor:pointer}.lt .nd rect.b{stroke-width:1.5;filter:drop-shadow(0 1px 2px rgba(0,0,0,.14))}
 .lt .nd.me rect.b{stroke-width:2.5}.lt .nd.sel rect.b{stroke-width:3;stroke:var(--lt-acc)!important}
 .lt .nd.dim{opacity:.5}.lt .nd.hit rect.b{stroke:#A98625!important;stroke-width:3}
 .lt .nd text{font-family:inherit;pointer-events:none}.lt .tg{cursor:pointer}
 .lt .e{fill:none;stroke:var(--lt-line);stroke-width:1.4}
 .lt-bg{position:fixed;inset:0;z-index:10000;background:rgba(19,17,14,.5);display:flex;align-items:center;justify-content:center;padding:1rem}
 .lt-bg[hidden]{display:none}
 .lt-modal{background:var(--lt-card);color:var(--lt-ink);border:1px solid var(--lt-line);border-radius:1rem;width:min(40rem,96vw);max-height:90vh;overflow:auto;padding:1.2rem 1.4rem;box-shadow:0 20px 60px rgba(0,0,0,.35);position:relative}
 .lt-modal .x{position:absolute;right:.8rem;top:.7rem;width:2rem;height:2rem;padding:0;font-size:1.1rem;line-height:1}
 .lt-modal h2{font-size:1.3rem;margin:0 2.4rem 0 0}
 .lt-act{margin:.7rem 0;display:flex;gap:.4rem;flex-wrap:wrap}
 .lt-dl{display:grid;grid-template-columns:auto 1fr;gap:.2rem 1rem;margin:.6rem 0;font-size:.9rem}
 .lt-dl dt{color:var(--lt-muted)}.lt-dl dd{margin:0;text-align:right;font-weight:600}
 .lt-modal h4{margin:1rem 0 .4rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;opacity:.65}
 .lt-req{padding:.45rem 0;border-top:1px solid rgba(127,127,127,.2)}
 .lt-rrow{display:flex;justify-content:space-between;gap:.75rem;font-size:.9rem}.lt-rrow>span:last-child{font-weight:600;text-align:right}
 .lt-pb{height:.55rem;border-radius:.3rem;background:rgba(127,127,127,.22);overflow:hidden;margin:.2rem 0 .1rem}
 .lt-pb>i{display:block;height:100%;background:#C2663A}.lt-pb.done>i{background:#2E9E5B}
 .lt-tick{display:inline-block;width:1.2rem;font-weight:700;color:#2E9E5B}.lt-tick.no{color:#C2663A}
 .lt-hint{font-size:.78rem}
 .lt-modal .lt-hint{position:static;opacity:.7;color:inherit}
 .lt-pill{display:inline-block;margin-left:.4rem;padding:.03rem .5rem;border-radius:999px;font-size:.72rem;font-weight:600;border:1px solid currentColor}
 @media (max-width:640px){.lt-hint{display:none}.lt-mini{width:7rem;height:4.5rem}.lt-dl{font-size:.85rem}}
</style>
<script src="/js/lamess-tree.js?v={{ @filemtime(public_path('js/lamess-tree.js')) ?: 1 }}" defer></script>
@else
<section class="panel">
 <header><h2>Albero della rete</h2><span class="muted" style="font-size:13px">Tocca un nome per aprire o chiudere il ramo</span></header>
 <div class="panel-body"><ul class="tree" id="tree">@include('site.area._node', ['ids' => $children[$me->id] ?? []])</ul></div>
</section>
@endif
