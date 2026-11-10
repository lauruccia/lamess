@php($t = $priced->totals())
<dl class="totals">
 <div><dt>{{ __('Prodotti') }}</dt><dd>€ {{ \App\Support\Money::eur($priced->listGross()) }}</dd></div>
 @foreach($priced->applied as $a)
  <div class="disc"><dt>{{ $a->discount->name }}@if($a->discount->code) <span class="code">{{ $a->discount->code }}</span>@endif <span class="muted">{{ $a->summary }}</span></dt><dd></dd></div>
 @endforeach
 @if($priced->discountGross()->isPositive())<div class="disc"><dt>{{ __('Sconti') }}</dt><dd>− € {{ \App\Support\Money::eur($priced->discountGross()) }}</dd></div>@endif
 @if($priced->shippingListNet->isPositive())
  <div>
   <dt>{{ __('Spedizione') }}</dt>
   @if($priced->shippingNet->isPositive())
    <dd>€ {{ \App\Support\Money::eur($priced->shippingGross()) }}</dd>
   @else
    <dd><s class="muted">€ {{ \App\Support\Money::eur($priced->shippingListGross()) }}</s> {{ __('Gratuita') }}</dd>
   @endif
  </div>
 @endif
 @php($vatRates = collect($priced->lines)->map(fn ($l) => (string) $l->product->vat_rate)->when($priced->shippingNet->isPositive(), fn ($c) => $c->push((string) $priced->shippingVatRate))->map(fn ($r) => rtrim(rtrim(number_format((float) $r, 2, ',', ''), '0'), ','))->unique()->implode('% / '))
 <div class="grand"><dt>{{ __('Totale') }} <small class="vat-pct">{{ __('IVA :p% inclusa', ['p' => $vatRates]) }}</small></dt><dd>€ {{ \App\Support\Money::eur($t['total']) }}</dd></div>
 @if(! empty($showKmoney) && $priced->kmoneyShare()->isPositive())
  {{-- Come si paga l'ordine con KMoney: quota in KY (prodotti con % KMoney, e spedizione se tutto è pagabile in KMoney) ed eventuale resto in euro --}}
  <div class="km-save" data-km-only @if(empty($kmoneyPicked)) hidden @endif><dt>{{ __('Da pagare in KMoney') }}</dt><dd>{{ __(':p KY', ['p' => \App\Support\Money::eur($priced->kmoneyShare())]) }}</dd></div>
  @if($priced->kmoneyRest()->isPositive())
   <div class="km-save km-save-eur" data-km-only @if(empty($kmoneyPicked)) hidden @endif><dt>{{ __('Da pagare in euro') }}</dt><dd>€ {{ \App\Support\Money::eur($priced->kmoneyRest()) }}</dd></div>
  @endif
 @endif
@if(! empty($tascaOn) && ! empty($tascaPlan))
 <div class="km-save"><dt>{{ __('Pagati con la Tasca') }}</dt><dd>− € {{ \App\Support\Money::eur($tascaPlan['amount']) }}</dd></div>
 <div class="km-save km-save-eur"><dt>{{ __('Da pagare ora') }}</dt><dd>€ {{ \App\Support\Money::eur(max(0, (float) $t['total'] - (float) (string) $tascaPlan['amount'])) }}</dd></div>
@endif
</dl>
