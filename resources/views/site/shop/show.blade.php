@extends('site.layout')
@php
 $withVariants = $variants->isNotEmpty();
 $current = $withVariants ? $chosen : $product;
 $title = $product->seo_title ?: $product->displayName();
 $desc = $product->seo_description ?: ($product->description ?: 'Scopri '.$product->name.' su Lamèss.');
 $images = $product->imagePaths();
 $firstImage = $withVariants && $chosen->imagePaths() ? $chosen->imagePaths()[0] : ($images[0] ?? null);
 $saving = $current->savingPercent(auth()->user());
 $max = $current->availableQty();
@endphp
@section('title', $title.' — Lamèss')
@section('width', 'full')
@push('head')
<meta name="description" content="{{ \Illuminate\Support\Str::limit($desc, 160) }}">
<meta property="og:title" content="{{ $title }}"><meta property="og:description" content="{{ \Illuminate\Support\Str::limit($desc, 200) }}"><meta property="og:type" content="product">
@if($product->coverUrl())<meta property="og:image" content="{{ $product->coverUrl() }}">@endif
<script type="application/ld+json">{!! json_encode(array_filter([
 '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product->name, 'sku' => $product->sku, 'description' => $desc,
 'image' => array_map(fn ($p) => \App\Models\Product::mediaUrl($p), $images) ?: null,
 'offers' => $withVariants
  ? $variants->map(fn ($v) => ['@type' => 'Offer', 'name' => $v->variant_label, 'sku' => $v->sku, 'priceCurrency' => 'EUR', 'price' => $v->grossPriceFor(auth()->user()), 'availability' => $v->isSoldOut() ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock', 'url' => $v->shopUrl()])->all()
  : ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'price' => $product->grossPriceFor(auth()->user()), 'availability' => $product->isSoldOut() ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock', 'url' => url('/negozio/'.$product->slug)],
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
@section('content')
<nav class="crumbs" aria-label="Percorso"><a href="/negozio">Negozio</a>@if($product->category)@if($product->category->parent) <span>/</span> <a href="/negozio?categoria={{ $product->category->parent->slug }}">{{ $product->category->parent->name }}</a>@endif <span>/</span> <a href="/negozio?categoria={{ $product->category->slug }}">{{ $product->category->name }}</a>@endif <span>/</span> <span aria-current="page">{{ $product->displayName() }}</span></nav>

<div class="pdp">
 <div class="gallery" data-gallery>
  <div class="gallery-main">
   <img id="gallery-img" @if($firstImage) src="{{ \App\Models\Product::mediaUrl($firstImage) }}" @else hidden @endif alt="{{ $product->displayName() }}" decoding="async"><span class="ph" aria-hidden="true" @if($firstImage) hidden @endif>L</span>
   <span class="tags" data-v-tags>@if($current->isSoldOut())<span class="tag dark">Esaurito</span>@elseif($saving)<span class="tag hot">−{{ $saving }}%</span>@endif @if($product->badge)<span class="tag">{{ $product->badge }}</span>@endif @if(($kmPct = $product->kmoneyPercent()) > 0)<span class="tag km">{{ $kmPct }}% in KMoney</span>@endif</span>
  </div>
  @if(count($images) > 1)
  <div class="gallery-thumbs" role="list">
   @foreach($images as $i => $img)<button type="button" class="{{ $i === 0 ? 'on' : '' }}" data-src="{{ \App\Models\Product::mediaUrl($img) }}" aria-label="Immagine {{ $i + 1 }}"><img src="{{ \App\Models\Product::mediaUrl($img) }}" alt="" loading="lazy"></button>@endforeach
  </div>
  @endif
 </div>

 <div class="pdp-info">
  @if($product->category)<a class="pcard-cat" href="/negozio?categoria={{ $product->category->slug }}">{{ $product->category->name }}</a>@endif
  <h1>{{ $product->displayName() }}</h1>
  <p class="price big" data-v-price><b>€ {{ \App\Support\Money::eur($current->grossPriceFor(auth()->user())) }}</b>@if($current->compareAtGross()) <s>€ {{ \App\Support\Money::eur($current->compareAtGross()) }}</s> <span class="save">Risparmi il {{ $saving }}%</span>@endif</p>
  <p class="muted small vat-line">IVA inclusa · spedizione calcolata al checkout</p>
  @if($product->description)<p class="pdp-lead">{{ $product->description }}</p>@endif

  @if($offers)
  <div class="offers" aria-label="Offerte attive"><b>Offerte attive</b><ul>@foreach($offers as $o)<li>{{ $o }}</li>@endforeach</ul></div>
  @endif

  @if($withVariants)
   <div class="opts" data-variants>
    @foreach((array) $product->options as $gi => $group)
    <fieldset class="opt" data-group="{{ $gi }}">
     <legend>{{ $group['name'] }}: <span data-group-value>{{ $chosen->variant_options[$group['name']] ?? '' }}</span></legend>
     <div class="opt-pills">
      @foreach(array_values(array_filter((array) ($group['values'] ?? []), fn ($val) => $variants->contains(fn ($v) => ($v->variant_options[$group['name']] ?? null) === $val))) as $val)
      <label class="pill"><input type="radio" name="opt{{ $gi }}" value="{{ $val }}" @checked(($chosen->variant_options[$group['name']] ?? null) === $val)><span>{{ $val }}</span></label>
      @endforeach
     </div>
    </fieldset>
    @endforeach
   </div>
  @endif
  @php($soldOut = $current->isSoldOut())
  <p class="stock {{ $soldOut ? 'out' : ($current->isLowStock() ? 'low' : 'in') }}" data-v-stock>@if($soldOut)Esaurito al momento @elseif($current->isLowStock())Ultimi {{ $max }} pezzi disponibili @else Disponibile @endif</p>
  <form method="post" action="/carrello/aggiungi" class="buy" data-add @if($soldOut) hidden @endif>@csrf
   @if($withVariants)
    <select name="product_id" data-js-hide aria-label="Variante">@foreach($variants as $v)<option value="{{ $v->id }}" @selected($v->id === $chosen->id)>{{ $v->variant_label }}</option>@endforeach</select>
   @else
    <input type="hidden" name="product_id" value="{{ $product->id }}">
   @endif
   <div class="qty" data-qty><button type="button" data-step="-1" aria-label="Diminuisci">−</button><input type="number" name="qty" value="1" min="1" max="{{ $max ?? 99 }}" inputmode="numeric" aria-label="Quantità"><button type="button" data-step="1" aria-label="Aumenta">+</button></div>
   <button class="btn accent buy-btn">Aggiungi al carrello</button>
  </form>
  @if($withVariants)
   <p class="variant-na" data-v-na hidden>Questa combinazione non è disponibile: scegline un'altra.</p>
   <script type="application/json" id="variants-data">{!! json_encode($variants->map(fn ($v) => [
    'id' => $v->id, 'options' => array_values((array) $v->variant_options), 'sku' => $v->sku,
    'price' => \App\Support\Money::eur($v->grossPriceFor(auth()->user())), 'was' => $v->compareAtGross() ? \App\Support\Money::eur($v->compareAtGross()) : null, 'saving' => $v->savingPercent(auth()->user()),
    'soldOut' => $v->isSoldOut(), 'low' => $v->isLowStock(), 'max' => $v->availableQty(), 'img' => $v->imagePaths() ? \App\Models\Product::mediaUrl($v->imagePaths()[0]) : null,
   ])->values(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
  @endif
  @if($shippingPromo)<p class="ship-note">Spedizione gratuita per ordini da <b>€ {{ $shippingPromo }}</b></p>@endif

  <ul class="assure">
   <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><span><b>Pagamento sicuro</b>Paghi con carta, bonifico o KMoney.</span></li>
   @if(\App\Models\Subscription::isOffered())<li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7.5" cy="17.5" r="1.7"/><circle cx="17.5" cy="17.5" r="1.7"/></svg><span><b>Spedizione a casa</b>Con l’abbonamento la spedizione è gratuita.</span></li>@endif
   @if(\App\Models\Subscription::isOffered())<li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5"/><path d="M20 4v4.5h-4.5"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 15.5"/><path d="M4 20v-4.5h4.5"/></svg><span><b>Abbonamento flessibile</b>Lo interrompi quando vuoi.</span></li>@endif
   <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg><span><b>Garanzia di rimborso di 30 giorni</b></span></li>
   <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7.5" cy="17.5" r="1.7"/><circle cx="17.5" cy="17.5" r="1.7"/></svg><span><b>Spedizione</b>2-3 giorni lavorativi</span></li>
   <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg><span><a href="https://www.lamess.it/terms" target="_blank" rel="noopener">Termini e condizioni</a></span></li>
  </ul>

  @if($product->details)
  <section class="pdp-details"><details class="acc" open><summary>Dettagli del prodotto</summary><div class="prose">{!! \Illuminate\Support\Str::markdown($product->details, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div></details></section>
  @endif
  <p class="muted small sku">Codice prodotto: <span data-v-sku>{{ $current->sku }}</span></p>
 </div>
</div>

@if($related->isNotEmpty())
<section class="related"><h2>Potrebbe piacerti anche</h2>
 <div class="pgrid">@foreach($related as $p)@include('site.shop._card', ['product' => $p])@endforeach</div>
</section>
@endif
@endsection
