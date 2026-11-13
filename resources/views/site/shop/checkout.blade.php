@extends('site.layout')
@section('title', __('Checkout — Lamèss'))
@section('width', 'full')
@section('header', 'minimal')
@section('content')
@php($__h = 0)
@php($methods = $methods ?? ['card' => true, 'transfer' => true, 'kmoney' => true])
@php($pay = old('payment_method', '')) {{-- nessun metodo preselezionato: ogni cliente sceglie --}}
@php($sub = old('subscribe', '')) {{-- nessuna scelta preselezionata --}}
@php($subOn = in_array($sub, array_keys($cadences), true))
@php($cfRequired = (($billingMissing === [] ? ($profile?->country_code ?: 'IT') : $country) === 'IT'))
<header class="page-head"><h1>{{ __('Checkout') }}</h1>@include('site.shop._steps', ['current' => 2])</header>
@if(session('kmoney_unlinked'))
<div class="warn" role="alert">{{ __('Per pagare con KMoney') }} <a href="{{ session('kmoney_unlinked.connect') }}"><b>{{ __('collega il tuo conto') }}</b></a>{{ __('. Se non sei registrato,') }} <a href="{{ session('kmoney_unlinked.register') }}" target="_blank" rel="noopener"><b>{{ __('clicca qui') }}</b></a> {{ __('per aprire un conto KMoney.') }}</div>
@elseif($errors->any())
{{-- Riepilogo errori in cima: grande, rosso, con l'elenco di tutto ciò che manca; la pagina scorre qui e i campi sono evidenziati. --}}
<div class="form-errors" id="checkout-errors" role="alert" tabindex="-1" data-fields='@json($errors->keys())'>
 <b>{{ __('Non possiamo confermare l\'ordine: controlla questi punti') }}</b>
 <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
</div>
@endif
@if(! empty($pendingOrder))<div class="warn">{{ __('Hai un ordine in attesa di pagamento (:p):', ['p' => $pendingOrder->number]) }} <a href="/area/ordini/{{ $pendingOrder->id }}">{{ __('completa il pagamento') }}</a>{{ __('. Se invece riordini da qui, quell\'ordine in sospeso viene annullato.') }}</div>@endif
<div class="cart-grid checkout">
 <form method="post" action="{{ $step < 4 ? '/area/checkout/passo/'.$step : '/area/checkout' }}" class="co-form" id="checkout-form">@csrf
  <input type="hidden" name="_step" value="{{ $step }}">
  <ol class="co-progress" aria-label="{{ __('Passi del checkout') }}">@foreach(\App\Http\Controllers\ShopController::STEPS as $n => [$url, $title])<li class="{{ $n === $step ? 'on' : ($n < $step ? 'done' : '') }}">@if($n < $step)<a href="{{ $url }}"><span class="n">✓</span>{{ $title }}</a>@else<span class="lbl" @if($n === $step) aria-current="step" @endif><span class="n">{{ $n }}</span>{{ $title }}</span>@endif</li>@endforeach</ol>
  @if($step === 1)
  <section class="card co-step" data-step="1">
   <h2><span class="step">1</span> {{ __('Fatturazione') }}</h2>
   <p class="muted small" style="margin:0 0 8px">{{ __('I campi con') }} <b class="req">*</b> {{ __('sono obbligatori.') }}</p>
   <label @if($billingMissing === []) hidden @endif>{{ __('Fattura intestata a') }}</label>
   <select name="type" id="type" @disabled($billingMissing === []) @if($billingMissing === []) hidden @endif><option value="private" @selected(old('type', $profile?->type ?? 'private')==='private')>{{ __('Privato') }}</option><option value="company" @selected(old('type', $profile?->type)==='company')>{{ __('Azienda') }}</option></select>
   @if($billingMissing === [])<input type="hidden" name="type" value="{{ $profile?->type ?? 'private' }}">@endif
   <div class="row pair"><div><label>{{ __('Nome') }} <b class="req" data-private title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="first_name" data-req-private value="{{ old('first_name', $profile?->first_name) }}" autocomplete="given-name"></div><div><label>{{ __('Cognome') }} <b class="req" data-private title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="last_name" data-req-private value="{{ old('last_name', $profile?->last_name) }}" autocomplete="family-name"></div></div>
   <div data-company><label>{{ __('Ragione sociale (aziende)') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="company_name" data-req-company value="{{ old('company_name', $profile?->company_name) }}" autocomplete="organization"></div>
   <div class="row pair pair-fiscal"><div><label>{{ __('Codice fiscale') }} @if($cfRequired)<b class="req" title="{{ __('Obbligatorio') }}">*</b>@else<span class="muted small">{{ __('(solo per l\'Italia)') }}</span>@endif</label><input @required($cfRequired) @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="tax_code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="16" value="{{ old('tax_code', $profile?->tax_code) }}"></div><div><label>{{ __('Telefono') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input required type="tel" @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="phone" value="{{ old('phone', $profile?->phone) }}" autocomplete="tel" inputmode="tel"></div></div>
   <div class="row" data-company><div><label>{{ __('Partita IVA (aziende)') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="vat_number" data-req-company value="{{ old('vat_number', $profile?->vat_number) }}"></div></div>
   <p class="muted small req-note" data-company style="margin:0 0 4px">{{ __('Per le aziende italiane serve il codice SDI oppure la PEC.') }}</p>
   <div class="row" data-company><div><label>{{ __('Codice SDI (aziende)') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="sdi_code" value="{{ old('sdi_code', $profile?->sdi_code) }}"></div><div><label>{{ __('PEC (aziende)') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input @if($billingMissing === []) readonly class="locked" aria-readonly="true" @endif name="pec" value="{{ old('pec', $profile?->pec) }}"></div></div>
   @if($addressLocked)
    <label>{{ __('Indirizzo di fatturazione') }}</label><input name="address" value="{{ $profile?->address }}" readonly class="locked" aria-readonly="true">
    <div class="row pair pair-cap"><div><label>{{ __('Città') }}</label><input name="city" value="{{ $profile?->city }}" readonly class="locked" aria-readonly="true"></div><div><label>{{ __('CAP') }}</label><input name="zip" value="{{ $profile?->zip }}" readonly class="locked" aria-readonly="true"></div></div>
    <div class="row pair pair-prov"><div><label>{{ __('Provincia') }}</label><input name="province" value="{{ $profile?->province }}" readonly class="locked" aria-readonly="true"></div><div><label for="country">{{ __('Nazione') }}</label>@php($billingCountry = strtoupper($profile?->country_code ?: 'IT'))@php($billingOptions = $countries + [$billingCountry => (config('lamess.country_names')[$billingCountry] ?? $billingCountry)])<select name="country" id="country" data-country-reload>@foreach($billingOptions as $code => $name)<option value="{{ $code }}" @selected($billingCountry === $code)>{{ $name }}</option>@endforeach</select></div></div>
    @unless(array_key_exists($billingCountry, $countries))<p class="muted small" style="margin:0 0 4px">{{ __('La tua nazione di fatturazione è :p: la spedizione verso questo paese non è ancora attiva, scegli un indirizzo di spedizione in una nazione disponibile.', ['p' => $billingOptions[$billingCountry]]) }}</p>@endunless
    <p class="muted small lock-note">{!! __('🔒 I dati di fatturazione sono quelli con cui sei registrata e non si cambiano dal checkout, tranne la nazione. Per modificarli vai su <a href="/area/profilo">I miei dati</a>: l\'indirizzo di spedizione puoi scegliere qui sotto se è diverso.') !!}</p>
   @else
    <label>{{ __('Indirizzo di fatturazione') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input required name="address" value="{{ old('address', $profile?->address) }}" autocomplete="street-address">
    <div class="row pair pair-cap"><div><label>{{ __('Città') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input required name="city" value="{{ old('city', $profile?->city) }}" autocomplete="address-level2"></div><div><label>{{ __('CAP') }} <b class="req" title="{{ __('Obbligatorio') }}">*</b></label><input required name="zip" value="{{ old('zip', $profile?->zip) }}" autocomplete="postal-code" inputmode="numeric"></div></div>
    <div class="row pair pair-prov"><div><label>{{ __('Provincia') }}</label><input name="province" maxlength="3" value="{{ old('province', $profile?->province) }}"></div><div><label for="country">{{ __('Nazione') }}</label><select name="country" id="country" data-country-reload>@foreach($countries as $code => $name)<option value="{{ $code }}" @selected(old('country', $country) === $code)>{{ $name }}</option>@endforeach</select></div></div>
    <p class="muted small lock-note">{!! __('Dopo il salvataggio l\'indirizzo di fatturazione non si potrà più cambiare dal checkout (solo da <a href="/area/profilo">I miei dati</a>).') !!}</p>
   @endif
   <div class="billing-save" data-billing-save @if($billingMissing === []) hidden @endif>
    <button type="submit" class="btn" formaction="/area/checkout/dati" data-save-billing>{{ __('Salva i dati') }}</button>
    <span class="muted small" data-billing-state>@if($billingMissing === [])<b class="ok">{{ __('✓ Dati salvati.') }}</b> {{ __('Se li modifichi, salvali di nuovo prima di confermare.') }}@else {{ __('Salva i dati di fatturazione per poter confermare l\'ordine.') }}@endif</span>
   </div>
   @foreach(['first_name','last_name','company_name','tax_code','phone','vat_number','sdi_code','address','city','zip','pec'] as $f)@error($f)<div class="err">{{ $message }}</div>@enderror @endforeach
   <div class="co-nav">@if($billingMissing === [])<button type="submit" class="btn accent">{{ __('Avanti →') }}</button>@endif</div>
  </section>
  @endif
  @if($step === 2)
  <section class="card co-step" data-step="2">
   <h2><span class="step">2</span> {{ __('Spedizione') }}</h2>
   @include('site.shop._ship_picker', ['addresses' => $addresses, 'max' => $maxAddresses])
   <div class="co-nav"><a class="ghostbtn" href="{{ \App\Http\Controllers\ShopController::STEPS[1][0] }}">{{ __('← Indietro') }}</a><button type="submit" class="btn accent">{{ __('Avanti →') }}</button></div>
  </section>
  @endif
  @if($step === 3)
  <section class="card co-step" data-step="3">
   <h2><span class="step">3</span> {{ __('Pagamento e consegna') }}</h2>
   @if(! empty($tasca))
   @php($useTasca = old('use_tasca', '0') === '1')
   <div class="tasca-pick" data-tasca-box data-covers-single="{{ $tasca['single']['covers_all'] ? 1 : 0 }}">
    <input type="hidden" name="use_tasca" value="0">
    <label class="tasca-card"><input type="checkbox" name="use_tasca" value="1" data-tasca-check @checked($useTasca)><span class="tick" aria-hidden="true"></span><span class="oc-text"><b>{{ __('Usa i compensi della Tasca') }}</b>
     @if($priced->shippingNet->isPositive())<span class="tasca-ship" style="display:block;margin:2px 0;font-weight:600;color:var(--accent,#c4663a)">{{ __('Se usi i compensi, la spedizione (€ :s) diventa gratuita.', ['s' => \App\Support\Money::eur($priced->shippingGross())]) }}</span>@endif
     <small>{{ __('Compensi utilizzabili: € :u (mesi chiusi, senza trattenute).', ['u' => \App\Support\Money::eur($tasca['usable'])]) }}
     <span data-sum-single @if($subOn) hidden @endif>{{ __('Con questo ordine usi € :u e paghi € :a di prodotti.', ['u' => \App\Support\Money::eur($tasca['single']['used']), 'a' => \App\Support\Money::eur($tasca['single']['amount'])]) }}@if($tasca['single']['covers_all']) {{ __('L\'ordine è pagato per intero.') }}@else {{ __('Il resto lo paghi con il metodo che scegli qui sotto.') }}@endif</span>
     <span data-sum-sub @if(! $subOn) hidden @endif>{{ __('Con questo ordine usi € :u e paghi € :a di prodotti.', ['u' => \App\Support\Money::eur($tasca['sub']['used']), 'a' => \App\Support\Money::eur($tasca['sub']['amount'])]) }}</span>
     {{ __('La scelta è definitiva: i compensi usati non si possono più prelevare.') }}</small></span>@if($priced->shippingNet->isPositive())<em class="oc-tag oc-tag-accent">{{ __('Spedizione gratuita') }}</em>@endif @if($tascaBonus > 0)<em class="oc-tag oc-tag-accent">+{{ $tascaBonus }}%</em>@endif</label>
    <p class="muted small" data-tasca-kmoney-note hidden>{{ __('I compensi della Tasca non si possono usare insieme a KMoney.') }}</p>
   </div>
   @endif
   <fieldset class="choice" data-pay-methods>
    <legend data-rest="{{ __('Come paghi il resto') }}">{{ __('Come vuoi pagare') }}</legend>
    @if(($kmoneyEnabled ?? false) && $priced->acceptsKMoney())
     {{-- % pagabile in KMoney impostata sulla pagina prodotto; con più prodotti è la quota complessiva dei prodotti nel carrello --}}
     @php($kmLinked = \App\Models\KMoneyLink::where('user_id', $user->id)->whereNotNull('mandate_uuid')->exists())
     <label class="opt-card opt-featured" data-pay-kmoney><input type="radio" name="payment_method" value="kmoney" @checked($pay === 'kmoney')><span class="dot"></span><span class="oc-text"><b>{{ __('Paga con KMoney') }}</b><small>
      <span class="km-link">
       @unless($kmLinked)
        {{ __('Per pagare col tuo saldo devi prima collegare il conto KMoney: al ritorno trovi qui tutto com\'era.') }}
       @endunless
      </span></small></span><em class="oc-tag oc-tag-accent">{{ __('Consigliato') }}</em></label>
     <p class="km-flow muted small" data-km-only @if($pay !== 'kmoney') hidden @endif>
      <span data-sum-single @if($subOn) hidden @endif>{{ __('Con «Conferma ordine» addebitiamo :p KY dal tuo conto KMoney, in un clic. Se l\'importo supera il tetto che hai autorizzato, KMoney ti chiede una sola conferma.', ['p' => \App\Support\Money::eur($priced->kmoneyShare())]) }}@if($priced->kmoneyRest()->isPositive()) {{ __('Poi scegli come pagare il resto, € :p, con carta o bonifico.', ['p' => \App\Support\Money::eur($priced->kmoneyRest())]) }}@endif</span>
      <span data-sum-sub @if(! $subOn) hidden @endif>{{ __('Con «Conferma ordine» addebitiamo :p KY dal tuo conto KMoney.', ['p' => \App\Support\Money::eur($pricedSub->kmoneyShare())]) }}@if($pricedSub->kmoneyRest()->isPositive()) {{ __('Il resto, € :p, si addebita in automatico sulla carta.', ['p' => \App\Support\Money::eur($pricedSub->kmoneyRest())]) }}@endif {{ __('Lo stesso a ogni addebito.') }}</span>
     </p>
     @if(! $kmLinked)
      <div class="warn km-connect" data-km-only data-km-connect @if($pay !== 'kmoney') hidden @endif>
       {!! __('<b>Collega il tuo conto KMoney per continuare.</b> Con «Collega KMoney» vai su KMoney e, al ritorno, ritrovi qui tutto com\'era: dati, spedizione, metodo di pagamento e abbonamento.') !!}
       <div class="km-connect-actions"><button type="submit" class="btn accent" formaction="/area/checkout/kmoney" formnovalidate>{{ __('Collega KMoney') }}</button> <a href="{{ $kmoneyRegisterUrl }}" target="_blank" rel="noopener">{{ __('Non hai un conto? Aprilo qui') }}</a></div>
      </div>
     @endif
    @endif
    @if($methods['card'])<label class="opt-card"><input type="radio" name="payment_method" value="card" @checked($pay === 'card')><span class="dot"></span><span class="oc-text"><b>{{ __('Carta di credito o debito') }}</b><small>{{ __('Pagamento immediato') }}</small></span></label>@endif
    @if($methods['transfer'])<label class="opt-card"><input type="radio" name="payment_method" value="transfer" @checked($pay === 'transfer')><span class="dot"></span><span class="oc-text"><b>{{ __('Bonifico bancario') }}</b><small>{{ __('L\'ordine è confermato alla ricezione del pagamento') }}</small></span></label>@endif
   </fieldset>
   @if(! empty($tasca))
   <script>
   (function () {
    var box = document.querySelector('[data-tasca-box]'), fs = document.querySelector('[data-pay-methods]'); if (!box || !fs) return;
    var chk = box.querySelector('[data-tasca-check]'), legend = fs.querySelector('legend'), kmCard = fs.querySelector('[data-pay-kmoney]'), note = box.querySelector('[data-tasca-kmoney-note]');
    var full = legend.textContent, rest = legend.dataset.rest || full;
    function radios() { return Array.prototype.slice.call(document.querySelectorAll('input[name=payment_method]')); }
    function pick(r) { if (r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } }
    function sync() {
     var on = chk.checked, s = document.querySelector('input[name=subscribe]:checked');
     var list = radios(), km = list.filter(function (r) { return r.value === 'kmoney'; })[0], other = list.filter(function (r) { return r.value !== 'kmoney'; })[0];
     // I compensi non si combinano con KMoney: se li usi, KMoney sparisce e resta carta o bonifico.
     if (on && km && km.checked && other) { pick(other); }
     if (kmCard) { kmCard.style.display = (on && other) ? 'none' : ''; }
     if (note) { note.hidden = !(on && km && other); }
     // Se i compensi coprono tutto l'ordine (ordine singolo) gli altri metodi non servono: restano nascosti.
     var covers = on && !!other && box.dataset.coversSingle === '1' && !(s && s.value !== 'none');
     if (covers && !list.some(function (r) { return r.checked; })) { pick(other); }
     fs.style.display = covers ? 'none' : '';
     legend.textContent = on ? rest : full;
     document.querySelectorAll('[data-tasca-on]').forEach(function (e) { e.hidden = !on; });
     document.querySelectorAll('[data-tasca-off]').forEach(function (e) { e.hidden = on; });
    }
    document.addEventListener('change', function (e) { var n = e.target && e.target.name; if (n === 'use_tasca' || n === 'subscribe' || n === 'payment_method') { sync(); } });
    sync();
    document.addEventListener('DOMContentLoaded', sync);
   })();
   </script>
   @endif
   @if($cadences !== [])
   <fieldset class="choice" data-subscribe-box>
    <legend>{{ __('Come vuoi riceverla') }}</legend>
    <label class="opt-card"><input type="radio" form="checkout-form" name="subscribe" value="none" @checked($sub === 'none')><span class="dot"></span><span class="oc-text"><b>{{ __('Ordine singolo') }}</b><small>{{ __('Spedizione a pagamento') }}</small></span></label>
    @isset($cadences['monthly'])
    <label class="opt-card opt-featured" data-subscribe-monthly><input type="radio" form="checkout-form" name="subscribe" value="monthly" @checked($sub === 'monthly')><span class="dot"></span><span class="oc-text"><b>{{ __('Abbonamento mensile') }}</b><small>{{ __('Ripeti questo ordine ogni mese, con carta o KMoney. Lo interrompi quando vuoi.') }}</small></span><em class="oc-tag oc-tag-accent">{{ __('Spedizione gratuita') }}</em></label>
    @endisset
    @isset($cadences['bimonthly'])
    <label class="opt-card" data-subscribe-monthly><input type="radio" form="checkout-form" name="subscribe" value="bimonthly" @checked($sub === 'bimonthly')><span class="dot"></span><span class="oc-text"><b>{{ __('Abbonamento bimestrale') }}</b><small>{{ __('Ricevi la box ogni 2 mesi, con carta o KMoney. Lo interrompi quando vuoi.') }}</small></span><em class="oc-tag oc-tag-accent">{{ __('Spedizione gratuita') }}</em></label>
    @endisset
    <p class="muted small" data-subscribe-note @if(! $subOn) style="display:none" @endif>{{ __('Con l\'abbonamento la box ti arriva ogni mese e l\'importo viene addebitato in automatico sulla carta o sul tuo conto KMoney, secondo il metodo scelto.') }}</p>
   </fieldset>
   @endif
   <div class="co-nav"><a class="ghostbtn" href="{{ \App\Http\Controllers\ShopController::STEPS[2][0] }}">{{ __('← Indietro') }}</a><button type="submit" class="btn accent">{{ __('Avanti →') }}</button></div>
  </section>
  @endif
  @if($step === 4)
  <section class="card co-step" data-step="4">
   <h2><span class="step">4</span> {{ __('Riepilogo e conferma') }}</h2>
   @foreach($hidden as $hk => $hv)<input type="hidden" name="{{ $hk }}" value="{{ $hv }}">@endforeach
   <dl class="co-review">@foreach($review as $rk => $rv)<dt>{{ $rk }}</dt><dd>{{ $rv }}</dd>@endforeach</dl>
  <div class="co-confirm">
  <label class="chk"><input type="checkbox" name="privacy" value="1" @checked(old('privacy'))><span>{!! __('Ho letto l\'<a href="/privacy" target="_blank" rel="noopener">informativa sulla privacy</a>, le <a href="/termini" target="_blank" rel="noopener">condizioni di vendita</a> e le <a href="/politiche-di-reso" target="_blank" rel="noopener">politiche di reso</a>.') !!}</span></label>
  @error('privacy')<div class="err">{{ $message }}</div>@enderror
  @if(! empty($tascaOn))
  <label class="chk"><input type="checkbox" name="tasca_consent" value="1" @checked(old('tasca_consent'))><span>{{ __('Confermo di usare i compensi della Tasca per questo acquisto: la scelta è definitiva e quei compensi non potranno più essere prelevati.') }}</span></label>
  @error('tasca_consent')<div class="err">{{ $message }}</div>@enderror
  @endif
  <button class="btn accent wide cta-lock" id="confirm-order" data-billing-missing="{{ $billingMissing === [] ? 0 : 1 }}" data-need-choice="{{ ($pay === '' || ($cadences !== [] && $sub === '')) ? 1 : 0 }}" data-km-needs-link="{{ ($kmoneyEnabled ?? false) && $kmoneyNeedsLink ? 1 : 0 }}" @disabled($billingMissing !== [] || $pay === '' || ($cadences !== [] && $sub === ''))><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> {{ __('Conferma ordine ·') }} <span data-sum-single @if($subOn) hidden @endif>€ {{ \App\Support\Money::eur(! empty($tascaOn) ? \App\Domain\Engine\Support\Num::of($tasca['single']['priced']->totals()['total'])->minus($tasca['single']['amount']) : $priced->totals()['total']) }}</span><span data-sum-sub @if(! $subOn) hidden @endif>€ {{ \App\Support\Money::eur(! empty($tascaOn) ? \App\Domain\Engine\Support\Num::of($tasca['sub']['priced']->totals()['total'])->minus($tasca['sub']['amount']) : $pricedSub->totals()['total']) }}</span></button>
  <p class="muted small confirm-hint" data-confirm-hint @if($billingMissing === [] && $pay !== '' && ($cadences === [] || $sub !== '')) hidden @endif>{{ __('Salva i dati di fatturazione (pulsante «Salva i dati») per attivare «Conferma ordine».') }}</p>
  <p class="secure-note">{{ __('Pagamento sicuro · I tuoi dati sono protetti') }}</p>
  </div>
   <div class="co-nav"><a class="ghostbtn" href="{{ \App\Http\Controllers\ShopController::STEPS[3][0] }}">{{ __('← Indietro') }}</a></div>
  </section>
  @endif
 </form>

 <aside class="summary card">
  <h2>{{ __('Il tuo ordine') }}</h2>
  @php($showKmoneyLines = ($kmoneyEnabled ?? false) && $priced->acceptsKMoney())
  <div class="co-items">
   @foreach($priced->lines as $key => $line)
    @php($p = $line->product)
    <div class="co-item"><span class="co-thumb">@include('site.shop._thumb', ['product' => $p])<i>{{ $line->qty }}</i></span><span class="co-name">{{ $p->displayName() }}@if($showKmoneyLines && $line->product->kmoneyPercent() > 0)<small class="co-km" data-km-only @if($pay !== 'kmoney') hidden @endif><b class="km-pill">{{ __(':p% KMoney', ['p' => (int) $line->product->kmoneyPercent()]) }}</b> @if($line->product->kmoneyPercent() < 100)<span class="co-km-eur">{{ __(':p KY + € :p2 in euro', ['p' => \App\Support\Money::eur($priced->kmoneyLineGross($line)), 'p2' => \App\Support\Money::eur($line->grossLine()->minus($priced->kmoneyLineGross($line)))]) }}</span>@endif</small>@endif</span><span class="co-price">€ {{ \App\Support\Money::eur($line->grossLine()) }}</span></div>
   @endforeach
  </div>
  @include('site.shop._code', ['uid' => 'co'])
  <div data-sum-single @if($subOn) hidden @endif>
   <div data-tasca-off @if(! empty($tascaOn)) hidden @endif>@include('site.shop._totals', ['priced' => $priced, 'tascaOn' => false, 'tascaPlan' => null, 'showKmoney' => ($kmoneyEnabled ?? false) && $priced->acceptsKMoney(), 'kmoneyPicked' => $pay === 'kmoney'])</div>
   @if(! empty($tasca))<div data-tasca-on @if(empty($tascaOn)) hidden @endif>@include('site.shop._totals', ['priced' => $tasca['single']['priced'], 'tascaOn' => true, 'tascaPlan' => $tasca['single'], 'showKmoney' => false, 'kmoneyPicked' => false])</div>@endif
  </div>
  <div data-sum-sub @if(! $subOn) hidden @endif>
   <div data-tasca-off @if(! empty($tascaOn)) hidden @endif>@include('site.shop._totals', ['priced' => $pricedSub, 'tascaOn' => false, 'tascaPlan' => null, 'showKmoney' => ($kmoneyEnabled ?? false) && $priced->acceptsKMoney(), 'kmoneyPicked' => $pay === 'kmoney'])</div>
   @if(! empty($tasca))<div data-tasca-on @if(empty($tascaOn)) hidden @endif>@include('site.shop._totals', ['priced' => $tasca['sub']['priced'], 'tascaOn' => true, 'tascaPlan' => $tasca['sub'], 'showKmoney' => false, 'kmoneyPicked' => false])</div>@endif
  </div>
  <p class="muted small"><a href="/carrello">{{ __('← Modifica il carrello') }}</a></p>
 </aside>
</div>
<script>
// Cambio nazione: ricalcola spedizione e prodotti vendibili senza perdere i dati già scritti (il modulo viene reinviato e riaperto).
(function () {
 var f = document.getElementById('checkout-form'); if (!f) return;
 var current = @json($country);
 function effective() {
  var to = f.elements['ship_to'] ? f.elements['ship_to'].value : 'billing';
  if (/^\d+$/.test(to)) return f.elements['ship_to'].selectedOptions[0].dataset.country || 'IT';
  var el = f.elements[to === 'new' ? 'ship_country' : 'country'];
  return el ? el.value : 'IT';
 }
 f.querySelectorAll('[data-country-reload]').forEach(function (el) {
  el.addEventListener('change', function () {
   if (effective() !== current) { f.action = '/area/checkout/nazione'; f.submit(); }
  });
 });
})();
</script>
@endsection
