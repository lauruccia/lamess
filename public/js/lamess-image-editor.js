/*
 * Lamèss — «Sistema l'immagine»: prima di caricare una foto nella scheda prodotto si apre un editor
 * (formato, zoom, spostamento, rotazione, «mostra tutta l'immagine») e il file esce già ridimensionato
 * (max 1600 px) e compresso in WebP. File statico: nessuna compilazione, funziona anche su cPanel.
 */
(function () {
  if (window.__lmImageEditor) return;
  window.__lmImageEditor = true;

  var SCOPE = '.lm-product-form';
  var MAX_PX = 1600, QUALITY = 0.82, STAGE_H = 420;

  var css = '\
.lmie-ov{position:fixed;inset:0;z-index:100000;background:rgba(19,17,14,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:16px;font-family:inherit}\
.lmie{background:#fff;color:#13110E;border-radius:20px;box-shadow:0 24px 70px rgba(19,17,14,.35);width:min(980px,100%);max-height:100%;display:flex;flex-direction:column;overflow:hidden}\
.lmie-h{padding:20px 26px;font-size:1.15rem;font-weight:600;display:flex;justify-content:space-between;gap:12px;align-items:baseline}\
.lmie-h small{font-weight:400;color:#6f665f;font-size:.85rem}\
.lmie-stage{background:#1d1a16;height:' + STAGE_H + 'px;position:relative;touch-action:none;cursor:grab}\
.lmie-stage.drag{cursor:grabbing}\
.lmie-stage canvas{width:100%;height:100%;display:block}\
.lmie-bar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;padding:16px 26px;border-bottom:1px solid #eee5df}\
.lmie-grp{display:flex;gap:8px;align-items:center}\
.lmie button{font:inherit;font-size:.9rem;cursor:pointer}\
.lmie-pill{border:1px solid #d9cfc8;background:#fff;color:#13110E;border-radius:999px;padding:9px 18px;line-height:1}\
.lmie-pill:hover{background:#faf3ef}\
.lmie-pill.on{background:#C2663A;border-color:#C2663A;color:#fff}\
.lmie-ico{width:38px;height:38px;padding:0;border-radius:999px;border:1px solid #d9cfc8;background:#fff;font-size:1.1rem}\
.lmie-ico:hover{background:#faf3ef}\
.lmie input[type=range]{width:170px;accent-color:#C2663A}\
.lmie-chk{display:flex;gap:7px;align-items:center;font-size:.9rem;cursor:pointer;margin-left:auto}\
.lmie-chk input{accent-color:#C2663A;width:16px;height:16px}\
.lmie-f{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:16px 26px}\
.lmie-res{color:#6f665f;font-size:.88rem}\
.lmie-btns{display:flex;gap:10px;flex-wrap:wrap}\
.lmie-btn{border:1px solid #d9cfc8;background:#fff;color:#13110E;border-radius:12px;padding:10px 20px;font-weight:500}\
.lmie-btn:hover{background:#faf3ef}\
.lmie-btn.ok{background:#C2663A;border-color:#C2663A;color:#fff;font-weight:600;box-shadow:0 2px 8px rgba(194,102,58,.35)}\
.lmie-btn.ok:hover{background:#a9532c}\
@media (max-width:640px){.lmie-chk{margin-left:0}.lmie input[type=range]{width:120px}}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  function loadImage(file) {
    return new Promise(function (res, rej) {
      var url = URL.createObjectURL(file), i = new Image();
      i.onload = function () { res({ img: i, url: url }); };
      i.onerror = function () { URL.revokeObjectURL(url); rej(); };
      i.src = url;
    });
  }

  /** Apre l'editor per un file. Risolve con: un File nuovo (Applica), 'original' (Usa l'originale) o null (Annulla). */
  function edit(file, label) {
    return loadImage(file).then(function (loaded) {
      return new Promise(function (resolve) {
        var img = loaded.img;
        var ov = document.createElement('div');
        ov.className = 'lmie-ov';
        ov.innerHTML =
          '<div class="lmie" role="dialog" aria-modal="true" aria-label="Sistema l\'immagine">' +
          '<div class="lmie-h"><span>Sistema l\'immagine</span><small>' + (label || '') + '</small></div>' +
          '<div class="lmie-stage"><canvas></canvas></div>' +
          '<div class="lmie-bar">' +
          '<div class="lmie-grp" data-ratios>' +
          '<button type="button" class="lmie-pill on" data-r="1">Quadrato</button>' +
          '<button type="button" class="lmie-pill" data-r="0.8">4:5</button>' +
          '<button type="button" class="lmie-pill" data-r="1.3333">4:3</button>' +
          '<button type="button" class="lmie-pill" data-r="0">Libero</button></div>' +
          '<div class="lmie-grp"><button type="button" class="lmie-ico" data-z="-" aria-label="Riduci">−</button>' +
          '<input type="range" min="1" max="4" step="0.01" value="1" aria-label="Zoom">' +
          '<button type="button" class="lmie-ico" data-z="+" aria-label="Ingrandisci">+</button></div>' +
          '<div class="lmie-grp"><button type="button" class="lmie-ico" data-rot="-1" aria-label="Ruota a sinistra">↺</button>' +
          '<button type="button" class="lmie-ico" data-rot="1" aria-label="Ruota a destra">↻</button>' +
          '<button type="button" class="lmie-pill" data-center>Centra</button></div>' +
          '<label class="lmie-chk"><input type="checkbox" data-contain> Mostra tutta l\'immagine</label>' +
          '</div>' +
          '<div class="lmie-f"><span class="lmie-res"></span><div class="lmie-btns">' +
          '<button type="button" class="lmie-btn" data-act="cancel">Annulla</button>' +
          '<button type="button" class="lmie-btn" data-act="original">Usa l\'originale</button>' +
          '<button type="button" class="lmie-btn ok" data-act="apply">Applica</button></div></div></div>';
        document.body.appendChild(ov);

        var stage = ov.querySelector('.lmie-stage'), cv = ov.querySelector('canvas'), ctx = cv.getContext('2d');
        var slider = ov.querySelector('input[type=range]'), chk = ov.querySelector('[data-contain]'), resEl = ov.querySelector('.lmie-res');
        var src = document.createElement('canvas');
        var S = { rot: 0, ratio: 1, zoom: 1, ox: 0, oy: 0, contain: false, cw: 0, ch: 0, fw: 0, fh: 0 };

        function rebuild() {
          var w = img.naturalWidth, h = img.naturalHeight, odd = S.rot % 2 !== 0;
          src.width = odd ? h : w; src.height = odd ? w : h;
          var c = src.getContext('2d');
          c.setTransform(1, 0, 0, 1, 0, 0);
          c.clearRect(0, 0, src.width, src.height);
          c.translate(src.width / 2, src.height / 2);
          c.rotate(S.rot * Math.PI / 2);
          c.drawImage(img, -w / 2, -h / 2);
        }
        function frame() {
          var dpr = window.devicePixelRatio || 1;
          S.cw = stage.clientWidth; S.ch = stage.clientHeight;
          cv.width = S.cw * dpr; cv.height = S.ch * dpr;
          ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
          var r = S.ratio || src.width / src.height;
          S.fw = Math.min(S.cw * 0.9, S.ch * 0.9 * r); S.fh = S.fw / r;
        }
        function scale() {
          var cover = Math.max(S.fw / src.width, S.fh / src.height), whole = Math.min(S.fw / src.width, S.fh / src.height);
          return (S.contain ? whole : cover) * S.zoom;
        }
        function clamp() {
          var s = scale(), mx = Math.max(0, (src.width * s - S.fw) / 2), my = Math.max(0, (src.height * s - S.fh) / 2);
          S.ox = Math.max(-mx, Math.min(mx, S.ox)); S.oy = Math.max(-my, Math.min(my, S.oy));
        }
        function out() {
          var s = scale(), w = S.fw / s, h = S.fh / s, k = Math.min(1, MAX_PX / Math.max(w, h));
          return { w: Math.max(1, Math.round(w * k)), h: Math.max(1, Math.round(h * k)), k: k };
        }
        function draw() {
          clamp();
          var s = scale(), iw = src.width * s, ih = src.height * s, cx = S.cw / 2 + S.ox, cy = S.ch / 2 + S.oy;
          var fx = (S.cw - S.fw) / 2, fy = (S.ch - S.fh) / 2;
          ctx.clearRect(0, 0, S.cw, S.ch);
          ctx.fillStyle = '#1d1a16'; ctx.fillRect(0, 0, S.cw, S.ch);
          ctx.fillStyle = '#fff'; ctx.fillRect(fx, fy, S.fw, S.fh);
          ctx.imageSmoothingQuality = 'high';
          ctx.drawImage(src, cx - iw / 2, cy - ih / 2, iw, ih);
          ctx.fillStyle = 'rgba(19,17,14,.58)';
          ctx.beginPath(); ctx.rect(0, 0, S.cw, S.ch); ctx.rect(fx, fy, S.fw, S.fh); ctx.fill('evenodd');
          ctx.strokeStyle = 'rgba(255,255,255,.95)'; ctx.lineWidth = 1.5; ctx.strokeRect(fx, fy, S.fw, S.fh);
          ctx.strokeStyle = 'rgba(255,255,255,.35)'; ctx.lineWidth = 1; ctx.beginPath();
          for (var i = 1; i < 3; i++) {
            ctx.moveTo(fx + S.fw * i / 3, fy); ctx.lineTo(fx + S.fw * i / 3, fy + S.fh);
            ctx.moveTo(fx, fy + S.fh * i / 3); ctx.lineTo(fx + S.fw, fy + S.fh * i / 3);
          }
          ctx.stroke();
          var o = out();
          resEl.textContent = 'Risultato: ' + o.w + ' × ' + o.h + ' px · WebP compresso';
          slider.value = S.zoom;
        }
        function reset() { S.zoom = 1; S.ox = 0; S.oy = 0; draw(); }

        // Formato
        ov.querySelector('[data-ratios]').addEventListener('click', function (e) {
          var b = e.target.closest('[data-r]'); if (!b) return;
          ov.querySelectorAll('[data-r]').forEach(function (x) { x.classList.toggle('on', x === b); });
          S.ratio = parseFloat(b.dataset.r); frame(); reset();
        });
        // Zoom
        slider.addEventListener('input', function () { S.zoom = parseFloat(slider.value); draw(); });
        ov.querySelectorAll('[data-z]').forEach(function (b) {
          b.addEventListener('click', function () { S.zoom = Math.max(1, Math.min(4, S.zoom + (b.dataset.z === '+' ? 0.2 : -0.2))); draw(); });
        });
        stage.addEventListener('wheel', function (e) {
          e.preventDefault(); S.zoom = Math.max(1, Math.min(4, S.zoom + (e.deltaY < 0 ? 0.1 : -0.1))); draw();
        }, { passive: false });
        // Rotazione e centra
        ov.querySelectorAll('[data-rot]').forEach(function (b) {
          b.addEventListener('click', function () { S.rot = (S.rot + parseInt(b.dataset.rot, 10) + 4) % 4; rebuild(); frame(); reset(); });
        });
        ov.querySelector('[data-center]').addEventListener('click', reset);
        chk.addEventListener('change', function () { S.contain = chk.checked; reset(); });
        // Trascinamento
        var drag = null;
        stage.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, ox: S.ox, oy: S.oy }; stage.classList.add('drag'); stage.setPointerCapture(e.pointerId); });
        stage.addEventListener('pointermove', function (e) { if (!drag) return; S.ox = drag.ox + e.clientX - drag.x; S.oy = drag.oy + e.clientY - drag.y; draw(); });
        stage.addEventListener('pointerup', function () { drag = null; stage.classList.remove('drag'); });
        stage.addEventListener('pointercancel', function () { drag = null; stage.classList.remove('drag'); });

        function onResize() { frame(); draw(); }
        function close(result) {
          window.removeEventListener('resize', onResize);
          document.removeEventListener('keydown', onKey, true);
          URL.revokeObjectURL(loaded.url);
          ov.remove();
          resolve(result);
        }
        function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(null); } }
        window.addEventListener('resize', onResize);
        document.addEventListener('keydown', onKey, true);

        function apply() {
          var o = out(), s = scale(), c = document.createElement('canvas');
          c.width = o.w; c.height = o.h;
          var x = c.getContext('2d'), f = o.w / S.fw;
          x.fillStyle = '#fff'; x.fillRect(0, 0, o.w, o.h);
          x.imageSmoothingQuality = 'high';
          x.drawImage(src, (S.fw / 2 + S.ox - src.width * s / 2) * f, (S.fh / 2 + S.oy - src.height * s / 2) * f, src.width * s * f, src.height * s * f);
          var base = (file.name || 'foto').replace(/\.[^.]+$/, '').replace(/[^\w-]+/g, '-').slice(0, 60) || 'foto';
          c.toBlob(function (b) {
            if (b && b.type === 'image/webp') { close(new File([b], base + '.webp', { type: 'image/webp' })); return; }
            c.toBlob(function (j) { close(j ? new File([j], base + '.jpg', { type: 'image/jpeg' }) : 'original'); }, 'image/jpeg', 0.85);
          }, 'image/webp', QUALITY);
        }
        ov.addEventListener('click', function (e) {
          var b = e.target.closest('[data-act]');
          if (!b) return;
          if (b.dataset.act === 'apply') apply();
          else if (b.dataset.act === 'original') close('original');
          else close(null);
        });

        rebuild(); frame(); draw();
      });
    });
  }

  function handle(files, input) {
    var list = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
    if (!list.length) return;
    var results = [];
    var chain = Promise.resolve();
    list.forEach(function (f, i) {
      chain = chain.then(function () {
        return edit(f, list.length > 1 ? 'Immagine ' + (i + 1) + ' di ' + list.length : '').then(function (r) {
          if (r === 'original') results.push(f); else if (r) results.push(r);
        }, function () { results.push(f); }); // immagine non leggibile dal browser: la lasciamo passare così com'è
      });
    });
    chain.then(function () {
      if (!results.length) return;
      var dt = new DataTransfer();
      results.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
      input.dataset.lmEdited = '1';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  // Selezione con «Sfoglia»
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLInputElement) || t.type !== 'file' || !t.closest(SCOPE) || !t.closest('.filepond--root')) return;
    if (t.dataset.lmEdited) { delete t.dataset.lmEdited; return; }
    if (!t.files || !t.files.length) return;
    e.stopImmediatePropagation();
    var files = Array.prototype.slice.call(t.files);
    t.value = '';
    handle(files, t);
  }, true);

  // Trascinamento dei file sull'area di caricamento
  document.addEventListener('drop', function (e) {
    var zone = e.target && e.target.closest ? e.target.closest(SCOPE + ' .filepond--root') : null;
    if (!zone || !e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
    var input = zone.querySelector('input[type=file]');
    if (!input) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    handle(Array.prototype.slice.call(e.dataTransfer.files), input);
  }, true);
})();
