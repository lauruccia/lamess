@php
 $withVariants = $product->hasVariants();
 $range = $withVariants ? $product->variantPriceRange(auth()->user()) : null;
 $saving = $withVariants ? null : $product->savingPercent(auth()->user());
 $cart = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2.2l2 11h10.6l1.8-8H6.4"/><circle cx="9" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/></svg>';
@endphp
<article class="pcard">
 <a class="pcard-img" href="{{ $product->shopUrl() }}" tabindex="-1" aria-hidden="true">
  @include('site.shop._thumb', ['product' => $product, 'alt' => $product->displayName()])
  <span class="tags">
   @if($product->isSoldOut())<span class="tag dark">Esaurito</span>@elseif($saving)<span class="tag hot">−{{ $saving }}%</span>@endif
   @if($product->badge)<span class="tag">{{ $product->badge }}</span>@endif
   @if($product->is_kit)<span class="tag">Kit</span>@endif
  </span>
  @if(($kmPct = $product->kmoneyPercent()) > 0)<span class="tags tags-bottom"><span class="tag km" title="Puoi pagare il {{ $kmPct }}% di questo prodotto con KMoney">{{ $kmPct }}% in KMoney</span></span>@endif
 </a>
 <div class="pcard-body">
  <h3><a href="{{ $product->shopUrl() }}">{{ $product->displayName() }}</a></h3>
  @if($product->description)<p class="pcard-desc">{{ $product->description }}</p>@endif
  <div class="pcard-foot">
   <div class="pcard-price">
    @if($withVariants && $range)
     <p class="price">@if($range[0] !== $range[1])<span class="muted small">da</span> @endif<b>€ {{ \App\Support\Money::eur($range[0]) }}</b><span class="vat-note">IVA inclusa</span></p>
    @else
     <p class="price"><b>€ {{ \App\Support\Money::eur($product->grossPriceFor(auth()->user())) }}</b>@if($product->compareAtGross()) <s>€ {{ \App\Support\Money::eur($product->compareAtGross()) }}</s>@endif<span class="vat-note">IVA inclusa</span></p>
    @endif
    @if($product->category)<span class="pcard-chip">{{ $product->category->name }}</span>@endif
   </div>
   <div class="pcard-act">
    @if($product->isSoldOut())
     <span class="muted small">Non disponibile</span>
    @elseif($withVariants)
     <a class="add icon" href="{{ $product->shopUrl() }}" title="Scegli le opzioni" aria-label="Scegli le opzioni di {{ $product->displayName() }}">{!! $cart !!}</a>
    @else
     <form method="post" action="/carrello/aggiungi" data-add>@csrf
      <input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="qty" value="1">
      <button class="add icon" title="Aggiungi al carrello" aria-label="Aggiungi {{ $product->displayName() }} al carrello">{!! $cart !!}</button>
     </form>
    @endif
   </div>
  </div>
 </div>
</article>
