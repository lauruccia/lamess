/*
 * Albero grafico di «Il mio ramo» (area incaricato).
 * Stessa grafica dell'albero del back-office: schede per persona, colore della pietra, barra di avanzamento verso la qualifica
 * successiva, ricerca, livelli, schermo intero, minimappa, scheda con i dettagli al clic. Qui la radice sei tu e si vede solo il tuo ramo.
 * JavaScript semplice, senza librerie: i dati arrivano da <script type="application/json" id="lt-data">.
 */
(function () {
  'use strict';

  var box = document.getElementById('lt');
  var dataEl = document.getElementById('lt-data');
  if (!box || !dataEl) { return; }
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

  var NS = 'http://www.w3.org/2000/svg';
  var W = 184, H = 58, GX = 22, GY = 70;
  var nodes = data.nodes || [], ranks = data.ranks || [], ME = data.root;
  var by = {}, kids = {};
  var rootId = ME, open = {}, sel = null, hits = {}, showTerm = false, full = false;
  var tx = 0, ty = 0, k = 1, layoutCache = {}, mv = null, dragged = false, detailToken = 0, clickTimer = null;

  function ref(name) { return box.querySelector('[data-r="' + name + '"]'); }
  var cv = ref('cv'), g = ref('g'), mini = ref('mini'), msvg = ref('msvg'), crumbEl = ref('crumb'), totalEl = ref('total');
  var zoomEl = ref('zoom'), searchEl = ref('q'), sr = ref('sr'), bg = ref('bg'), mbody = ref('mbody'), termEl = ref('term');
  var fullBtn = box.querySelector('[data-a="full"]');

  /* ---------- utilità ---------- */
  function el(name, attrs, parent) {
    var e = document.createElementNS(NS, name);
    for (var a in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, a)) { e.setAttribute(a, attrs[a]); } }
    if (parent) { parent.appendChild(e); }
    return e;
  }
  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function eur(v) { return Number(v || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function rk(code) {
    for (var i = 0; i < ranks.length; i++) { if (ranks[i].code === code) { return ranks[i]; } }
    return { code: code, name: 'Nessuna', color: '#B9B1A5' };
  }
  function statusName(s) { return ({ active: 'Attivo', suspended: 'Sospeso', terminated: 'Cessato', pending: 'In attesa' })[s] || s; }
  function ini(name) {
    var s = String(name || '').replace(/[^A-Za-zÀ-ÖØ-öø-ÿ ]/g, '').trim().split(/\s+/).slice(0, 2).map(function (x) { return x.charAt(0); }).join('').toUpperCase();
    return s || '?';
  }
  /* testo scuro sui colori chiari (perla, cristallo, diamante), bianco sugli scuri */
  function onColor(hex) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) { return '#fff'; }
    var n = parseInt(m[1], 16), r = (n >> 16) & 255, gr = (n >> 8) & 255, b = n & 255;
    return (0.2126 * r + 0.7152 * gr + 0.0722 * b) > 150 ? '#13110E' : '#fff';
  }
  function vis(id) { return showTerm || by[id].status !== 'terminated'; }
  function kidsOf(id) { return (kids[id] || []).filter(vis); }
  function depth(id) { var d = 0, x = id; while (x !== null && x !== undefined && by[x]) { x = by[x].parent; d++; } return d; }
  function lvl(id) { return depth(id) - depth(ME); }

  /* ---------- struttura ---------- */
  nodes.forEach(function (n) { by[n.id] = n; kids[n.id] = []; });
  nodes.forEach(function (n) { if (n.id !== ME && by[n.parent]) { kids[n.parent].push(n.id); } });
  open[ME] = true;

  function layout() {
    var pos = {}, x = 0;
    (function walk(id, d) {
      var c = open[id] ? kidsOf(id) : [];
      if (!c.length) { pos[id] = { x: x, y: d }; x += W + GX; return; }
      c.forEach(function (i) { walk(i, d + 1); });
      pos[id] = { x: (pos[c[0]].x + pos[c[c.length - 1]].x) / 2, y: d };
    })(rootId, 0);
    return pos;
  }

  function draw() {
    g.innerHTML = '';
    var pos = layout(), count = 0;
    layoutCache = pos;
    var eg = el('g', {}, g), ng = el('g', {}, g);
    Object.keys(pos).forEach(function (id) {
      var d = by[id], P = pos[id], isMe = (+id === ME);
      count++;
      if (open[id]) {
        kidsOf(+id).forEach(function (c) {
          var q = pos[c], x1 = P.x + W / 2, y1 = P.y * (H + GY) + H, x2 = q.x + W / 2, y2 = q.y * (H + GY), m = (y1 + y2) / 2;
          el('path', { 'class': 'e', d: 'M' + x1 + ',' + y1 + 'V' + m + 'H' + x2 + 'V' + y2 }, eg);
        });
      }
      var r = rk(d.rank), col = r.color, ink = onColor(col);
      var cls = 'nd' + (sel && sel.id === +id ? ' sel' : '') + (hits[id] ? ' hit' : '') + (d.status === 'terminated' ? ' dim' : '') + (isMe ? ' me' : '');
      var gr = el('g', { 'class': cls, 'data-id': id, transform: 'translate(' + P.x + ',' + (P.y * (H + GY)) + ')' }, ng);
      el('rect', { 'class': 'b', width: W, height: H, rx: 10, fill: 'var(--lt-node)', stroke: col }, gr);
      el('rect', { x: 0, y: 0, width: 6, height: H, rx: 3, fill: col }, gr);
      el('circle', { cx: 28, cy: H / 2 - 3, r: 16, fill: col }, gr);
      el('text', { x: 28, y: H / 2 + 1, 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 700, fill: ink }, gr).textContent = ini(d.name);
      var nm = d.name; if (nm.length > 20) { nm = nm.slice(0, 19) + '…'; }
      el('text', { x: 52, y: H / 2 - 9, 'font-size': 12, 'font-weight': 700, fill: 'var(--lt-ink)' }, gr).textContent = nm;
      var line2 = isMe ? r.name + ' · tu' : r.name + ' · ' + plural(d.clients, 'cliente', 'clienti');
      if (line2.length > 26) { line2 = line2.slice(0, 25) + '…'; }
      el('text', { x: 52, y: H / 2 + 5, 'font-size': 10.5, 'font-weight': 600, fill: 'var(--lt-muted)' }, gr).textContent = line2;
      if (d.status !== 'active') { el('circle', { cx: W - 9, cy: 9, r: 4.5, fill: '#C62828' }, gr); }
      else if (d.active) { el('circle', { cx: W - 9, cy: 9, r: 4.5, fill: '#2E9E5B' }, gr); }
      /* avanzamento verso la qualifica successiva */
      el('rect', { x: 52, y: H - 12, width: W - 64, height: 5, rx: 2.5, fill: 'rgba(127,127,127,.25)' }, gr);
      if (d.next) { el('rect', { x: 52, y: H - 12, width: Math.max(2, (W - 64) * d.pct / 100), height: 5, rx: 2.5, fill: d.pct >= 100 ? '#2E9E5B' : '#C2663A' }, gr); }
      else { el('rect', { x: 52, y: H - 12, width: W - 64, height: 5, rx: 2.5, fill: '#2E9E5B' }, gr); }
      var tip = d.name + ' — ' + r.name;
      if (d.next) { tip += ' — verso ' + d.next + ': ' + d.pct + '%' + (d.missing.length ? ' (mancano ' + d.missing.join(' · ') + ')' : ''); }
      else { tip += ' — qualifica massima'; }
      el('title', {}, gr).textContent = tip;

      var nk = kidsOf(+id).length;
      if (nk) {
        var o = !!open[id];
        var t = el('g', { 'class': 'tg', transform: 'translate(' + (W / 2) + ',' + (H + 1) + ')' }, gr);
        el('rect', { x: -28, y: -1, width: 56, height: 17, rx: 8.5, fill: 'var(--lt-card)', stroke: '#BBB4AF' }, t);
        el('text', { 'text-anchor': 'middle', y: 11, 'font-size': 10, 'font-weight': 700, fill: 'var(--lt-link)' }, t).textContent = (o ? '▾ ' : '▸ ') + nk + (o ? '' : ' · ' + d.team);
        t.addEventListener('click', function (e) { e.stopPropagation(); if (dragged) { return; } open[id] = !open[id]; draw(); });
      }
      /* il clic singolo apre la scheda dopo un attimo, così il doppio clic (apri l'albero di quella persona) non viene coperto dalla scheda */
      gr.addEventListener('click', function () {
        if (dragged) { return; }
        clearTimeout(clickTimer);
        clickTimer = setTimeout(function () { select(+id); }, 260);
      });
      gr.addEventListener('dblclick', function () {
        clearTimeout(clickTimer);
        if (+id !== rootId) { focus(+id); }
      });
    });
    totalEl.textContent = plural(nodes.length - 1, 'persona', 'persone') + ' nel ramo · ' + (count - (rootId === ME ? 1 : 0)) + ' visibili';
    tf();
    return pos;
  }

  /* ---------- vista: spostamento, zoom, minimappa ---------- */
  function tf() {
    g.setAttribute('transform', 'translate(' + tx + ',' + ty + ') scale(' + k + ')');
    if (zoomEl) { zoomEl.textContent = Math.round(k * 100) + '%'; }
    drawMini();
  }
  function drawMini() {
    if (!msvg || !g.firstChild) { return; }
    var b = g.getBBox(), c = cv.getBoundingClientRect();
    if (!b.width) { return; }
    var pad = 40, vx = b.x - pad, vy = b.y - pad, vw = b.width + pad * 2, vh = b.height + pad * 2;
    msvg.setAttribute('viewBox', vx + ' ' + vy + ' ' + vw + ' ' + vh);
    var html = '';
    Object.keys(layoutCache).forEach(function (id) {
      html += '<rect x="' + layoutCache[id].x + '" y="' + (layoutCache[id].y * (H + GY)) + '" width="' + W + '" height="' + H + '" rx="8" fill="' + rk(by[id].rank).color + '"/>';
    });
    html += '<rect x="' + (-tx / k) + '" y="' + (-ty / k) + '" width="' + (c.width / k) + '" height="' + (c.height / k) + '" fill="rgba(194,102,58,.12)" stroke="#C2663A" stroke-width="' + (vw / 90) + '"/>';
    msvg.innerHTML = html;
    mv = { vx: vx, vy: vy, vw: vw, vh: vh };
  }
  function fit() {
    var b = g.getBBox(), r = cv.getBoundingClientRect();
    if (!r.width || !r.height) { return; }
    k = Math.max(0.08, Math.min(1.25, (r.width - 60) / (b.width || 1), (r.height - 90) / (b.height || 1)));
    tx = (r.width - b.width * k) / 2 - b.x * k;
    ty = 60 - b.y * k + Math.max(0, (r.height - 90 - b.height * k) / 4);
    tf();
  }
  function zoom(f, cx, cy) {
    var r = cv.getBoundingClientRect();
    cx = (cx === undefined) ? r.width / 2 : cx;
    cy = (cy === undefined) ? r.height / 2 : cy;
    var nk = Math.min(2.5, Math.max(0.05, k * f));
    tx = cx - (cx - tx) * nk / k; ty = cy - (cy - ty) * nk / k; k = nk;
    tf();
  }

  cv.addEventListener('wheel', function (e) {
    e.preventDefault();
    var r = cv.getBoundingClientRect();
    zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });
  var drag = null;
  cv.addEventListener('pointerdown', function (e) {
    if (e.button !== undefined && e.button > 0) { return; }
    dragged = false;
    drag = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
    cv.classList.add('drag');
  });
  window.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!dragged && Math.abs(dx) + Math.abs(dy) > 5) { dragged = true; }
    if (dragged) { tx = drag.tx + dx; ty = drag.ty + dy; tf(); }
  });
  window.addEventListener('pointerup', function () { drag = null; cv.classList.remove('drag'); });
  window.addEventListener('pointercancel', function () { drag = null; cv.classList.remove('drag'); });
  mini.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
  mini.addEventListener('click', function (e) {
    if (!mv) { return; }
    var r = mini.getBoundingClientRect(), c = cv.getBoundingClientRect();
    var wx = mv.vx + (e.clientX - r.left) / r.width * mv.vw, wy = mv.vy + (e.clientY - r.top) / r.height * mv.vh;
    tx = c.width / 2 - wx * k; ty = c.height / 2 - wy * k; tf();
  });
  var zoomBox = box.querySelector('.lt-zoom');
  if (zoomBox) { zoomBox.addEventListener('pointerdown', function (e) { e.stopPropagation(); }); }

  /* ---------- barra comandi ---------- */
  function setCrumb() {
    crumbEl.innerHTML = '';
    if (rootId === ME) { crumbEl.appendChild(h('b', '', 'Il mio ramo')); return; }
    var a = h('a', 'crumb', '← Il mio ramo');
    a.href = '#'; a.addEventListener('click', function (e) { e.preventDefault(); focus(ME); });
    crumbEl.appendChild(a);
    crumbEl.appendChild(document.createTextNode(' · Albero di '));
    crumbEl.appendChild(h('b', '', by[rootId].name));
  }
  function focus(id) { rootId = id; open[id] = true; setCrumb(); draw(); fit(); }
  function levels(m) {
    open = {};
    var base = depth(rootId);
    nodes.forEach(function (n) { if (kids[n.id].length && depth(n.id) - base < m) { open[n.id] = true; } });
    setCrumb(); draw(); fit();
  }
  function expandAll() { nodes.forEach(function (n) { if (kids[n.id].length) { open[n.id] = true; } }); draw(); fit(); }
  function toggleFull() {
    full = !full;
    box.classList.toggle('full', full);
    if (full && box.requestFullscreen) { box.requestFullscreen().catch(function () {}); }
    if (!full && document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
    if (fullBtn) { fullBtn.textContent = full ? '✕ Esci da schermo intero' : '⛶ Schermo intero'; }
    setTimeout(fit, 120);
  }
  document.addEventListener('fullscreenchange', function () {
    if (!document.fullscreenElement && full) {
      full = false; box.classList.remove('full');
      if (fullBtn) { fullBtn.textContent = '⛶ Schermo intero'; }
      setTimeout(fit, 120);
    }
  });
  box.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-a]') : null;
    if (!b || !box.contains(b)) { return; }
    var a = b.getAttribute('data-a');
    if (a === 'l3') { levels(3); } else if (a === 'l6') { levels(6); } else if (a === 'all') { expandAll(); }
    else if (a === 'full') { toggleFull(); } else if (a === 'zin') { zoom(1.25); } else if (a === 'zout') { zoom(0.8); } else if (a === 'fit') { fit(); }
  });
  if (termEl) { termEl.addEventListener('change', function () { showTerm = termEl.checked; draw(); fit(); }); }

  /* ---------- ricerca ---------- */
  function inSub(i) { var x = i; while (x !== null && x !== undefined && by[x]) { if (x === rootId) { return true; } x = by[x].parent; } return false; }
  searchEl.addEventListener('input', function () {
    var q = searchEl.value.trim().toLowerCase();
    if (q.length < 2) { sr.style.display = 'none'; return; }
    var m = nodes.filter(function (d) { return d.name.toLowerCase().indexOf(q) > -1 || d.code.toLowerCase().indexOf(q) > -1; }).slice(0, 25);
    sr.innerHTML = '';
    if (!m.length) { sr.appendChild(h('div', 'lt-sub', 'Nessun risultato')); }
    m.forEach(function (d) {
      var row = h('div', '', d.name + ' ');
      row.setAttribute('data-i', d.id);
      row.appendChild(h('span', 'lt-sub', d.code + (d.id === ME ? ' · tu' : ' · liv. ' + lvl(d.id))));
      sr.appendChild(row);
    });
    var r = searchEl.getBoundingClientRect(), p = box.getBoundingClientRect();
    sr.style.left = (r.left - p.left) + 'px'; sr.style.top = (r.bottom - p.top + 4) + 'px'; sr.style.display = 'block';
  });
  sr.addEventListener('click', function (e) {
    var row = e.target.closest ? e.target.closest('[data-i]') : null;
    if (!row) { return; }
    var i = +row.getAttribute('data-i');
    sr.style.display = 'none'; searchEl.value = '';
    var x = by[i].parent;
    while (x !== null && x !== undefined && by[x]) { open[x] = true; x = by[x].parent; }
    if (!inSub(i)) { rootId = ME; setCrumb(); }
    hits = {}; hits[i] = true;
    var pos = draw();
    select(i);
    var P = pos[i], r2 = cv.getBoundingClientRect();
    if (P) { k = 1; tx = r2.width / 2 - (P.x + W / 2); ty = r2.height / 2 - (P.y * (H + GY) + H / 2); tf(); }
  });
  document.addEventListener('click', function (e) { if (!sr.contains(e.target) && e.target !== searchEl) { sr.style.display = 'none'; } });

  /* ---------- scheda della persona ---------- */
  function row(dl, key, value) {
    dl.appendChild(h('dt', '', key));
    dl.appendChild(h('dd', '', value));
  }
  function bar(pct, ok) {
    var b = h('div', 'lt-pb' + (ok ? ' done' : ''));
    var i = document.createElement('i'); i.style.width = Math.max(0, Math.min(100, pct)) + '%';
    b.appendChild(i);
    return b;
  }
  function reqBlock(parent, title, list) {
    if (!list || !list.length) { return; }
    if (title) { parent.appendChild(h('h4', '', title)); }
    list.forEach(function (r) {
      var box2 = h('div', 'lt-req');
      var head = h('div', 'lt-rrow');
      var left = h('span', '');
      left.appendChild(h('span', 'lt-tick' + (r.ok ? '' : ' no'), r.ok ? '✓' : '•'));
      left.appendChild(document.createTextNode(r.label));
      if (r.disabled) { left.appendChild(h('span', 'lt-pill', 'non richiesto ora')); }
      head.appendChild(left);
      head.appendChild(h('span', '', r.counted + ' / ' + r.required));
      box2.appendChild(head);
      box2.appendChild(bar(r.required > 0 ? Math.floor(r.counted / r.required * 100) : 100, r.ok));
      if (r.detail) { box2.appendChild(h('div', 'lt-hint', r.detail)); }
      parent.appendChild(box2);
    });
  }
  function renderDetail(holder, j, id) {
    holder.innerHTML = '';
    if (j && j.contact) {
      var cbox = h('div', 'lt-req');
      cbox.appendChild(h('h4', '', 'Scheda e recapiti'));
      if (j.contact.email) { var ea = h('a', '', j.contact.email); ea.href = 'mailto:' + j.contact.email; var er = h('div', 'lt-rrow'); er.appendChild(h('span', '', 'Email')); er.appendChild(ea); cbox.appendChild(er); }
      if (j.contact.phone) { var pa = h('a', '', j.contact.phone); pa.href = 'tel:' + j.contact.phone.replace(/\s+/g, ''); var pr = h('div', 'lt-rrow'); pr.appendChild(h('span', '', 'Telefono')); pr.appendChild(pa); cbox.appendChild(pr); }
      if (j.contact.place) { var lr = h('div', 'lt-rrow'); lr.appendChild(h('span', '', 'Città')); lr.appendChild(h('span', '', j.contact.place)); cbox.appendChild(lr); }
      holder.appendChild(cbox);
    }
    if (!j || !j.known) {
      holder.appendChild(h('p', 'lt-sub', 'Nessun dato di carriera: la persona non è ancora nel calcolo del periodo. I requisiti compaiono dopo il prossimo calcolo.'));
      return;
    }
    var own = (id === ME);
    if (j.next_name) {
      holder.appendChild(h('h4', '', 'Requisiti per ' + j.next_name + ' · ' + j.percent + '%'));
      holder.appendChild(bar(j.percent, j.percent >= 100));
      if (!j.requirements.length) { holder.appendChild(h('div', 'lt-hint', 'Nessun requisito attivo per questa qualifica.')); }
      reqBlock(holder, '', j.requirements);
    } else {
      holder.appendChild(h('p', 'lt-sub', 'Ha raggiunto la qualifica massima del piano.'));
    }
    reqBlock(holder, 'Requisiti della ' + (own ? 'tua' : 'sua') + ' qualifica attuale (' + j.rank + ')', j.current_requirements);
    if (j.maintenance) {
      var m = j.maintenance;
      holder.appendChild(h('h4', '', 'Mantenere la qualifica'));
      var hd = h('div', 'lt-rrow');
      hd.appendChild(h('span', '', 'Clienti per restare ' + m.rank));
      hd.appendChild(h('span', '', m.counted + ' / ' + m.required));
      holder.appendChild(hd);
      holder.appendChild(bar(Math.floor(m.counted / Math.max(1, m.required) * 100), m.ok));
      if (m.protected_until) { holder.appendChild(h('div', 'lt-hint', 'Le qualifiche attuali sono protette fino al ' + m.protected_until + '.')); }
    }
  }
  function select(id) {
    sel = by[id]; draw();
    var d = sel, token = ++detailToken;
    mbody.innerHTML = '';
    mbody.appendChild(h('h2', '', d.name));
    mbody.appendChild(h('div', 'lt-sub', d.code + ' · ' + statusName(d.status) + (d.since ? ' · dal ' + d.since : '')));
    var nk = (kids[id] || []).length;
    mbody.appendChild(h('div', 'lt-sub', plural(nk, 'diretto', 'diretti') + ' · team ' + d.team + ' · ' + plural(d.clients, 'cliente', 'clienti')));
    if (id !== rootId) {
      var act = h('div', 'lt-act');
      var b = h('button', '', 'Apri albero di questo incaricato'); b.type = 'button';
      b.addEventListener('click', function () { closeModal(); focus(id); });
      act.appendChild(b); mbody.appendChild(act);
    }
    var dl = h('dl', 'lt-dl');
    row(dl, 'Qualifica', rk(d.rank).name);
    row(dl, 'Livello', id === ME ? 'tu' : (lvl(id) === 1 ? '1 · tuo diretto' : String(lvl(id))));
    row(dl, 'Clienti personali', String(d.clients));
    row(dl, 'Iscritti', d.registered > 0 ? d.registered + ' · ' + d.valid + (d.valid === 1 ? ' valido' : ' validi') : 'nessuno');
    if (d.waiting > 0) { row(dl, 'Senza primo ordine', String(d.waiting)); }
    if (d.last_order) { row(dl, 'Ultimo ordine dei clienti', d.last_order); }
    row(dl, 'Venduto del mese', d.eur > 0 ? '€ ' + eur(d.eur) : '—');
    if (nk) { row(dl, 'Diretti Pearl', String(d.pearl_first)); }
    if (d.team > 0 && id !== ME) { row(dl, 'Il suo ramo', plural(d.team, 'persona', 'persone')); }
    row(dl, d.next ? 'Verso ' + d.next : 'Prossima qualifica', d.next ? (d.missing.length ? d.missing.join(' · ') : 'requisiti soddisfatti') : 'qualifica massima');
    mbody.appendChild(dl);
    var holder = h('div', 'lt-detail');
    holder.appendChild(h('p', 'lt-sub', 'Carico i requisiti…'));
    mbody.appendChild(holder);
    bg.hidden = false;

    var url = (box.getAttribute('data-detail') || '').replace('__ID__', String(id));
    if (!url || !window.fetch) { holder.innerHTML = ''; return; }
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
      .then(function (j) { if (token === detailToken) { renderDetail(holder, j, id); } })
      .catch(function () { if (token === detailToken) { holder.innerHTML = ''; holder.appendChild(h('p', 'lt-sub', 'Impossibile caricare i requisiti.')); } });
  }
  function closeModal() { bg.hidden = true; detailToken++; }
  bg.addEventListener('click', function (e) { if (e.target === bg) { closeModal(); } });
  var xBtn = ref('x');
  if (xBtn) { xBtn.addEventListener('click', closeModal); }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !bg.hidden) { closeModal(); } });

  /* ---------- avvio ---------- */
  /* Vista iniziale: i livelli più profondi che restano leggibili (zoom di almeno 50%); poi si apre da soli con «Espandi tutto». */
  function initialView() {
    var steps = [6, 3, 2, 1];
    for (var i = 0; i < steps.length; i++) {
      levels(steps[i]);
      if (k >= 0.5) { return; }
    }
    k = 0.55;
    var P = layoutCache[rootId], r = cv.getBoundingClientRect();
    if (P) { tx = r.width / 2 - (P.x + W / 2) * k; ty = 40; tf(); }
  }
  setCrumb();
  initialView();
  window.addEventListener('resize', function () { if (!drag) { drawMini(); } });
})();
