@extends('site.layout')
@section('title','Candidati — Lamèss')
@section('width','auth')
@section('side_title','Entra in Lamèss')
@section('side_text','Il nostro team valuterà il tuo profilo e riceverai una risposta via email.')
@section('content')
<h1>Candidati</h1>
<p class="muted">Compila i campi: il nostro team valuterà il tuo profilo e riceverai una risposta via email. Se approvato, ti arriverà il link per creare il tuo account.</p>
<form method="post" action="/candidati" class="reg card" novalidate>
 @csrf
 <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Sito web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
 @foreach([['first_name','Nome','given-name'],['last_name','Cognome','family-name'],['email','Email','email'],['phone','Telefono','tel'],['city','Città','address-level2'],['province','Provincia (sigla, es. RM)','address-level1']] as [$f,$label,$ac])
 <div class="fld @error($f) bad @enderror">
  <input id="c-{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" placeholder=" " autocomplete="{{ $ac }}" @if($f==='email')type="email" autocapitalize="none"@endif @if($f==='province')maxlength="2"@endif required><label for="c-{{ $f }}">{{ $label }}</label>
  @error($f)<p class="fe">{{ $message }}</p>@enderror
 </div>
 @endforeach
 <fieldset class="fld @error('interest') bad @enderror" style="border:0;padding:0">
  <legend>Sei interessato come…</legend>
  @foreach(\App\Models\MembershipApplication::INTERESTS as $v => $label)
  <label class="chk"><input type="radio" name="interest" value="{{ $v }}" @checked(old('interest')===$v) required><span>{{ $label }}</span></label>
  @endforeach
  @error('interest')<p class="fe">{{ $message }}</p>@enderror
 </fieldset>
 <div class="fld @error('message') bad @enderror">
  <textarea id="c-message" name="message" rows="5" maxlength="2000" placeholder=" ">{{ old('message') }}</textarea><label for="c-message">Presentati in poche righe (facoltativo)</label>
  @error('message')<p class="fe">{{ $message }}</p>@enderror
 </div>
 <label class="chk"><input type="checkbox" name="privacy" value="1" @checked(old('privacy')) required><span>Ho letto l'<a href="/privacy" target="_blank" rel="noopener">informativa sulla privacy</a> e acconsento al trattamento dei dati per valutare la mia candidatura.</span></label>
 @error('privacy')<p class="fe">{{ $message }}</p>@enderror
 <button class="btn" type="submit">Invia la candidatura</button>
</form>
<p class="muted reg-login">Hai già un account? <a href="/accedi">Accedi</a></p>
@endsection
