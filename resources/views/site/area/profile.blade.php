@extends('site.layout')
@section('title','I miei dati — Lamèss')
@section('width','area')
@section('content')
@php
    $d = app(\App\Domain\Customers\AreaDashboard::class)->for($user);
    $company = $profile->type === 'company';
    $c = $user->consultant;
@endphp
<div class="pg-head">
 <div>
  <h1>I miei dati</h1>
  <p class="sub">Tieni aggiornati i recapiti: li usiamo per fatture, spedizioni e per avvisarti. Le fatture già emesse non cambiano.</p>
 </div>
</div>

@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif

<div class="split">
 <div class="stack">
  <form method="post" action="/area/profilo" class="panel">@csrf
   <header><h2>Anagrafica e indirizzo di fatturazione</h2></header>
   <div class="panel-body">
    <div class="form-grid">
     @if($company)
      <div class="full"><label for="p-company">Ragione sociale</label><input id="p-company" name="company_name" value="{{ old('company_name', $profile->company_name) }}"></div>
     @else
      <div><label for="p-first">Nome</label><input id="p-first" name="first_name" value="{{ old('first_name', $profile->first_name) }}" autocomplete="given-name"></div>
      <div><label for="p-last">Cognome</label><input id="p-last" name="last_name" value="{{ old('last_name', $profile->last_name) }}" autocomplete="family-name"></div>
     @endif
     <div class="full"><label for="p-phone">Telefono</label><input id="p-phone" name="phone" type="tel" value="{{ old('phone', $profile->phone) }}" autocomplete="tel" inputmode="tel"></div>
     <div class="full"><label for="p-address">Indirizzo</label><input id="p-address" name="address" value="{{ old('address', $profile->address) }}" autocomplete="street-address"></div>
     <div><label for="p-city">Città</label><input id="p-city" name="city" value="{{ old('city', $profile->city) }}" autocomplete="address-level2"></div>
     <div class="form-grid" style="grid-template-columns:1fr 90px;gap:0 14px">
      <div><label for="p-zip">CAP</label><input id="p-zip" name="zip" value="{{ old('zip', $profile->zip) }}" autocomplete="postal-code" inputmode="numeric"></div>
      <div><label for="p-prov">Prov.</label><input id="p-prov" name="province" value="{{ old('province', $profile->province) }}" maxlength="3" autocapitalize="characters"></div>
     </div>
     @if($company)
      <div><label for="p-pec">PEC</label><input id="p-pec" name="pec" type="email" value="{{ old('pec', $profile->pec) }}"></div>
      <div><label for="p-sdi">Codice SDI</label><input id="p-sdi" name="sdi_code" value="{{ old('sdi_code', $profile->sdi_code) }}" maxlength="7" autocapitalize="characters"></div>
     @endif
    </div>
    @foreach($errors->messages() as $k => $m)@unless(in_array($k, ['current_password', 'password'], true))<p class="err">{{ $m[0] }}</p>@endunless @endforeach
    <div class="form-actions"><button>Salva i dati</button></div>
   </div>
  </form>
  <section class="panel" id="indirizzi">
   <header><h2>Indirizzi di spedizione</h2><span class="muted">{{ $addresses->count() }} di {{ $maxAddresses }}</span></header>
   <div class="panel-body">
    <p class="muted" style="margin-top:0">Dove ricevere i tuoi ordini. Possono essere diversi dall'indirizzo di fatturazione; al checkout scegli quale usare.</p>
    @error('shipping')<div class="note note-err"><div>{{ $message }}</div></div>@enderror
    @foreach($addresses as $a)
     <details style="border-top:1px solid rgba(187,180,175,.5);padding:10px 0">
      <summary style="cursor:pointer"><strong>{{ $a->label ?: 'Indirizzo' }}</strong>@if($a->is_default) <span class="st st-ok">predefinito</span>@endif<br><span class="muted">{{ $a->recipient ? $a->recipient.' · ' : '' }}{{ $a->address }}, {{ $a->zip }} {{ $a->city }}{{ $a->province ? ' ('.$a->province.')' : '' }}</span></summary>
      <form method="post" action="/area/indirizzi/{{ $a->id }}" style="margin-top:10px">@csrf
       <div class="form-grid">
        <div><label>Nome dell'indirizzo</label><input name="label" maxlength="60" value="{{ $a->label }}"></div>
        <div><label>Destinatario</label><input name="recipient" maxlength="190" value="{{ $a->recipient }}"></div>
        <div class="full"><label>Indirizzo</label><input name="address" value="{{ $a->address }}"></div>
        <div><label>Città</label><input name="city" value="{{ $a->city }}"></div>
        <div class="form-grid" style="grid-template-columns:1fr 90px;gap:0 14px"><div><label>CAP</label><input name="zip" value="{{ $a->zip }}" inputmode="numeric"></div><div><label>Prov.</label><input name="province" maxlength="3" value="{{ $a->province }}"></div></div>
        <div class="full"><label>Telefono per il corriere</label><input name="phone" maxlength="30" value="{{ $a->phone }}"></div>
       </div>
       <div class="form-actions"><button class="btn accent">Salva</button></div>
      </form>
      <div class="form-actions" style="margin-top:8px">
       @unless($a->is_default)<form method="post" action="/area/indirizzi/{{ $a->id }}/predefinito">@csrf<button class="btn ghost">Rendi predefinito</button></form>@endunless
       <form method="post" action="/area/indirizzi/{{ $a->id }}/elimina">@csrf<button class="btn ghost">Elimina</button></form>
      </div>
     </details>
    @endforeach
    @if($addresses->count() < $maxAddresses)
     <details style="border-top:1px solid rgba(187,180,175,.5);padding:10px 0" @if($errors->has('shipping') || $errors->has('address')) open @endif>
      <summary style="cursor:pointer"><strong>+ Aggiungi un indirizzo</strong></summary>
      <form method="post" action="/area/indirizzi" style="margin-top:10px">@csrf
       <div class="form-grid">
        <div><label>Nome dell'indirizzo (es. Casa, Mare)</label><input name="label" maxlength="60" value="{{ old('label') }}"></div>
        <div><label>Destinatario (se diverso da te)</label><input name="recipient" maxlength="190" value="{{ old('recipient') }}"></div>
        <div class="full"><label>Indirizzo</label><input name="address" value="{{ old('address') }}"></div>
        <div><label>Città</label><input name="city" value="{{ old('city') }}"></div>
        <div class="form-grid" style="grid-template-columns:1fr 90px;gap:0 14px"><div><label>CAP</label><input name="zip" value="{{ old('zip') }}" inputmode="numeric"></div><div><label>Prov.</label><input name="province" maxlength="3" value="{{ old('province') }}"></div></div>
        <div class="full"><label>Telefono per il corriere</label><input name="phone" maxlength="30" value="{{ old('phone') }}"></div>
       </div>
       <div class="form-actions"><button class="btn accent">Salva indirizzo</button></div>
      </form>
     </details>
    @else
     <p class="muted" style="margin-bottom:0">Hai raggiunto il massimo di {{ $maxAddresses }} indirizzi: elimina quelli che non usi più per aggiungerne altri.</p>
    @endif
   </div>
  </section>

  <form method="post" action="/area/profilo/password" class="panel">@csrf
   <header><h2>Cambia password</h2></header>
   <div class="panel-body">
    <div class="form-grid">
     <div class="full"><label for="pw-cur">Password attuale</label><input id="pw-cur" type="password" name="current_password" autocomplete="current-password"></div>
     @error('current_password')<p class="err full">{{ $message }}</p>@enderror
     <div><label for="pw-new">Nuova password</label><input id="pw-new" type="password" name="password" autocomplete="new-password" minlength="10"></div>
     <div><label for="pw-new2">Ripeti la nuova password</label><input id="pw-new2" type="password" name="password_confirmation" autocomplete="new-password"></div>
     @error('password')<p class="err full">{{ $message }}</p>@enderror
    </div>
    <p class="muted" style="margin:10px 0 0;font-size:13px">Almeno 10 caratteri.</p>
    <div class="form-actions"><button>Cambia password</button></div>
   </div>
  </form>
 </div>

 <aside class="stack">
  <section class="panel">
   <header><h2>Il tuo profilo</h2></header>
   <div class="panel-body">
    <div class="who" style="margin-bottom:14px"><span class="avatar">{{ \App\Support\Ui::initials($profile->fullName() ?: $user->name) }}</span><div><b>{{ $profile->fullName() ?: $user->name }}</b><span>{{ $c && $c->status !== 'terminated' ? 'Incaricato · '.$c->code : 'Cliente' }}</span></div></div>
    <div class="meter {{ $d['profile']['percent'] === 100 ? 'ok' : '' }}" role="progressbar" aria-valuenow="{{ $d['profile']['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $d['profile']['percent'] }}%"></i></div>
    <p class="muted" style="margin:7px 0 0;font-size:13px">@if($d['profile']['missing'])Completo al {{ $d['profile']['percent'] }}%: mancano {{ implode(', ', $d['profile']['missing']) }}.@else Profilo completo: fatture e spedizioni sono pronte.@endif</p>
   </div>
  </section>
  <section class="panel">
   <header><h2>Dati che non puoi cambiare da qui</h2></header>
   <div class="panel-body">
    <dl class="dl">
     <dt>Email</dt><dd>{{ $user->email }}</dd>
     @if($profile->tax_code)<dt>Codice fiscale</dt><dd>{{ $profile->tax_code }}</dd>@endif
     @if($profile->vat_number)<dt>Partita IVA</dt><dd>{{ $profile->vat_number }}</dd>@endif
    </dl>
    <p class="muted" style="margin:12px 0 0;font-size:13px">Servono per fatture e contratti: per modificarli scrivi al Consorzio.</p>
    <a class="btn ghost" style="margin-top:12px" href="/area/assistenza">Scrivi al Consorzio</a>
   </div>
  </section>
  @if($c?->status === 'active')
   <section class="panel">
    <div class="panel-body"><strong>Cessione del codice attività</strong><p class="muted" style="margin:4px 0 12px">Vuoi passare il tuo codice a un'altra persona?</p><a class="btn ghost" href="/area/cessione">Scopri come</a></div>
   </section>
  @endif
 </aside>
</div>
@endsection
