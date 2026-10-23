/* Negozio Lamèss: carrello a cassetto, quantità, galleria. Senza JavaScript tutto funziona comunque con le pagine normali. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var token = function () { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; };
  var drawer, body, lastFocus, toastTimer;
  var onCartPage = /^\/(carrello|area\/checkout)/.test(location.pathname);

  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }

  function toast(msg) {
    var t = $('#toast'); if (!t || !msg) return;
    t.textContent = msg; t.hidden = false; clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 3200);
  }

  function setCount(n) {
    $$('[data-cart-count]').forEach(function (el) {
      el.textContent = n; el.hidden = n < 1; el.classList.remove('pop'); void el.offsetWidth; el.classList.add('pop');
    });
  }

  function render(json) {
    if (body && json.html) body.innerHTML = json.html;
    if (typeof json.count === 'number') setCount(json.count);
  }

  function openDrawer() {
    if (!drawer) return;
    lastFocus = document.activeElement;
    drawer.hidden = false; document.body.classList.add('noscroll');
    var panel = $('.drawer-panel', drawer); if (panel) panel.focus();
  }
  function closeDrawer() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden = true; document.body.classList.remove('noscroll');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function loadDrawer(open) {
    return fetch('/carrello/mini', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (j) { render(j); if (open) openDrawer(); });
  }

  // Quantità: + / − e invio automatico sulla pagina carrello
  document.addEventListener('click', function (e) {
    var step = e.target.closest('[data-step]');
    if (step) {
      var box = step.closest('[data-qty]'), input = $('input', box);
      var v = (parseInt(input.value, 10) || 0) + parseInt(step.getAttribute('data-step'), 10);
      var min = input.min !== '' ? parseInt(input.min, 10) : 0, max = input.max !== '' ? parseInt(input.max, 10) : 99;
      input.value = Math.max(min, Math.min(max, v));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    var rm = e.target.closest('[data-remove]');
    if (rm) {
      var line = rm.closest('[data-line]'), q = $('[data-qty] input', line);
      q.value = 0; q.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    if (e.target.closest('[data-drawer-close]')) { closeDrawer(); return; }
    var opener = e.target.closest('[data-cart-open]');
    if (opener && drawer && !onCartPage && !e.metaKey && !e.ctrlKey) { e.preventDefault(); loadDrawer(true); }
  });

  document.addEventListener('change', function (e) {
    var qty = e.target.closest('[data-qty]'); if (!qty) return;
    var input = e.target;
    if (qty.hasAttribute('data-line-update')) {
      post('/carrello/riga', { product_id: qty.getAttribute('data-product'), qty: input.value, for: qty.getAttribute('data-for') })
        .then(function (j) { render(j); if (j.message) toast(j.message); }).catch(function () { toast('Non è stato possibile aggiornare il carrello.'); });
    } else if (qty.closest('#cart-form')) {
      qty.closest('form').submit();
    }
  });

  // Aggiungi al carrello senza cambiare pagina
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-add]'); if (!f || !drawer) return;
    e.preventDefault();
    var btn = $('button:not([type=button])', f); if (btn) btn.disabled = true;
    post('/carrello/aggiungi', { product_id: $('[name=product_id]', f).value, qty: ($('[name=qty]', f) || {}).value || 1 })
      .then(function (j) { render(j); if (j.message) toast(j.message); if (!onCartPage) openDrawer(); })
      .catch(function (err) { toast((err && err.message) || 'Prodotto non disponibile.'); })
      .then(function () { if (btn) btn.disabled = false; });
  });

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

  // Galleria della scheda prodotto
  document.addEventListener('click', function (e) {
    var t = e.target.closest('.gallery-thumbs button'); if (!t) return;
    var img = $('#gallery-img'); if (img) img.src = t.getAttribute('data-src');
    $$('.gallery-thumbs button').forEach(function (b) { b.classList.toggle('on', b === t); });
  });

  // Checkout: i campi aziendali compaiono solo per le aziende
  function company() {
    var sel = $('#type'); if (!sel) return;
    var co = sel.value === 'company';
    $$('[data-company]').forEach(function (el) { el.style.display = co ? '' : 'none'; });
    $$('[data-private]').forEach(function (el) { el.style.display = co ? 'none' : ''; });
    // «required» del browser solo sui campi che servono al tipo di fattura scelto
    $$('[data-req-private]').forEach(function (el) { el.required = !co; });
    $$('[data-req-company]').forEach(function (el) { el.required = co; });
  }

  // Checkout: l'abbonamento (mensile o bimestrale) si paga solo con carta o KMoney
  function subscription() {
    var box = $('[data-subscribe-box]'); if (!box) return;
    var opts = $$('[data-subscribe-monthly]', box), note = $('[data-subscribe-note]', box);
    var inputs = opts.map(function (o) { return $('input', o); }), single = $('input[value="none"]', box);
    if (!inputs.length) return;
    function picked() { return inputs.some(function (i) { return i.checked; }); }
    function sync() {
      var pm = $('input[name=payment_method]:checked'); var card = !pm || pm.value === 'card' || pm.value === 'kmoney';
      inputs.forEach(function (i, n) { i.disabled = !card; opts[n].classList.toggle('is-off', !card); });
      // Con l'abbonamento il resto dopo la quota KMoney si addebita in automatico: il bonifico non è possibile.
      var rest = $('#kmoney_rest'), tr = rest ? $('option[value=transfer]', rest) : null;
      if (tr) { tr.disabled = picked(); if (tr.disabled && rest.value === 'transfer') rest.value = 'card'; }
      if (!card && picked() && single) single.checked = true;
      // Testi di KMoney e nota dell'abbonamento seguono la scelta «ordine singolo / abbonamento».
      var on = picked(), bi = !!$('input[value="bimonthly"]:checked', box);
      $$('[data-sum-single]').forEach(function (e) { e.hidden = on; });
      $$('[data-sum-sub]').forEach(function (e) { e.hidden = !on; });
      if (note) note.style.display = on ? '' : 'none';
      if (note) note.textContent = card ? "Con l'abbonamento la box ti arriva " + (bi ? "ogni 2 mesi" : "ogni mese") + " e l'importo viene addebitato in automatico " + (pm && pm.value === 'kmoney' ? "sul tuo conto KMoney." : "sulla carta.") : "L'abbonamento è disponibile solo con carta o KMoney.";
    }
    $$('input[name=payment_method]').forEach(function (r) { r.addEventListener('change', sync); });
    $$('input[name=subscribe]', box).forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  }

  // Checkout: gli importi «In KMoney» nel riepilogo compaiono solo se si sceglie KMoney come pagamento
  function kmoneyAmounts() {
    var els = $$('[data-km-only]'); if (!els.length) return;
    function sync() { var pm = $('input[name=payment_method]:checked'); els.forEach(function (e) { e.hidden = !(pm && pm.value === 'kmoney'); }); }
    $$('input[name=payment_method]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  }

  // Checkout: con KMoney compare la scelta del metodo per il resto
  function kmoneyRest() {
    var box = $('[data-km-rest]'); if (!box) return;
    function sync() {
      var pm = $('input[name=payment_method]:checked'), sub = $('input[name=subscribe]:checked');
      var needed = (sub && sub.value !== 'none' ? box.dataset.restSub : box.dataset.restSingle) === '1';
      box.style.display = pm && pm.value === 'kmoney' && needed ? '' : 'none';
    }
    $$('input[name=payment_method]').forEach(function (r) { r.addEventListener('change', sync); });
    $$('input[name=subscribe]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  }

  // Varianti: formato, taglia, colore. Le pillole scelgono la variante; il menu nascosto resta come alternativa senza JavaScript.
  function variants() {
    var box = $('[data-variants]'), raw = $('#variants-data'); if (!box || !raw) return;
    var list = JSON.parse(raw.textContent), groups = $$('[data-group]', box);
    var form = $('form.buy'), select = $('select[name=product_id]', form), qty = $('[name=qty]', form);
    var priceEl = $('[data-v-price]'), stockEl = $('[data-v-stock]'), skuEl = $('[data-v-sku]'), naEl = $('[data-v-na]'), tagsEl = $('[data-v-tags]');
    var money = function (t) { return '€ ' + t; };

    function picked() { return groups.map(function (g) { var c = $('input:checked', g); return c ? c.value : null; }); }
    function find(sel) { return list.filter(function (v) { return sel.every(function (x, i) { return x === null || v.options[i] === x; }); }); }

    function refreshPills() {
      var sel = picked();
      groups.forEach(function (g, gi) {
        var value = $('input:checked', g); $('[data-group-value]', g).textContent = value ? value.value : '';
        $$('.pill', g).forEach(function (pill) {
          var probe = sel.slice(); probe[gi] = $('input', pill).value;
          var any = find(probe).some(function (v) { return !v.soldOut; });
          pill.classList.toggle('na', !any);
        });
      });
    }

    function apply() {
      var sel = picked(), v = find(sel)[0];
      refreshPills();
      if (!v || sel.indexOf(null) !== -1) { form.hidden = true; naEl.hidden = false; return; }
      naEl.hidden = true; select.value = String(v.id);
      var html = '<b>' + money(v.price) + '</b>';
      if (v.was) { html += ' <s>' + money(v.was) + '</s> <span class="save">Risparmi il ' + v.saving + '%</span>'; }
      priceEl.innerHTML = html;
      stockEl.className = 'stock ' + (v.soldOut ? 'out' : (v.low ? 'low' : 'in'));
      stockEl.textContent = v.soldOut ? 'Esaurito al momento' : (v.low ? 'Ultimi ' + v.max + ' pezzi disponibili' : 'Disponibile');
      form.hidden = v.soldOut;
      if (skuEl) skuEl.textContent = v.sku;
      qty.max = v.max === null ? 99 : Math.max(1, v.max); if (!(parseInt(qty.value, 10) >= 1)) qty.value = 1; if (parseInt(qty.value, 10) > parseInt(qty.max, 10)) qty.value = qty.max;
      var img = $('#gallery-img'), ph = $('.gallery-main .ph'), thumbs = $$('.gallery-thumbs button'), want = v.img || (thumbs[0] ? thumbs[0].getAttribute('data-src') : null);
      if (img) { if (want) { img.src = want; } img.hidden = !want; if (ph) ph.hidden = !!want; thumbs.forEach(function (b, i) { b.classList.toggle('on', !v.img && i === 0); }); }
      if (tagsEl) { var hot = tagsEl.querySelector('.hot, .dark'); if (hot) hot.remove(); if (v.soldOut) tagsEl.insertAdjacentHTML('afterbegin', '<span class="tag dark">Esaurito</span>'); else if (v.saving) tagsEl.insertAdjacentHTML('afterbegin', '<span class="tag hot">−' + v.saving + '%</span>'); }
      if (history.replaceState) history.replaceState(null, '', location.pathname + '?variante=' + v.id);
    }
    box.addEventListener('change', apply);
    refreshPills();
  }

  document.addEventListener('DOMContentLoaded', function () {
    variants();
    drawer = $('#cart-drawer'); body = $('#cart-drawer-body');
    $$('[data-js-hide]').forEach(function (b) { b.style.display = 'none'; });
    var sel = $('#type'); if (sel) { sel.addEventListener('change', company); company(); }
    subscription();
    kmoneyRest(); kmoneyAmounts();
  });
})();

// Filtri: il menu «Prezzo» si chiude cliccando fuori o con Esc
document.addEventListener('click', function (e) {
  document.querySelectorAll('details[data-dd][open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.querySelectorAll('details[data-dd][open]').forEach(function (d) { d.removeAttribute('open'); });
});
