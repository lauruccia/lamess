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
 @if(! empty($showKmoney) && ($kmBonus = max(0, (int) config('lamess.kmoney_bonus_percent', 0))) > 0 && $priced->kmoneyShare()->isPositive())
  {{-- Vantaggio KMoney in euro: sulla quota pagata in KMoney si ricarica solo quota / (1 + bonus%) --}}
  @php($kmSave = round((float) (string) $priced->kmoneyShare() * $kmBonus / (100 + $kmBonus), 2))
  <div class="km-save"><dt>Con KMoney risparmi</dt><dd>€ {{ \App\Support\Money::eur($kmSave) }}</dd></div>
 @endif
</dl>
