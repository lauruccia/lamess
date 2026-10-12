@if($priced->freeShippingHint)
 @php
  $h = $priced->freeShippingHint;
  $pct = max(4, min(100, (int) round((((float) $h['threshold'] - (float) $h['missing']) / max(1, (float) $h['threshold'])) * 100)));
 @endphp
 <div class="shipbar"><p>Ti mancano <b>€ {{ \App\Support\Money::eur($h['missing']) }}</b> per la spedizione gratuita</p><div class="bar" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ $pct }}%"></span></div></div>
@elseif($priced->shippingListNet->isPositive() && ! $priced->shippingNet->isPositive())
 <div class="shipbar done"><p>🎉 Hai la <b>spedizione gratuita</b></p></div>
@endif
