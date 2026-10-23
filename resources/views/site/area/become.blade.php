@extends('site.layout')
@section('title','Diventa Incaricato — Lamèss')
@section('width','area')
@section('content')
@php
    $step = $awaiting ? 2 : (($contract && in_array($contract->status, ['signed', 'accepted'])) ? 3 : 1);
@endphp
<div class="pg-head"><div><h1>Diventa Incaricato</h1><p class="sub">Solo le persone fisiche maggiorenni possono diventare Incaricati. Ci vogliono pochi minuti.</p></div></div>
<div class="split">
 <div class="stack">
  @if($awaiting)
   <section class="panel accent">
    <header><h2>Firma la Lettera di Incarico</h2></header>
    <form method="post" action="/area/diventa-incaricato/firma" class="panel-body">@csrf
     <p style="margin-top:0">Abbiamo preparato la tua Lettera di Incarico IVD. Leggila, completa le dichiarazioni, spunta le accettazioni e inserisci il codice OTP per firmarla elettronicamente.</p>
     @if($hint)<div class="note note-info"><div>{{ $hint }}</div></div>@endif

     <details style="margin:0 0 18px">
      <summary style="cursor:pointer;font-weight:600">1 · Leggi la Lettera di Incarico</summary>
      <div class="legal-body" style="max-height:340px;overflow:auto;border:1px solid var(--lm-line,#d9d2cc);border-radius:8px;padding:12px 16px;margin-top:10px;font-size:14px">
       @include('legal.ivd-letter-body')
      </div>
      <p class="muted" style="margin:8px 0 0;font-size:13px">Allegati: <a href="/regolamento" target="_blank" rel="noopener">Regolamento</a> · <a href="/piano-compensi" target="_blank" rel="noopener">Piano compensi</a> · <a href="/privacy" target="_blank" rel="noopener">Informativa privacy</a> · <a href="/lettera-di-incarico" target="_blank" rel="noopener">Lettera a pagina intera</a></p>
     </details>

     <h3 style="margin:0 0 8px;font-size:1rem">2 · Dichiarazioni (art. 12)</h3>
     <fieldset style="border:0;padding:0;margin:0 0 10px">
      <legend style="font-size:.92rem;padding:0;margin-bottom:4px">12.1 — Reddito complessivo netto di 5.000 € (art. 7), nell'anno in corso</legend>
      <label class="chk"><input type="radio" name="income_over_threshold" value="no" @checked(old('income_over_threshold') === 'no')> Dichiaro di <strong>non avere</strong> superato il reddito</label>
      <label class="chk"><input type="radio" name="income_over_threshold" value="yes" @checked(old('income_over_threshold') === 'yes')> Dichiaro di <strong>avere</strong> superato il reddito</label>
     </fieldset>
     <fieldset style="border:0;padding:0;margin:0 0 10px">
      <legend style="font-size:.92rem;padding:0;margin-bottom:4px">12.1 — Altra posizione previdenziale obbligatoria</legend>
      <label class="chk"><input type="radio" name="other_social_security" value="no" @checked(old('other_social_security') === 'no')> Non ho altra posizione previdenziale obbligatoria aperta</label>
      <label class="chk"><input type="radio" name="other_social_security" value="yes" @checked(old('other_social_security') === 'yes')> Ho altra posizione previdenziale obbligatoria aperta</label>
      <label class="chk"><input type="checkbox" name="is_pensioner" value="1" @checked(old('is_pensioner'))> Sono in possesso di pensione previdenziale e mi impegno a comunicare al Consorzio ogni variazione della mia posizione previdenziale.</label>
     </fieldset>
     <fieldset style="border:0;padding:0;margin:0 0 18px">
      <legend style="font-size:.92rem;padding:0;margin-bottom:4px">12.2 — Sono stato incaricato da altre imprese di vendita diretta a domicilio (L. 173/2005), o collaboro con imprese del settore?</legend>
      <label class="chk"><input type="radio" name="multi_principal" value="no" @checked(old('multi_principal') === 'no')> No</label>
      <label class="chk"><input type="radio" name="multi_principal" value="yes" @checked(old('multi_principal') === 'yes')> Sì</label>
      <label for="b-mp" style="margin-top:6px">Se sì, quali imprese?</label><input id="b-mp" name="multi_principal_names" maxlength="255" value="{{ old('multi_principal_names') }}">
     </fieldset>
     <p class="muted" style="margin:0 0 18px;font-size:13px">Con la firma dichiari anche quanto previsto dagli artt. 12.3 e 12.4 (requisiti di onorabilità, maggiore età, dati veritieri).</p>

     <h3 style="margin:0 0 8px;font-size:1rem">3 · Accettazioni</h3>
     @foreach(\App\Domain\Onboarding\IvdAcceptances::items() as $key => $item)
      <label class="chk" style="margin-bottom:{{ $item['detail'] ? '2px' : '8px' }}"><input type="checkbox" name="accept_{{ $key }}" value="1" @checked(old('accept_'.$key))>
       <span>{{ $item['label'] }}@if($item['link']) <a href="{{ $item['link'] }}" target="_blank" rel="noopener">{{ $item['link_text'] }}</a>@endif</span></label>
      @if($item['detail'])<p class="muted" style="margin:0 0 8px 26px;font-size:13px">{{ $item['detail'] }}</p>@endif
     @endforeach

     <h3 style="margin:18px 0 8px;font-size:1rem">4 · Firma</h3>
     <label for="b-otp">Codice OTP</label><input id="b-otp" name="otp" inputmode="numeric" autocomplete="one-time-code" style="max-width:240px;font-size:20px;letter-spacing:.2em">
     @foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
     <div class="form-actions"><button class="accent">Firma</button></div>
    </form>
    @if(config('lamess.signature_driver') === 'email')
     <form method="post" action="/area/diventa-incaricato/codice" class="panel-body" style="padding-top:0">@csrf
      <p style="margin:.2rem 0 .6rem;font-size:.9rem;opacity:.8">Non hai ricevuto il codice? Controlla lo spam, poi puoi chiederne uno nuovo (dopo un minuto dall'ultimo).</p>
      <button class="btn" type="submit">Invia di nuovo il codice</button>
     </form>
    @endif
   </section>
  @elseif($contract && in_array($contract->status, ['signed', 'accepted']))
   <div class="note note-ok"><div><strong>Domanda già in corso o incarico attivo</strong>Non serve fare altro qui: trovi tutto nella tua area.</div><a class="btn" href="/area">Torna alla tua area</a></div>
  @else
   @php($p = $user->profile)
   @php($sp = $lockedSponsor ?? $user->customer?->consultant)
   <form method="post" action="/area/diventa-incaricato" class="stack">@csrf
    <section class="panel">
     <header><h2>1 · Chi ti ha invitato</h2></header>
     <div class="panel-body">
      @if($lockedSponsor)
       <label for="b-sp">Il tuo sponsor</label>
       <input id="b-sp" value="{{ $lockedSponsor->code }}" readonly>
       <p class="muted" style="margin:6px 0 0;font-size:13px">Scelto dal link con cui ti sei registrato. Non si può cambiare.</p>
      @else
       <label for="b-sp">Sponsor (codice o referral)</label>
       <input id="b-sp" name="sponsor_code" value="{{ old('sponsor_code', $sp?->code) }}" placeholder="es. LM00123" autocapitalize="characters">
       <p class="muted" style="margin:6px 0 0;font-size:13px">Modificabile fino all'invio della richiesta; dopo non si cambia più.</p>
      @endif
     </div>
    </section>

    <section class="panel">
     <header><h2>2 · Dati anagrafici</h2></header>
     <div class="panel-body">
      <div class="form-grid">
       <div><label for="b-nm">Nome e cognome</label><input id="b-nm" value="{{ $p?->fullName() }}" readonly></div>
       <div><label for="b-em">Email</label><input id="b-em" value="{{ $user->email }}" readonly></div>
       <div class="full"><label for="b-cf">Codice fiscale *</label><input id="b-cf" name="tax_code" value="{{ old('tax_code', $p?->tax_code) }}" autocapitalize="characters" required></div>
       <div><label for="b-bd">Data di nascita *</label><input id="b-bd" type="date" name="birth_date" value="{{ old('birth_date', $p?->birth_date?->format('Y-m-d')) }}" max="{{ now()->subYears((int) config('lamess.adult_age'))->toDateString() }}" required></div>
       <div><label for="b-bp">Luogo di nascita *</label><input id="b-bp" name="birth_place" value="{{ old('birth_place', $p?->birth_place) }}" required></div>
      </div>
      <p class="muted" style="margin:12px 0 0;font-size:13px">Nome ed email sono quelli del tuo account: si correggono dal profilo. Possono diventare incaricati solo persone fisiche maggiorenni.</p>
     </div>
    </section>

    <section class="panel">
     <header><h2>3 · Residenza e contatti</h2></header>
     <div class="panel-body">
      <div class="form-grid">
       <div class="full"><label for="b-ad">Indirizzo di residenza *</label><input id="b-ad" name="address" value="{{ old('address', $p?->address) }}" autocomplete="street-address" required></div>
       <div><label for="b-ci">Città *</label><input id="b-ci" name="city" value="{{ old('city', $p?->city) }}" required></div>
       <div class="form-grid" style="grid-template-columns:1fr 90px;gap:0 14px">
        <div><label for="b-zi">CAP *</label><input id="b-zi" name="zip" value="{{ old('zip', $p?->zip) }}" inputmode="numeric" required></div>
        <div><label for="b-pr">Prov.</label><input id="b-pr" name="province" maxlength="3" value="{{ old('province', $p?->province) }}" autocapitalize="characters"></div>
       </div>
       <div><label for="b-co">Nazione</label>
        <select id="b-co" name="country_code">@foreach(['IT' => 'Italia', 'ES' => 'Spagna', 'DE' => 'Germania', 'NL' => 'Olanda', 'FR' => 'Francia', 'IE' => 'Irlanda', 'PT' => 'Portogallo', 'AT' => 'Austria', 'BE' => 'Belgio', 'CH' => 'Svizzera', 'GB' => 'Regno Unito', 'PA' => 'Panama'] as $cc => $cn)<option value="{{ $cc }}" @selected(old('country_code', $p?->country_code ?: 'IT') === $cc)>{{ $cn }}</option>@endforeach</select></div>
       <div><label for="b-ph">Telefono</label><input id="b-ph" name="phone" type="tel" value="{{ old('phone', $p?->phone) }}" autocomplete="tel"></div>
       <div class="full"><label for="b-pec">PEC (facoltativa)</label><input id="b-pec" name="pec" type="email" value="{{ old('pec', $p?->pec) }}" autocapitalize="none"></div>
      </div>
     </div>
    </section>

    <section class="panel">
     <header><h2>4 · Conferma</h2></header>
     <div class="panel-body">
      <p style="margin-top:0">IBAN e documenti li inserisci dopo la firma, nell'onboarding: servono per ricevere i pagamenti.</p>
      <label class="chk"><input type="checkbox" name="accept" value="1" @checked(old('accept'))> Ho preso visione del <a href="/regolamento" target="_blank" rel="noopener">Regolamento</a> e del <a href="/piano-compensi" target="_blank" rel="noopener">Piano compensi</a> e voglio ricevere la Lettera di Incarico da firmare. Le dichiarazioni e le accettazioni vere e proprie sono nel passo successivo, insieme alla firma.</label>
      @foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
      <div class="form-actions"><button class="accent">Continua alla firma</button></div>
     </div>
    </section>
   </form>
  @endif
 </div>
 <aside class="stack">
  <section class="panel">
   <header><h2>Il percorso</h2></header>
   <div class="list">
    @foreach([[1, 'Invia la richiesta', 'Dati personali e accettazione del regolamento'], [2, 'Firma la lettera', 'Dichiarazioni, accettazioni e codice OTP, da telefono o computer'], [3, 'Completa l\'onboarding', 'Documento d\'identità e IBAN per i pagamenti'], [4, 'Inizia', 'Il tuo link referral e la tua rete sono pronti']] as [$n, $t, $m])
     <div class="item"><span class="ico" @if($n < $step) style="background:var(--lm-ok-bg);color:var(--ok)" @elseif($n === $step) style="background:var(--lm-terracotta);color:#fff" @endif>{{ $n < $step ? '✓' : $n }}</span><span class="main"><span class="t">{{ $t }}</span><span class="m">{{ $m }}</span></span></div>
    @endforeach
   </div>
  </section>
 </aside>
</div>
@endsection
