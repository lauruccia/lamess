@php
 [$scope, $pid] = explode(':', $key);
 $for = $scope === 'me' ? null : (int) $scope;
 $p = $line->product;
 $unitGross = \App\Domain\Orders\PricedLine::gross($line->unitNet, (string) $p->vat_rate);
 $listGross = \App\Domain\Orders\PricedLine::gross($line->listUnitNet, (string) $p->vat_rate);
 $max = min(99, $p->availableQty() ?? 99);
@endphp
<div class="line" data-line>
 <a class="line-img" href="{{ $p->shopUrl() }}">@include('site.shop._thumb', ['product' => $p])</a>
 <div class="line-main">
  <a class="line-name" href="{{ $p->shopUrl() }}">{{ $p->displayName() }}</a>
  <p class="line-price">€ {{ \App\Support\Money::eur($unitGross) }}@if($line->isDiscounted()) <s>€ {{ \App\Support\Money::eur($listGross) }}</s>@endif <span class="muted">cad.</span></p>
  @if($max < 99 && $line->qty >= $max)<p class="line-warn">Massimo disponibile</p>@endif
  <div class="line-ctl">
   <div class="qty sm" data-qty @if($variant === 'drawer') data-line-update data-product="{{ $pid }}" @if($for) data-for="{{ $for }}" @endif @endif>
    <button type="button" data-step="-1" aria-label="Diminuisci">−</button>
    <input type="number" value="{{ $line->qty }}" min="0" max="{{ $max }}" inputmode="numeric" aria-label="Quantità di {{ $p->displayName() }}" @if($variant === 'cart') name="{{ $for ? 'qty_others['.$for.']['.$pid.']' : 'qty['.$pid.']' }}" @endif>
    <button type="button" data-step="1" aria-label="Aumenta">+</button>
   </div>
   <button type="button" class="linkbtn" data-remove aria-label="Rimuovi {{ $p->displayName() }}">Rimuovi</button>
  </div>
 </div>
 <p class="line-total">€ {{ \App\Support\Money::eur($line->grossLine()) }}</p>
</div>
