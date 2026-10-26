/* Occhiolino "mostra/nascondi password" su tutti i campi password del sito
   (esclusi quelli che hanno già il proprio pulsante, es. registrazione: .has-eye). */
(function () {
  var OPEN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var SLASH = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.1 10.1 0 0 1 12 19c-6.4 0-10-7-10-7a17.6 17.6 0 0 1 4.1-5M9.9 5.2A9.7 9.7 0 0 1 12 5c6.4 0 10 7 10 7a17.7 17.7 0 0 1-2.2 3.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M2 2l20 20"/></svg>';

  function enhance(input) {
    if (input.dataset.pwEye || input.closest('.has-eye')) return;
    input.dataset.pwEye = '1';
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.setAttribute('aria-label', 'Mostra password');
    btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = OPEN;
    wrap.appendChild(btn);
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? SLASH : OPEN;
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Nascondi password' : 'Mostra password');
      input.focus({ preventScroll: true });
    });
    // prima dell'invio il campo torna nascosto (evita che il browser salvi/offra testo in chiaro)
    if (input.form) input.form.addEventListener('submit', function () { input.type = 'password'; });
  }

  function init() { document.querySelectorAll('input[type=password]').forEach(enhance); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
