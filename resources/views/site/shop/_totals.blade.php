@php($t = $priced->totals())
<dl class="totals">
 <div><dt>Prodotti</dt><dd>€ {{ \App\Support\Money::eur($priced->listGross()) }}</dd></div>
 @foreach($priced->applied as $a)
  <div class="disc"><dt>{{ $a->discount->name }}@if($a->discount->code) <span class="code">{{ $a->discount->code }}</span>@endif <span class="muted">{{ $a->summary }}</span></dt><dd></dd></div>
 @endforeach
 @if($priced->discountGross()->isPositive())<div class="disc"><dt>Sconti</dt><dd>− € {{ \App\Support\Money::eur($priced->discountGross()) }}</dd></div>@endif
 @if($priced->shippingListNet->isPositive())
  <div>
   <dt>Spedizione</dt>
   @if($priced->shippingNet->isPositive())
    <dd>€ {{ \App\Support\Money::eur($priced->shippingGross()) }}</dd>
   @else
    <dd><s class="muted">€ {{ \App\Support\Money::eur($priced->shippingListGross()) }}</s> Gratuita</dd>
   @endif
  </div>
 @endif
 @php($vatRates = collect($priced->lines)->map(fn ($l) => (string) $l->product->vat_rate)->when($priced->shippingNet->isPositive(), fn ($c) => $c->push((string) $priced->shippingVatRate))->map(fn ($r) => rtrim(rtrim(number_format((float) $r, 2, ',', ''), '0'), ','))->unique()->implode('% / '))
 <div class="grand"><dt>Totale <small class="vat-pct">IVA {{ $vatRates }}% inclusa</small></dt><dd>€ {{ \App\Support\Money::eur($t['total']) }}</dd></div>
 @if(! empty($showKmoney) && $priced->kmoneyShare()->isPositive())
  {{-- Quanto si paga in KMoney sull'ordine (prodotti e, se tutto è pagabile in KMoney, spedizione) --}}
  <div class="km-save" data-km-only @if(empty($kmoneyPicked)) hidden @endif><dt>In KMoney</dt><dd>{{ \App\Support\Money::eur($priced->kmoneyShare()) }} KY</dd></div>
 @endif
</dl>
