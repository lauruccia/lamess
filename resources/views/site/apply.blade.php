@extends('site.layout')
@section('title', __('Candidati — Lamèss'))
@section('width','auth')
@section('side_title','Entra in Lamèss')
@section('side_text','Il nostro team valuterà il tuo profilo e riceverai una risposta via email.')
@push('styles')
<style>
.apply-int{margin:2px 0 6px}.apply-int-q{display:block;font-size:14.5px;margin:0 0 7px}
.apply-int-opts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.apply-int-opts label{margin:0;cursor:pointer}
.apply-int-opts input{position:absolute;opacity:0;width:1px;height:1px}
.apply-int-opts span{display:block;text-align:center;padding:12px 10px;border:1.5px solid var(--line,#d9cfc7);border-radius:11px;font-weight:600;font-size:15px;background:#fff;transition:background .14s,border-color .14s,color .14s}
.apply-int-opts input:checked+span{background:var(--lm-terracotta,#a4522f);border-color:var(--lm-terracotta,#a4522f);color:#fff}
.apply-int-opts input:focus-visible+span{outline:2px solid var(--lm-terracotta,#a4522f);outline-offset:2px}
@media(max-width:420px){.apply-int-opts{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<h1>{{ __('Candidati') }}</h1>
<p class="muted">{{ __('Compila i campi: il nostro team valuterà il tuo profilo e riceverai una risposta via email. Se approvato, ti arriverà il link per creare il tuo account.') }}</p>
<form method="post" action="/candidati" class="reg card" novalidate>
 @csrf
 <div style="position:absolute;left:-9999px" aria-hidden="true"><label>{{ __('Sito web') }} <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  @foreach([[['first_name','Nome','given-name','text'],['last_name','Cognome','family-name','text']],[['email','Email','email','email'],['phone','Telefono','tel','tel']],[['city','Città','address-level2','text']]] as $row)
 <div class="{{ count($row) > 1 ? 'pair' : '' }}">
  @foreach($row as [$f,$label,$ac,$type])
  <div class="fld @error($f) bad @enderror">
   <input id="c-{{ $f }}" name="{{ $f }}" type="{{ $type }}" value="{{ old($f) }}" placeholder=" " autocomplete="{{ $ac }}" @if($type==='email')autocapitalize="none" inputmode="email"@endif required><label for="c-{{ $f }}">{{ $label }}</label>
   @error($f)<p class="fe">{{ $message }}</p>@enderror
  </div>
  @endforeach
 </div>
 @endforeach
 <div class="apply-int @error('interest') bad @enderror" role="radiogroup" aria-label="{{ __('Sei interessato come') }}">
  <span class="apply-int-q">{{ __('Sei interessato come…') }}</span>
  <div class="apply-int-opts">
   @foreach(\App\Models\MembershipApplication::INTERESTS as $v => $label)
   <label><input type="radio" name="interest" value="{{ $v }}" @checked(old('interest')===$v) required><span>{{ $label }}</span></label>
   @endforeach
  </div>
  @error('interest')<p class="fe">{{ $message }}</p>@enderror
 </div>
 <label class="chk"><input type="checkbox" name="privacy" value="1" @checked(old('privacy')) required><span>{!! __('Ho letto l\'<a href="/privacy" target="_blank" rel="noopener">informativa sulla privacy</a> e acconsento al trattamento dei dati per valutare la mia candidatura.') !!}</span></label>
 @error('privacy')<p class="fe">{{ $message }}</p>@enderror
 <button class="btn" type="submit">{{ __('Invia la candidatura') }}</button>
</form>
<p class="muted reg-login">{!! __('Hai già un account? <a href="/accedi">Accedi</a>') !!}</p>
@endsection
