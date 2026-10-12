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
 <div class="grand"><dt>Totale</dt><dd>€ {{ \App\Support\Money::eur($t['total']) }}</dd></div>
 <p class="muted small vat">IVA inclusa (€ {{ \App\Support\Money::eur($t['vat']) }})</p>
</dl>
