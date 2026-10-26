@extends('site.layout')
@section('title','Firma la Lettera di Incarico — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Firma la Lettera di Incarico</h1><p class="sub">Versione {{ $version->version }}. Leggila, completa le dichiarazioni, spunta le accettazioni e firma con il codice che ricevi per email.</p></div></div>
<div>
 <div class="stack">
  <section class="panel accent">
   <header><h2>La tua firma</h2></header>
   <div class="panel-body">
    @if(filled($version->change_summary))
     <div class="note note-info"><div><strong>Cosa cambia</strong>{!! nl2br(e($version->change_summary)) !!}</div></div>
    @endif

    <details open style="margin:0 0 18px">
     <summary style="cursor:pointer;font-weight:600">1 · Leggi la Lettera di Incarico</summary>
     <div class="legal-body" style="max-height:560px;overflow:auto;border:1px solid var(--lm-line,#d9d2cc);border-radius:8px;padding:12px 16px;margin-top:10px;font-size:14px">
      @include('legal.ivd-letter-body', ['letterVersion' => $version])
     </div>
     <p class="muted" style="margin:8px 0 0;font-size:13px">Allegati: <a href="/regolamento" target="_blank" rel="noopener">Regolamento</a> · <a href="/piano-compensi" target="_blank" rel="noopener">Piano compensi</a> · <a href="/privacy" target="_blank" rel="noopener">Informativa privacy</a></p>
    </details>

    <details style="margin:0 0 18px">
     <summary style="cursor:pointer;font-weight:600">Leggi i Principi generali e Regolamento (Allegato 1) · versione {{ $regolamentoVersion }}</summary>
     <div class="legal-body" style="max-height:560px;overflow:auto;border:1px solid var(--lm-line,#d9d2cc);border-radius:8px;padding:12px 16px;margin-top:10px;font-size:14px">{!! $regolamentoHtml !!}</div>
    </details>

    <form id="sign-form" method="post" action="/area/lettera/firma">@csrf
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
      <label for="l-mp" style="margin-top:6px">Se sì, quali imprese?</label><input id="l-mp" name="multi_principal_names" maxlength="255" value="{{ old('multi_principal_names') }}">
     </fieldset>

     <h3 style="margin:0 0 8px;font-size:1rem">3 · Accettazioni</h3>
     @foreach($items as $key => $item)
      <label class="chk" style="margin-bottom:{{ $item['detail'] ? '2px' : '8px' }}"><input type="checkbox" name="accept_{{ $key }}" value="1" @checked(old('accept_'.$key))> <span>{{ $item['label'] }}</span></label>
      @if($item['detail'])<p class="muted" style="margin:0 0 8px 26px;font-size:13px">{{ $item['detail'] }}</p>@endif
     @endforeach

    </form>

    <h3 style="margin:18px 0 8px;font-size:1rem">4 · Firma</h3>
    <form method="post" action="/area/lettera/codice" style="margin:0 0 16px">@csrf
     <p style="margin:.2rem 0 .6rem;font-size:.9rem;opacity:.8">Prima chiedi il codice: ti arriva per email e lo scrivi qui sotto. Se non arriva puoi chiederne un altro dopo un minuto.</p>
     <button class="btn accent" type="submit" style="margin-top:0">Invia il codice</button>
    </form>
    @if($hint)<div class="note note-info"><div>{{ $hint }}</div></div>@endif
    <label for="l-otp">Codice OTP</label><input id="l-otp" name="otp" form="sign-form" inputmode="numeric" autocomplete="one-time-code" style="max-width:240px;font-size:20px;letter-spacing:.2em">
    @foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
    <div class="form-actions"><button class="btn accent" type="submit" form="sign-form">Firma</button></div>
   </div>
  </section>
 </div>
</div>
@endsection
