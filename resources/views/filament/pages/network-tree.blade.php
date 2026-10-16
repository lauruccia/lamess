<x-filament-panels::page>
    <style>
        .lt { --lt-card:#fff; --lt-line:#BBB4AF; --lt-muted:#6b635d; --lt-acc:#C2663A; --lt-link:#894C2D; --lt-cream:#FCF6F3; --lt-ink:#13110E; --lt-node:#fff; }
        .dark .lt { --lt-card:#201C18; --lt-line:#4a423b; --lt-muted:#a79d94; --lt-link:#E3AA8D; --lt-cream:#1b1814; --lt-ink:#F3ECE7; --lt-node:#2a2520; }
        .lt { color:var(--lt-ink); display:flex; flex-direction:column; gap:.6rem; } [x-cloak]{display:none !important}
        .lt-bar { background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.75rem; padding:.55rem .8rem; display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
        .lt-bar .sp { flex:1; } .lt-sub { color:var(--lt-muted); font-size:.78rem; }
        .lt button, .lt input[type=search] { font:inherit; color:inherit; background:var(--lt-cream); border:1px solid var(--lt-line); border-radius:.5rem; padding:.3rem .7rem; }
        .lt button { cursor:pointer; font-weight:600; } .lt button:hover { border-color:var(--lt-acc); } .lt button.pri { background:var(--lt-ink); color:var(--lt-card); border-color:var(--lt-ink); }
        .lt input[type=search] { width:15rem; }
        .lt-sr { position:absolute; z-index:30; background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.5rem; max-height:16rem; overflow:auto; min-width:18rem; display:none; box-shadow:0 4px 14px rgba(0,0,0,.15); }
        .lt-sr div { padding:.35rem .7rem; cursor:pointer; } .lt-sr div:hover { background:var(--lt-cream); }
        .lt-main { display:flex; min-height:0; }
        .lt-cv { flex:1; position:relative; background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.75rem; overflow:hidden; cursor:grab; touch-action:none; height:calc(100vh - 13.5rem); min-height:30rem; background-image:radial-gradient(var(--lt-line) .6px, transparent .6px); background-size:22px 22px; }
        .lt-cv.drag { cursor:grabbing; } .lt-cv svg { width:100%; height:100%; display:block; }
        .lt-zoom { position:absolute; left:.7rem; top:.7rem; display:flex; gap:.25rem; align-items:center; background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.6rem; padding:.2rem; z-index:5; }
        .lt-zoom span { min-width:2.6rem; text-align:center; color:var(--lt-muted); font-weight:600; font-size:.8rem; }
        .lt-hint { position:absolute; right:.8rem; top:.9rem; color:var(--lt-muted); font-size:.75rem; z-index:5; }
        .lt-mini { position:absolute; right:.8rem; bottom:.8rem; width:11rem; height:7rem; background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.5rem; z-index:5; opacity:.92; cursor:pointer; }
        .lt-mini svg { width:100%; height:100%; display:block; }
        .lt-bg { position:fixed; inset:0; z-index:10000; background:rgba(19,17,14,.5); display:flex; align-items:center; justify-content:center; padding:1rem; }
        .lt-modal { background:var(--lt-card); color:var(--lt-ink); border:1px solid var(--lt-line); border-radius:1rem; width:min(44rem,96vw); max-height:90vh; overflow:auto; padding:1.2rem 1.4rem; box-shadow:0 20px 60px rgba(0,0,0,.35); position:relative; }
        .lt-modal .x { position:absolute; right:.8rem; top:.7rem; width:2rem; height:2rem; padding:0; font-size:1.2rem; line-height:1; }
        .lt-modal h2 { font-size:1.35rem; margin:0 2.4rem 0 0; } .lt-empty { color:var(--lt-muted); }
        .lt.full { position:fixed; inset:0; z-index:9999; background:var(--lt-cream); padding:.7rem; overflow:hidden; }
        .lt.full .lt-main { flex:1; } .lt.full .lt-cv { height:auto; min-height:0; }
        .lt-leg { background:var(--lt-card); border:1px solid var(--lt-line); border-radius:.75rem; padding:.5rem .9rem; display:flex; flex-wrap:wrap; gap:.3rem 1.3rem; justify-content:center; font-size:.8rem; }
        .lt-leg i { display:inline-block; width:.7rem; height:.7rem; border-radius:.2rem; margin-right:.35rem; vertical-align:-1px; }
        .lt a.crumb { color:var(--lt-link); cursor:pointer; font-weight:600; }
        .lt .nd { cursor:pointer; } .lt .nd rect.b { stroke-width:1.5; filter:drop-shadow(0 1px 2px rgba(0,0,0,.14)); } .lt .nd.sel rect.b { stroke-width:3; stroke:var(--lt-acc) !important; }
        .lt .nd.dim { opacity:.5; } .lt .nd.hit rect.b { stroke:#A98625 !important; stroke-width:3; }
        .lt .nd text { font-family:inherit; pointer-events:none; } .lt .tg { cursor:pointer; }
        .lt .e { fill:none; stroke:var(--lt-line); stroke-width:1.4; }
    </style>

    <div class="lt" x-data="networkTree(@js($this->treeData()))" wire:ignore>
        <div class="lt-bar">
            <span class="lt-sub"><span x-text="total"></span> · dati di carriera: <span x-text="meta.period || 'nessun periodo'"></span> (<span x-text="meta.source || '—'"></span>)</span>
            <span class="sp"></span>
            <input type="search" x-ref="q" placeholder="Cerca incaricato o codice…" autocomplete="off" aria-label="Cerca nell'albero" @input="search()">
            <div class="lt-sr" x-ref="sr"></div>
            <button type="button" @click="levels(3)">Livelli 1–3</button>
            <button type="button" @click="levels(6)">Livelli 1–6</button>
            <button type="button" @click="expandAll()">Espandi tutto</button>
            <label class="lt-sub"><input type="checkbox" x-model="showTerminated" @change="draw(); fit()"> mostra cessati</label>
            <button type="button" class="pri" @click="toggleFull()" x-text="full ? '✕ Esci da schermo intero' : '⛶ Schermo intero'"></button>
        </div>
        <div class="lt-bar"><span x-html="crumb"></span><span class="lt-sub">Il colore indica la qualifica · barra in basso = avanzamento verso la qualifica successiva · clic per i dettagli · ▾/▸ apre e chiude il ramo · doppio clic apre l'albero di quel incaricato</span></div>

        <div class="lt-main">
            <div class="lt-cv" x-ref="cv">
                <div class="lt-zoom"><button type="button" @click="zoom(.8)" aria-label="Riduci">−</button><span x-text="Math.round(k*100)+'%'"></span><button type="button" @click="zoom(1.25)" aria-label="Ingrandisci">+</button><button type="button" @click="fit()">Adatta</button></div>
                <div class="lt-hint">Trascina per spostarti · rotella per zoomare</div>
                <svg x-ref="svg" role="img" aria-label="Albero della rete"><g x-ref="g"></g></svg>
                <div class="lt-mini" x-ref="mini" title="Panoramica: clic per spostarti" x-show="miniOn"><svg x-ref="msvg"></svg></div>
            </div>
        </div>
        <div class="lt-leg" x-html="legend"></div>
        <div class="lt-bg" x-cloak x-show="modal && sel" @click.self="closeModal()" @keydown.escape.window="closeModal()">
            <div class="lt-modal" role="dialog" aria-modal="true" aria-live="polite">
                <button type="button" class="x" @click="closeModal()" aria-label="Chiudi">✕</button>
                <template x-if="sel">
                    <div>
                        <h2 x-text="sel.name"></h2>
                        <div class="lt-sub" x-text="sel.code + ' · ' + statusName(sel.status) + (sel.since ? ' · dal ' + sel.since : '')"></div>
                        <div class="lt-sub" x-text="'Team 1° liv. ' + (kids[sel.id] || []).length + ' · team ' + sel.team + ' · clienti ' + sel.clients"></div>
                        <div style="margin:.7rem 0;display:flex;gap:.4rem;flex-wrap:wrap">
                            <a :href="sel.url"><button class="pri" type="button">Scheda incaricato</button></a>
                            <button type="button" @click="focus(sel.id); closeModal()">Apri albero di questo incaricato</button>
                        </div>
                        <div x-html="detailHtml"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        function networkTree(data) {
            const NS = 'http://www.w3.org/2000/svg', W = 184, H = 58, GX = 22, GY = 70;
            return {
                meta: data.meta, ranks: data.ranks, nodes: [], by: {}, kids: {}, ROOT: 0, root: 0, open: {}, sel: null, detailHtml: '', modal: false, full: false, miniOn: true, showTerminated: false, hits: {},
                tx: 0, ty: 0, k: 1, crumb: '', total: '', legend: '',
                init() {
                    const top = { id: 0, parent: null, code: '', name: 'Rete Lamèss', status: 'active', rank: null, active: false, pr: 0, next: null, pct: 100, missing: 0, clients: 0, team: data.nodes.length, since: '', url: '#', virtual: true };
                    this.nodes = [top].concat(data.nodes.map(n => Object.assign({}, n, { parent: (n.parent && n.parent !== n.id) ? n.parent : 0 })));
                    this.nodes.forEach(n => { this.by[n.id] = n; this.kids[n.id] = []; });
                    this.nodes.forEach(n => { if (n.id !== 0) { (this.by[n.parent] ? this.kids[n.parent] : this.kids[0]).push(n.id); } });
                    this.open[0] = true;
                    this.legend = this.ranks.map(r => '<span><i style="background:' + r.color + '"><\/i>' + this.esc(r.name) + ' (' + data.nodes.filter(n => n.rank === r.code).length + ')<\/span>').join('')
                        + '<span><i style="background:#2E9E5B;border-radius:50%"><\/i>Attivo nel mese<\/span><span><i style="background:#C62828;border-radius:50%"><\/i>Sospeso / cessato<\/span>';
                    const cv = this.$refs.cv;
                    cv.addEventListener('wheel', e => { e.preventDefault(); const r = cv.getBoundingClientRect(); this.zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX - r.left, e.clientY - r.top); }, { passive: false });
                    let dr = null;
                    cv.addEventListener('pointerdown', e => { dr = { x: e.clientX, y: e.clientY, tx: this.tx, ty: this.ty }; cv.classList.add('drag'); });
                    addEventListener('pointermove', e => { if (dr) { this.tx = dr.tx + e.clientX - dr.x; this.ty = dr.ty + e.clientY - dr.y; this.tf(); } });
                    addEventListener('pointerup', () => { dr = null; cv.classList.remove('drag'); });
                    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && this.full) { this.full = false; setTimeout(() => this.fit(), 120); } });
                    this.$refs.mini.addEventListener('pointerdown', e => e.stopPropagation());
                    this.$refs.mini.addEventListener('click', e => {
                        if (!this.mv) return; const r = this.$refs.mini.getBoundingClientRect(), c = cv.getBoundingClientRect();
                        const wx = this.mv.vx + (e.clientX - r.left) / r.width * this.mv.vw, wy = this.mv.vy + (e.clientY - r.top) / r.height * this.mv.vh;
                        this.tx = c.width / 2 - wx * this.k; this.ty = c.height / 2 - wy * this.k; this.tf();
                    });
                    this.expandAll(); this.setCrumb();
                },
                esc(t) { return String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); },
                fmt(v) { return (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('it-IT'); },
                rk(code) { return this.ranks.find(r => r.code === code) || { name: 'Nessuna', color: '#B9B1A5' }; },
                statusName(s) { return ({ active: 'Attivo', suspended: 'Sospeso', terminated: 'Cessato', pending: 'In attesa' })[s] || s; },
                vis(id) { const n = this.by[id]; return this.showTerminated || n.status !== 'terminated'; },
                kidsOf(id) { return (this.kids[id] || []).filter(c => this.vis(c)); },
                el(n, a, p) { const e = document.createElementNS(NS, n); for (const x in a) e.setAttribute(x, a[x]); p.appendChild(e); return e; },
                ini(n) { return (n.replace(/[^\p{L} ]/gu, '').trim().split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase()) || '?'; },
                layout() {
                    const pos = {}; let x = 0; const self = this;
                    (function w(id, d) {
                        const c = self.open[id] ? self.kidsOf(id) : [];
                        if (!c.length) { pos[id] = { x: x, y: d }; x += W + GX; return; }
                        c.forEach(i => w(i, d + 1));
                        pos[id] = { x: (pos[c[0]].x + pos[c[c.length - 1]].x) / 2, y: d };
                    })(this.root, 0);
                    return pos;
                },
                draw() {
                    const g = this.$refs.g; g.innerHTML = '';
                    const pos = this.layout(); this.layoutCache = pos; let count = 0;
                    const eg = this.el('g', {}, g), ng = this.el('g', {}, g);
                    for (const id in pos) {
                        const d = this.by[id], P = pos[id]; count++;
                        if (this.open[id]) this.kidsOf(+id).forEach(c => {
                            const q = pos[c], x1 = P.x + W / 2, y1 = P.y * (H + GY) + H, x2 = q.x + W / 2, y2 = q.y * (H + GY), m = (y1 + y2) / 2;
                            this.el('path', { class: 'e', d: `M${x1},${y1}V${m}H${x2}V${y2}` }, eg);
                        });
                        const r = this.rk(d.rank), col = r.color;
                        const gr = this.el('g', { class: 'nd' + (this.sel && this.sel.id == id ? ' sel' : '') + (this.hits[id] ? ' hit' : '') + (d.status === 'terminated' ? ' dim' : ''), 'data-id': id, transform: `translate(${P.x},${P.y * (H + GY)})` }, ng);
                        if (d.virtual) {
                            this.el('rect', { class: 'b', width: W, height: H, rx: 10, fill: 'var(--lt-node)', stroke: 'var(--lt-line)' }, gr);
                            this.el('text', { x: W / 2, y: H / 2 - 2, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: 'var(--lt-ink)' }, gr).textContent = d.name;
                            this.el('text', { x: W / 2, y: H / 2 + 14, 'text-anchor': 'middle', 'font-size': 10.5, fill: 'var(--lt-muted)' }, gr).textContent = this.kidsOf(0).length + ' radici · ' + (this.nodes.length - 1) + ' incaricati';
                        } else {
                            this.el('rect', { class: 'b', width: W, height: H, rx: 10, fill: 'var(--lt-node)', stroke: col }, gr);
                            this.el('rect', { x: 0, y: 0, width: 6, height: H, rx: 3, fill: col }, gr);
                            this.el('circle', { cx: 28, cy: H / 2 - 3, r: 16, fill: col }, gr);
                            this.el('text', { x: 28, y: H / 2 + 1, 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 700, fill: '#fff' }, gr).textContent = this.ini(d.name);
                            let nm = d.name; if (nm.length > 20) nm = nm.slice(0, 19) + '…';
                            this.el('text', { x: 52, y: H / 2 - 9, 'font-size': 12, 'font-weight': 700, fill: 'var(--lt-ink)' }, gr).textContent = nm;
                            this.el('text', { x: 52, y: H / 2 + 5, 'font-size': 10.5, 'font-weight': 600, fill: 'var(--lt-muted)' }, gr).textContent = d.hide_pr ? r.name : r.name + ' · ' + this.fmt(d.pr) + ' PR';
                            if (d.status !== 'active') this.el('circle', { cx: W - 9, cy: 9, r: 4.5, fill: '#C62828' }, gr); else if (d.active) this.el('circle', { cx: W - 9, cy: 9, r: 4.5, fill: '#2E9E5B' }, gr);
                            // avanzamento verso la qualifica successiva
                            if (!d.hide_pr) this.el('rect', { x: 52, y: H - 12, width: W - 64, height: 5, rx: 2.5, fill: 'rgba(127,127,127,.25)' }, gr);
                            if (d.hide_pr) {} else if (d.next) this.el('rect', { x: 52, y: H - 12, width: Math.max(2, (W - 64) * d.pct / 100), height: 5, rx: 2.5, fill: d.pct >= 100 ? '#2E9E5B' : '#C2663A' }, gr);
                            else this.el('rect', { x: 52, y: H - 12, width: W - 64, height: 5, rx: 2.5, fill: '#2E9E5B' }, gr);
                            const tt = this.el('title', {}, gr); tt.textContent = d.next ? d.hide_pr ? d.name + ' — prossima qualifica: ' + d.next : d.name + ' — verso ' + d.next + ': ' + d.pct + '% (mancano ' + this.fmt(d.missing) + ' PR)' : d.name + ' — qualifica massima';
                        }
                        const nk = this.kidsOf(+id).length;
                        if (nk) {
                            const o = !!this.open[id];
                            const t = this.el('g', { class: 'tg', transform: `translate(${W / 2},${H + 1})` }, gr);
                            this.el('rect', { x: -28, y: -1, width: 56, height: 17, rx: 8.5, fill: 'var(--lt-card)', stroke: '#BBB4AF' }, t);
                            this.el('text', { 'text-anchor': 'middle', y: 11, 'font-size': 10, 'font-weight': 700, fill: 'var(--lt-link)' }, t).textContent = (o ? '▾ ' : '▸ ') + nk + (o ? '' : ' · ' + d.team);
                            t.addEventListener('click', e => { e.stopPropagation(); this.open[id] = !this.open[id]; this.draw(); });
                            t.addEventListener('pointerdown', e => e.stopPropagation());
                        }
                        gr.addEventListener('click', () => this.select(+id));
                        gr.addEventListener('dblclick', () => { if (+id !== 0) this.focus(+id); });
                        gr.addEventListener('pointerdown', e => e.stopPropagation());
                    }
                    this.total = (this.nodes.length - 1) + ' incaricati · ' + (count - (pos[this.root] && this.root === 0 ? 1 : 0)) + ' visibili';
                    this.tf();
                    return pos;
                },
                closeModal() { this.modal = false; },
                toggleFull() {
                    this.full = !this.full;
                    const el = this.$root;
                    if (this.full && el.requestFullscreen) el.requestFullscreen().catch(() => {});
                    if (!this.full && document.fullscreenElement) document.exitFullscreen().catch(() => {});
                    setTimeout(() => this.fit(), 120);
                },
                tf() { this.$refs.g.setAttribute('transform', `translate(${this.tx},${this.ty}) scale(${this.k})`); this.mini(); },
                mini() {
                    const g = this.$refs.g, m = this.$refs.msvg; if (!m || !g.firstChild) return;
                    const b = g.getBBox(), cv = this.$refs.cv.getBoundingClientRect(); if (!b.width) return;
                    const pad = 40, vx = b.x - pad, vy = b.y - pad, vw = b.width + pad * 2, vh = b.height + pad * 2;
                    m.setAttribute('viewBox', `${vx} ${vy} ${vw} ${vh}`);
                    const dots = this.layoutCache || {}; let h = '';
                    for (const id in dots) h += `<rect x="${dots[id].x}" y="${dots[id].y * (H + GY)}" width="${W}" height="${H}" rx="8" fill="${this.rk(this.by[id].rank).color}"/>`;
                    const vp = { x: -this.tx / this.k, y: -this.ty / this.k, w: cv.width / this.k, h: cv.height / this.k };
                    h += `<rect x="${vp.x}" y="${vp.y}" width="${vp.w}" height="${vp.h}" fill="rgba(194,102,58,.12)" stroke="#C2663A" stroke-width="${vw / 90}"/>`;
                    m.innerHTML = h; this.mv = { vx, vy, vw, vh };
                },
                fit() {
                    const g = this.$refs.g, cv = this.$refs.cv, b = g.getBBox(), r = cv.getBoundingClientRect();
                    this.k = Math.max(.08, Math.min(1.25, (r.width - 60) / (b.width || 1), (r.height - 90) / (b.height || 1)));
                    this.tx = (r.width - b.width * this.k) / 2 - b.x * this.k;
                    this.ty = 60 - b.y * this.k + Math.max(0, (r.height - 90 - b.height * this.k) / 4);
                    this.tf();
                },
                zoom(f, cx, cy) {
                    const r = this.$refs.cv.getBoundingClientRect(); cx = cx ?? r.width / 2; cy = cy ?? r.height / 2;
                    const nk = Math.min(2.5, Math.max(.05, this.k * f));
                    this.tx = cx - (cx - this.tx) * nk / this.k; this.ty = cy - (cy - this.ty) * nk / this.k; this.k = nk; this.tf();
                },
                setCrumb() {
                    this.crumb = this.root === 0 ? '<b>Rete completa<\/b>' : '<a class="crumb" data-home>← Rete completa<\/a> · Albero di <b>' + this.esc(this.by[this.root].name) + '<\/b>';
                    this.$nextTick(() => { const a = this.$root.querySelector('[data-home]'); if (a) a.onclick = () => this.focus(0); });
                },
                focus(id) { this.root = id; this.open[id] = true; this.setCrumb(); this.draw(); this.fit(); },
                depth(id) { let d = 0, x = id; while (x && this.by[x]) { x = this.by[x].parent; d++; } return d; },
                levels(m) { this.open = {}; const base = this.depth(this.root); this.nodes.forEach(n => { if (this.kids[n.id].length && this.depth(n.id) - base < m) this.open[n.id] = true; }); this.setCrumb(); this.draw(); this.fit(); },
                expandAll() { this.nodes.forEach(n => { if (this.kids[n.id].length) this.open[n.id] = true; }); this.draw(); this.fit(); },
                async select(id) {
                    this.sel = this.by[id]; this.draw();
                    if (id === 0) { this.sel = null; return; }
                    this.modal = true;
                    this.detailHtml = '<p class="lt-sub">Carico…<\/p>';
                    try { this.detailHtml = await this.$wire.progressHtml(id); } catch (e) { this.detailHtml = '<p>Impossibile caricare il dettaglio.<\/p>'; }
                },
                search() {
                    const q = this.$refs.q.value.trim().toLowerCase(), sr = this.$refs.sr;
                    if (q.length < 2) { sr.style.display = 'none'; return; }
                    const m = this.nodes.filter(d => !d.virtual && (d.name.toLowerCase().includes(q) || d.code.toLowerCase().includes(q))).slice(0, 25);
                    sr.innerHTML = m.map(d => '<div data-i="' + d.id + '">' + this.esc(d.name) + ' <span class="lt-sub">' + this.esc(d.code) + ' · liv. ' + (this.depth(d.id) - 1) + '<\/span><\/div>').join('') || '<div class="lt-sub">Nessun risultato<\/div>';
                    const r = this.$refs.q.getBoundingClientRect(), p = this.$root.getBoundingClientRect();
                    sr.style.left = (r.left - p.left) + 'px'; sr.style.top = (r.bottom - p.top + 4) + 'px'; sr.style.display = 'block';
                    sr.onclick = e => {
                        const i = e.target.closest('[data-i]')?.dataset.i; if (!i) return;
                        sr.style.display = 'none'; this.$refs.q.value = '';
                        let x = this.by[i].parent; while (x !== null && x !== undefined && this.by[x]) { this.open[x] = true; x = this.by[x].parent; }
                        if (!this.inSub(+i)) { this.root = 0; this.setCrumb(); }
                        this.hits = { [i]: true };
                        const pos = this.draw(); this.select(+i);
                        const P = pos[i], r2 = this.$refs.cv.getBoundingClientRect();
                        this.k = 1; this.tx = r2.width / 2 - (P.x + W / 2); this.ty = r2.height / 2 - (P.y * (H + GY) + H / 2); this.tf();
                    };
                },
                inSub(i) { let x = i; while (x !== null && x !== undefined && this.by[x]) { if (x === this.root) return true; x = this.by[x].parent; } return false; },
            };
        }
    </script>
</x-filament-panels::page>
