@if($priced->freeShippingHint)
 @php
  $h = $priced->freeShippingHint;
  $pct = max(4, min(100, (int) round((((float) $h['threshold'] - (float) $h['missing']) / max(1, (float) $h['threshold'])) * 100)));
 @endphp
 <div class="shipbar"><p>{!! __('Ti mancano <b>€ :p</b> per la spedizione gratuita', ['p' => e(\App\Support\Money::eur($h['missing']))]) !!}</p><div class="bar" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ $pct }}%"></span></div></div>
@elseif($priced->shippingListNet->isPositive() && ! $priced->shippingNet->isPositive())
 <div class="shipbar done"><p>{{ __('🎉 Hai la') }} <b>{{ __('spedizione gratuita') }}</b></p></div>
@endif
