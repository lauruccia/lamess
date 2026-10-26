@extends('site.layout')
@section('title','Registrazione — Lamèss')
@section('width','auth')
@section('side_title','Crea il tuo account Lamèss')
@section('side_text','Registrati gratis e acquista dal negozio. Se vuoi, potrai poi candidarti come incaricato.')
@section('content')
@php
    $isCompany = old('type') === 'company';
    $stepOneFields = ['type', 'first_name', 'last_name', 'company_name', 'vat_number', 'sdi_code', 'pec', 'tax_code', 'email'];
    $startStep = ($errors->any() && ! $errors->hasAny($stepOneFields)) ? 2 : 1;
@endphp
<script>document.documentElement.classList.add('js')</script>
<div class="reg-wrap" id="reg-wrap" data-step="{{ $startStep }}">
 <div class="reg-head">
  <h1>Crea il tuo account</h1>
  <span class="reg-count" aria-hidden="true"><b data-n>{{ $startStep }}</b> / 2</span>
 </div>
 <p class="muted reg-sub">La registrazione è gratuita e non obbliga ad alcun acquisto.</p>
 <div class="reg-bar" aria-hidden="true"><i></i><i></i></div>

 <form method="post" action="/registrazione" class="reg card" id="reg" novalidate>
  @csrf
  <input type="hidden" name="ref" value="{{ request('ref') }}">

  {{-- Passo 1: chi sei --}}
  <div data-panel="1">
   <div class="seg" role="radiogroup" aria-label="Tipo di cliente">
    <label><input type="radio" name="type" value="private" @checked(! $isCompany)><span>Privato</span></label>
    <label><input type="radio" name="type" value="company" @checked($isCompany)><span>Azienda</span></label>
   </div>

   <div class="grp" data-grp="private" @if($isCompany) data-off @endif>
    <div class="pair">
     <div class="fld @error('first_name') bad @enderror">
      <input id="f-first" name="first_name" value="{{ old('first_name') }}" placeholder=" " autocomplete="given-name" enterkeyhint="next" data-req><label for="f-first">Nome</label>
      @error('first_name')<p class="fe">{{ $message }}</p>@enderror
     </div>
     <div class="fld @error('last_name') bad @enderror">
      <input id="f-last" name="last_name" value="{{ old('last_name') }}" placeholder=" " autocomplete="family-name" enterkeyhint="next" data-req><label for="f-last">Cognome</label>
      @error('last_name')<p class="fe">{{ $message }}</p>@enderror
     </div>
    </div>
   </div>

   <div class="grp" data-grp="company" @unless($isCompany) data-off @endunless>
    <div class="co-grid">
     <div class="fld w4 @error('company_name') bad @enderror">
      <input id="f-company" name="company_name" value="{{ old('company_name') }}" placeholder=" " autocomplete="organization" enterkeyhint="next" data-req><label for="f-company">Ragione sociale</label>
      @error('company_name')<p class="fe">{{ $message }}</p>@enderror
     </div>
     <div class="fld @error('vat_number') bad @enderror">
      <input id="f-vat" name="vat_number" value="{{ old('vat_number') }}" placeholder=" " autocomplete="off" autocapitalize="characters" enterkeyhint="next" data-req><label for="f-vat">Partita IVA</label>
      @error('vat_number')<p class="fe">{{ $message }}</p>@enderror
     </div>
     <div class="fld @error('sdi_code') bad @enderror">
      <input id="f-sdi" name="sdi_code" value="{{ old('sdi_code') }}" placeholder=" " autocomplete="off" autocapitalize="characters" enterkeyhint="next"><label for="f-sdi">Codice SDI</label>
      @error('sdi_code')<p class="fe">{{ $message }}</p>@enderror
     </div>
     <div class="fld w4 @error('pec') bad @enderror">
      <input id="f-pec" name="pec" type="email" value="{{ old('pec') }}" placeholder=" " autocomplete="off" autocapitalize="none" inputmode="email" enterkeyhint="next"><label for="f-pec">PEC</label>
      @error('pec')<p class="fe">{{ $message }}</p>@enderror
     </div>
    </div>
   </div>

   <div class="fld @error('tax_code') bad @enderror">
    <input id="f-tax" name="tax_code" value="{{ old('tax_code') }}" placeholder=" " autocomplete="off" autocapitalize="characters" maxlength="16" spellcheck="false" enterkeyhint="next"><label for="f-tax">Codice fiscale (facoltativo)</label>
    @error('tax_code')<p class="fe">{{ $message }}</p>@enderror
   </div>

   <div class="fld @error('email') bad @enderror">
    <input id="f-email" name="email" type="email" value="{{ old('email') }}" placeholder=" " autocomplete="email" autocapitalize="none" inputmode="email" enterkeyhint="next" required><label for="f-email">Email</label>
    @error('email')<p class="fe">{{ $message }}</p>@enderror
   </div>

   <button type="button" class="reg-next">Continua</button>
  </div>

  {{-- Passo 2: sicurezza --}}
  <div data-panel="2">
   <div class="pair-lg">
    <div class="fld has-eye @error('password') bad @enderror">
     <input id="f-pw" name="password" type="password" placeholder=" " autocomplete="new-password" minlength="10" enterkeyhint="next" required><label for="f-pw">Password</label>
     <button type="button" class="eye" aria-controls="f-pw f-pw2" aria-pressed="false">Mostra</button>
     @error('password')<p class="fe">{{ $message }}</p>@enderror
    </div>
    <div class="fld @error('password_confirmation') bad @enderror">
     <input id="f-pw2" name="password_confirmation" type="password" placeholder=" " autocomplete="new-password" enterkeyhint="next" required><label for="f-pw2">Ripeti password</label>
     @error('password_confirmation')<p class="fe">{{ $message }}</p>@enderror
    </div>
   </div>
   <p class="hint" id="pw-hint" aria-live="polite">Almeno 10 caratteri.</p>

   @if($sponsor)
    <div class="reg-sponsor">Il tuo incaricato di riferimento: <strong>{{ $sponsor->name }}</strong> <span>[{{ $sponsor->code }}]</span></div>
   @else
    <div class="fld pin @error('sponsor_code') bad @enderror">
     <input id="f-sponsor" name="sponsor_code" value="{{ old('sponsor_code') }}" placeholder="es. LM00123" autocomplete="off" autocapitalize="characters" enterkeyhint="done"><label for="f-sponsor">Codice del tuo incaricato (facoltativo)</label>
     @error('sponsor_code')<p class="fe">{{ $message }}</p>@enderror
    </div>
   @endif

   <div class="chk-wrap @error('privacy') bad @enderror">
    <label class="chk"><input type="checkbox" name="privacy" value="1" @checked(old('privacy')) required><span>Ho letto l'<a href="/privacy" target="_blank" rel="noopener">informativa sulla privacy</a>, i <a href="/termini" target="_blank" rel="noopener">termini e condizioni</a> e acconsento al trattamento dei dati.</span></label>
    @error('privacy')<p class="fe">{{ $message }}</p>@enderror
   </div>

   <p class="reg-free">Registrazione gratuita, nessun obbligo di acquisto.</p>

   <div class="actions">
    <button type="button" class="reg-back">Indietro</button>
    <button type="submit" class="reg-submit">Registrati</button>
   </div>
  </div>
 </form>

 <p class="muted reg-login">Hai già un account? <a href="/accedi">Accedi</a></p>
</div>

<script>
(function () {
  var wrap = document.getElementById('reg-wrap'), form = document.getElementById('reg');
  if (!wrap || !form) { return; }
  var mq = window.matchMedia('(max-width: 640px)');
  var step = parseInt(wrap.getAttribute('data-step'), 10) || 1;
  var el = form.elements;
  var counter = wrap.querySelector('[data-n]');
  var submitBtn = form.querySelector('.reg-submit');

  function isCompany() { return el['type'].value === 'company'; }

  function syncType() {
    var company = isCompany();
    [].forEach.call(form.querySelectorAll('.grp'), function (grp) {
      var on = grp.getAttribute('data-grp') === (company ? 'company' : 'private');
      if (on) { grp.removeAttribute('data-off'); } else { grp.setAttribute('data-off', ''); }
      [].forEach.call(grp.querySelectorAll('input'), function (i) { i.required = on && i.hasAttribute('data-req'); });
    });
  }

  function setStep(n, focusFirst) {
    step = n;
    wrap.setAttribute('data-step', n);
    if (counter) { counter.textContent = n; }
    if (focusFirst && mq.matches) {
      var f = form.querySelector('[data-panel="' + n + '"] input:not([type=radio]):not([type=hidden])');
      if (f) { f.focus({ preventScroll: true }); }
    }
    window.scrollTo(0, 0);
  }

  function firstInvalid(scope) {
    var sdi = el['sdi_code'], pec = el['pec'];
    sdi.setCustomValidity(isCompany() && !sdi.value.trim() && !pec.value.trim() ? 'Per le aziende serve il codice SDI o la PEC.' : '');
    var fields = scope.querySelectorAll('input');
    for (var i = 0; i < fields.length; i++) {
      if (fields[i].willValidate && !fields[i].checkValidity()) { return fields[i]; }
    }
    return null;
  }

  function panelOf(input) { return parseInt(input.closest('[data-panel]').getAttribute('data-panel'), 10); }

  function next() {
    var bad = firstInvalid(form.querySelector('[data-panel="1"]'));
    if (bad) { bad.reportValidity(); return; }
    setStep(2, true);
  }

  form.addEventListener('change', function (e) { if (e.target.name === 'type') { syncType(); } });
  form.addEventListener('input', function (e) { if (e.target.name === 'sdi_code' || e.target.name === 'pec') { el['sdi_code'].setCustomValidity(''); } });
  form.querySelector('.reg-next').addEventListener('click', next);
  form.querySelector('.reg-back').addEventListener('click', function () { setStep(1, false); });

  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && mq.matches && step === 1 && e.target.tagName === 'INPUT' && e.target.type !== 'radio') {
      e.preventDefault();
      next();
    }
  });

  form.addEventListener('submit', function (e) {
    var bad = firstInvalid(form);
    if (bad) {
      e.preventDefault();
      if (mq.matches) { setStep(panelOf(bad), false); }
      bad.focus({ preventScroll: true });
      bad.reportValidity();
      return;
    }
    if (mq.matches && step === 1) { e.preventDefault(); next(); return; }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Registrazione…';
  });

  window.addEventListener('pageshow', function () { submitBtn.disabled = false; submitBtn.textContent = 'Registrati'; });

  var pw = el['password'], pw2 = el['password_confirmation'], hint = document.getElementById('pw-hint');
  function checkPw() {
    var ok = pw.value.length >= 10;
    var mismatch = pw2.value !== '' && pw.value !== pw2.value;
    hint.className = 'hint' + (mismatch ? ' is-bad' : (ok && pw2.value !== '' ? ' is-ok' : ''));
    hint.textContent = mismatch ? 'Le due password non coincidono.' : (ok ? (pw2.value !== '' ? 'Password valida.' : 'Lunghezza ok. Ripetila qui sotto.') : 'Almeno 10 caratteri.');
  }
  pw.addEventListener('input', checkPw);
  pw2.addEventListener('input', checkPw);

  var eye = form.querySelector('.eye');
  eye.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = pw2.type = show ? 'text' : 'password';
    eye.textContent = show ? 'Nascondi' : 'Mostra';
    eye.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  syncType();
  checkPw();
})();
</script>
@endsection
