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
     <p style="margin-top:0">Abbiamo preparato la tua Lettera di Incarico IVD. Inserisci il codice OTP per firmarla elettronicamente.</p>
     @if($hint)<div class="note note-info"><div>{{ $hint }}</div></div>@endif
     <label for="b-otp">Codice OTP</label><input id="b-otp" name="otp" inputmode="numeric" autocomplete="one-time-code" style="max-width:240px;font-size:20px;letter-spacing:.2em">
     @foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
     <div class="form-actions"><button class="accent">Firma</button></div>
    </form>
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
       <input id="b-sp" value="{{ $lockedSponsor->name }} [{{ $lockedSponsor->code }}]" readonly>
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
      <label class="chk"><input type="checkbox" name="accept" value="1" @checked(old('accept'))> Dichiaro di aver letto il Regolamento e il Piano Compensi e di voler sottoscrivere la Lettera di Incarico.</label>
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
    @foreach([[1, 'Invia la richiesta', 'Dati personali e accettazione del regolamento'], [2, 'Firma la lettera', 'Con un codice OTP, da telefono o computer'], [3, 'Completa l\'onboarding', 'Documento d\'identità e IBAN per i pagamenti'], [4, 'Inizia', 'Il tuo link referral e la tua rete sono pronti']] as [$n, $t, $m])
     <div class="item"><span class="ico" @if($n < $step) style="background:var(--lm-ok-bg);color:var(--ok)" @elseif($n === $step) style="background:var(--lm-terracotta);color:#fff" @endif>{{ $n < $step ? '✓' : $n }}</span><span class="main"><span class="t">{{ $t }}</span><span class="m">{{ $m }}</span></span></div>
    @endforeach
   </div>
  </section>
 </aside>
</div>
@endsection
