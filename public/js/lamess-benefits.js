(function () {
  var dlg = document.getElementById('benefits');
  var openers = document.querySelectorAll('[data-benefits]');
  if (!dlg || typeof dlg.showModal !== 'function' || !openers.length) { return; } // senza <dialog> restano i link normali a /il-prodotto

  var slides = [].slice.call(dlg.querySelectorAll('.bn-slide'));
  var dots = [].slice.call(dlg.querySelectorAll('[data-go]'));
  var pauseBtn = dlg.querySelector('[data-bn-pause]');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var cur = 0, userPaused = false, MS = 6500;
  dlg.style.setProperty('--bn-ms', MS + 'ms');

  function fmt(n, dec) { return n.toFixed(dec).replace('.', ','); }

  function countUp(slide) {
    [].forEach.call(slide.querySelectorAll('[data-to]'), function (el) {
      var to = parseFloat(el.getAttribute('data-to')), dec = parseInt(el.getAttribute('data-dec'), 10) || 0;
      if (reduce) { el.textContent = fmt(to, dec); return; }
      var t0 = null, dur = 1100;
      function step(ts) {
        if (t0 === null) { t0 = ts; }
        var p = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(to * e, dec);
        if (p < 1 && dlg.open) { requestAnimationFrame(step); } else { el.textContent = fmt(to, dec); }
      }
      el.textContent = fmt(0, dec);
      requestAnimationFrame(step);
    });
  }

  // l'avanzamento automatico è guidato dalla barra CSS (animazione «fill»): in pausa si ferma insieme alla barra
  function schedule() {
    dlg.classList.remove('run');
    if (reduce || userPaused || cur >= slides.length - 1 || !dlg.open) { return; }
    void dlg.offsetWidth; // riavvia l'animazione
    dlg.classList.add('run');
  }
  dlg.addEventListener('animationend', function (e) {
    if (e.animationName === 'fill' && cur < slides.length - 1) { go(cur + 1, false); }
  });

  function go(i, manual) {
    i = Math.max(0, Math.min(slides.length - 1, i));
    slides.forEach(function (s, k) { s.hidden = k !== i; });
    dots.forEach(function (d, k) { d.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
    cur = i;
    countUp(slides[i]);
    schedule();
  }

  function setPaused(p) {
    userPaused = p;
    pauseBtn.setAttribute('aria-pressed', p ? 'true' : 'false');
    pauseBtn.textContent = p ? 'Riprendi' : 'Pausa';
    dlg.classList.toggle('paused', p);
    dlg.classList.toggle('ustop', p); // solo il pulsante «Pausa» ferma anche le animazioni decorative; il mouse ferma solo l'avanzamento
    if (!p) { schedule(); }
  }

  openers.forEach(function (o) {
    o.addEventListener('click', function (e) {
      e.preventDefault();
      userPaused = false; setPaused(false);
      dlg.showModal();
      go(0, false);
    });
  });
  dlg.addEventListener('close', function () { dlg.classList.remove('run', 'paused', 'ustop'); });
  [].forEach.call(dlg.querySelectorAll('[data-bn-close]'), function (b) { b.addEventListener('click', function () { dlg.close(); }); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } }); // clic sullo sfondo
  dlg.querySelector('[data-bn-next]').addEventListener('click', function () { go(cur + 1, true); });
  dlg.querySelector('[data-bn-prev]').addEventListener('click', function () { go(cur - 1, true); });
  dots.forEach(function (d) { d.addEventListener('click', function () { go(parseInt(d.getAttribute('data-go'), 10), true); }); });
  pauseBtn.addEventListener('click', function () { setPaused(!userPaused); });

  // pausa mentre il mouse o il fuoco sono sul popup
  var hold = function (on) { if (!userPaused) { dlg.classList.toggle('paused', on); } };
  var box = dlg.querySelector('.bn-box'); // non il <dialog>: lo sfondo scuro conta come parte del dialog e il mouse non «esce» mai
  box.addEventListener('mouseenter', function () { hold(true); });
  box.addEventListener('mouseleave', function () { hold(false); });

  dlg.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); go(cur + 1, true); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(cur - 1, true); }
  });
  var x0 = null;
  dlg.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  dlg.addEventListener('touchend', function (e) {
    if (x0 === null) { return; }
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) { go(cur + (dx < 0 ? 1 : -1), true); }
  }, { passive: true });
})();
