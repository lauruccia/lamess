@extends('site.layout')
@section('title','Checkout — Lamèss')
@section('width', 'full')
@section('header', 'minimal')
@section('content')
<header class="page-head"><h1>Checkout</h1>@include('site.shop._steps', ['current' => 2])</header>
@if(session('kmoney_unlinked'))
<div class="warn" role="alert">Per pagare con KMoney <a href="{{ session('kmoney_unlinked.connect') }}"><b>collega il tuo conto</b></a>. Se non sei registrato, <a href="{{ session('kmoney_unlinked.register') }}" target="_blank" rel="noopener"><b>clicca qui</b></a> per aprire un conto KMoney.</div>
@elseif($errors->any())
{{-- Riepilogo errori in cima: grande, rosso, con l'elenco di tutto ciò che manca; la pagina scorre qui e i campi sono evidenziati. --}}
<div class="form-errors" id="checkout-errors" role="alert" tabindex="-1" data-fields='@json($errors->keys())'>
 <b>Non possiamo confermare l'ordine: controlla questi punti</b>
 <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
</div>
@endif
@if(! empty($pendingOrder))<div class="warn">Hai un ordine in attesa di pagamento ({{ $pendingOrder->number }}): <a href="/area/ordini/{{ $pendingOrder->id }}">completa il pagamento</a>. Se invece riordini da qui, quell'ordine in sospeso viene annullato.</div>@endif
<div class="cart-grid checkout">
 <form method="post" action="/area/checkout" class="co-form" id="checkout-form">@csrf
  <section class="card">
   <h2><span class="step">1</span> Fatturazione</h2>
   <p class="muted small" style="margin:0 0 8px">I campi con <b class="req">*</b> sono obbligatori.</p>
   <label>Fattura intestata a</label>
   <select name="type" id="type"><option value="private" @selected(old('type', $profile?->type ?? 'private')==='private')>Privato</option><option value="company" @selected(old('type', $profile?->type)==='company')>Azienda</option></select>
   <div class="row"><div><label>Nome <b class="req" data-private title="Obbligatorio">*</b></label><input name="first_name" data-req-private value="{{ old('first_name', $profile?->first_name) }}" autocomplete="given-name"></div><div><label>Cognome <b class="req" data-private title="Obbligatorio">*</b></label><input name="last_name" data-req-private value="{{ old('last_name', $profile?->last_name) }}" autocomplete="family-name"></div></div>
   <div data-company><label>Ragione sociale (aziende) <b class="req" title="Obbligatorio">*</b></label><input name="company_name" data-req-company value="{{ old('company_name', $profile?->company_name) }}" autocomplete="organization"></div>
   <div class="row"><div><label>Codice fiscale <b class="req" data-private title="Obbligatorio">*</b></label><input name="tax_code" data-req-private value="{{ old('tax_code', $profile?->tax_code) }}"></div><div data-company><label>Partita IVA (aziende) <b class="req" title="Obbligatorio">*</b></label><input name="vat_number" data-req-company value="{{ old('vat_number', $profile?->vat_number) }}"></div></div>
   <p class="muted small req-note" data-company style="margin:0 0 4px">Per le aziende italiane serve il codice SDI oppure la PEC.</p>
   <div class="row" data-company><div><label>Codice SDI (aziende) <b class="req" title="Obbligatorio">*</b></label><input name="sdi_code" value="{{ old('sdi_code', $profile?->sdi_code) }}"></div><div><label>PEC (aziende) <b class="req" title="Obbligatorio">*</b></label><input name="pec" value="{{ old('pec', $profile?->pec) }}"></div></div>
   <label>Indirizzo di fatturazione <b class="req" title="Obbligatorio">*</b></label><input required name="address" value="{{ old('address', $profile?->address) }}" autocomplete="street-address">
   <div class="row"><div><label>Città <b class="req" title="Obbligatorio">*</b></label><input required name="city" value="{{ old('city', $profile?->city) }}" autocomplete="address-level2"></div><div><label>CAP <b class="req" title="Obbligatorio">*</b></label><input required name="zip" value="{{ old('zip', $profile?->zip) }}" autocomplete="postal-code" inputmode="numeric"></div></div>
   <div class="row"><div><label>Provincia</label><input name="province" maxlength="3" value="{{ old('province', $profile?->province) }}"></div><div><label for="country">Nazione</label><select name="country" id="country" data-country-reload>@foreach($countries as $code => $name)<option value="{{ $code }}" @selected(old('country', $country) === $code)>{{ $name }}</option>@endforeach</select></div></div>
   <input type="hidden" name="remember" value="0">
   <label class="chk"><input type="checkbox" name="remember" value="1" @checked(old('remember', '1') == '1')> Salva questi dati per i prossimi ordini (potrai cambiare l'indirizzo in ogni ordine)</label>
   @foreach(['first_name','last_name','company_name','tax_code','vat_number','sdi_code','address','city','zip','pec'] as $f)@error($f)<div class="err">{{ $message }}</div>@enderror @endforeach
  </section>
  <section class="card">
   <h2><span class="step">2</span> Spedizione</h2>
   @include('site.shop._ship_picker', ['addresses' => $addresses, 'max' => $maxAddresses])
  </section>
  <section class="card">
   <h2><span class="step">3</span> Pagamento</h2>
   @php($methods = $methods ?? ['card' => true, 'transfer' => true, 'kmoney' => true])
   @php($pay = old('payment_method', $methods['card'] ? 'card' : ($methods['transfer'] ? 'transfer' : 'kmoney')))
   @php($sub = old('subscribe', empty($codes) ? (array_key_first($cadences) ?? 'none') : 'none')) {{-- abbonamento preselezionato (non con codici sconto nel carrello: valgono solo sugli ordini singoli) --}}
   <fieldset class="choice">
    <legend>Come vuoi pagare</legend>
    @if(($kmoneyEnabled ?? false) && $priced->acceptsKMoney())
     {{-- % pagabile in KMoney impostata sulla pagina prodotto; con più prodotti è la quota complessiva dei prodotti nel carrello --}}
     @php($kmLinked = \App\Models\KMoneyLink::where('user_id', $user->id)->whereNotNull('mandate_uuid')->exists())
     <label class="opt-card opt-featured"><input type="radio" name="payment_method" value="kmoney" @checked($pay === 'kmoney')><span class="dot"></span><span class="oc-text"><b>Paga con KMoney</b><small>
      <span class="km-link">
       @unless($kmLinked)
        Per pagare col tuo saldo <a href="/area/kmoney/collega">collega il tuo conto KMoney</a>: al termine torni qui al checkout. Non hai un conto? <a href="{{ $kmoneyRegisterUrl }}" target="_blank" rel="noopener">Apri un conto KMoney</a>.
       @endunless
      </span></small></span><em class="oc-tag oc-tag-accent">Consigliato</em></label>
     <p class="km-flow muted small" data-km-only @if($pay !== 'kmoney') hidden @endif>
      <span data-sum-single @if($sub !== 'none') hidden @endif>Dopo «Conferma ordine» ti chiediamo di confermare l'addebito di {{ \App\Support\Money::eur($priced->kmoneyShare()) }} KY dal tuo conto KMoney.@if($priced->kmoneyRest()->isPositive()) Poi scegli come pagare il resto, € {{ \App\Support\Money::eur($priced->kmoneyRest()) }}, con carta o bonifico.@endif</span>
      <span data-sum-sub @if($sub === 'none') hidden @endif>Con «Conferma ordine» addebitiamo {{ \App\Support\Money::eur($pricedSub->kmoneyShare()) }} KY dal tuo conto KMoney.@if($pricedSub->kmoneyRest()->isPositive()) Il resto, € {{ \App\Support\Money::eur($pricedSub->kmoneyRest()) }}, si addebita in automatico sulla carta.@endif Lo stesso a ogni addebito.</span>
     </p>
    @endif
    @if($methods['card'])<label class="opt-card"><input type="radio" name="payment_method" value="card" @checked($pay === 'card')><span class="dot"></span><span class="oc-text"><b>Carta di credito o debito</b><small>Pagamento immediato</small></span></label>@endif
    @if($methods['transfer'])<label class="opt-card"><input type="radio" name="payment_method" value="transfer" @checked($pay === 'transfer')><span class="dot"></span><span class="oc-text"><b>Bonifico bancario</b><small>L'ordine è confermato alla ricezione del pagamento</small></span></label>@endif
   </fieldset>
   @if($cadences !== [])
   <fieldset class="choice" data-subscribe-box>
    <legend>Come vuoi riceverla</legend>
    <label class="opt-card"><input type="radio" name="subscribe" value="none" @checked($sub === 'none')><span class="dot"></span><span class="oc-text"><b>Ordine singolo</b><small>Spedizione a pagamento</small></span></label>
    @isset($cadences['monthly'])
    <label class="opt-card opt-featured" data-subscribe-monthly><input type="radio" name="subscribe" value="monthly" @checked($sub === 'monthly')><span class="dot"></span><span class="oc-text"><b>Abbonamento mensile</b><small>Ripeti questo ordine ogni mese, con carta o KMoney. Lo interrompi quando vuoi.</small></span><em class="oc-tag oc-tag-accent">Spedizione gratuita</em></label>
    @endisset
    @isset($cadences['bimonthly'])
    <label class="opt-card" data-subscribe-monthly><input type="radio" name="subscribe" value="bimonthly" @checked($sub === 'bimonthly')><span class="dot"></span><span class="oc-text"><b>Abbonamento bimestrale</b><small>Ricevi la box ogni 2 mesi, con carta o KMoney. Lo interrompi quando vuoi.</small></span><em class="oc-tag oc-tag-accent">Spedizione gratuita</em></label>
    @endisset
    <p class="muted small" data-subscribe-note @if($sub === 'none') style="display:none" @endif>Con l'abbonamento la box ti arriva ogni mese e l'importo viene addebitato in automatico sulla carta o sul tuo conto KMoney, secondo il metodo scelto.</p>
   </fieldset>
   @endif
  </section>
 </form>

 <aside class="summary card">
  <h2>Il tuo ordine</h2>
  @php($showKmoneyLines = ($kmoneyEnabled ?? false) && $priced->acceptsKMoney())
  <div class="co-items">
   @foreach($priced->lines as $key => $line)
    @php($p = $line->product)
    <div class="co-item"><span class="co-thumb">@include('site.shop._thumb', ['product' => $p])<i>{{ $line->qty }}</i></span><span class="co-name">{{ $p->displayName() }}@if($showKmoneyLines && $line->product->kmoneyPercent() > 0)<small class="co-km" data-km-only @if($pay !== 'kmoney') hidden @endif><b class="km-pill">{{ (int) $line->product->kmoneyPercent() }}% KMoney</b> @if($line->product->kmoneyPercent() < 100)<span class="co-km-eur">{{ \App\Support\Money::eur($priced->kmoneyLineGross($line)) }} KY + € {{ \App\Support\Money::eur($line->grossLine()->minus($priced->kmoneyLineGross($line))) }} in euro</span>@endif</small>@endif</span><span class="co-price">€ {{ \App\Support\Money::eur($line->grossLine()) }}</span></div>
   @endforeach
  </div>
  @include('site.shop._code', ['uid' => 'co'])
  <div data-sum-single @if($sub !== 'none') hidden @endif>@include('site.shop._totals', ['priced' => $priced, 'showKmoney' => ($kmoneyEnabled ?? false) && $priced->acceptsKMoney(), 'kmoneyPicked' => $pay === 'kmoney'])</div>
  <div data-sum-sub @if($sub === 'none') hidden @endif>@include('site.shop._totals', ['priced' => $pricedSub, 'showKmoney' => ($kmoneyEnabled ?? false) && $priced->acceptsKMoney(), 'kmoneyPicked' => $pay === 'kmoney'])</div>
  <div class="co-confirm">
  <label class="chk"><input type="checkbox" name="privacy" value="1" form="checkout-form"><span>Ho letto l'<a href="/privacy" target="_blank" rel="noopener">informativa sulla privacy</a>, le <a href="/termini" target="_blank" rel="noopener">condizioni di vendita</a> e le <a href="/politiche-di-reso" target="_blank" rel="noopener">politiche di reso</a>.</span></label>
  @error('privacy')<div class="err">{{ $message }}</div>@enderror
  <button class="btn accent wide cta-lock" form="checkout-form"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> Conferma ordine · <span data-sum-single @if($sub !== 'none') hidden @endif>€ {{ \App\Support\Money::eur($priced->totals()['total']) }}</span><span data-sum-sub @if($sub === 'none') hidden @endif>€ {{ \App\Support\Money::eur($pricedSub->totals()['total']) }}</span></button>
  <p class="secure-note">Pagamento sicuro · I tuoi dati sono protetti</p>
  </div>
  <p class="muted small"><a href="/carrello">← Modifica il carrello</a></p>
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
