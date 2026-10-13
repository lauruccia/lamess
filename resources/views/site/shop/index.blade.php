@extends('site.layout')
@section('title', ($category ? $category->name.' — ' : '').'Negozio — Lamèss')
@section('width', 'full')
@section('content')
@php
 $isHome = ! $category && $term === '' && $priceMin === null && $priceMax === null && $kmoneyMin === null && $products->currentPage() === 1;
 $qs = fn (array $over = []) => http_build_query(array_filter(array_merge(request()->except('page'), $over), fn ($v) => $v !== null && $v !== ''));
 $url = fn (array $over = []) => '/negozio'.(($q = $qs($over)) !== '' ? '?'.$q : '');
 $priceActive = $priceMin !== null || $priceMax !== null;
 $filtered = $category || $term !== '' || $priceActive || $kmoneyMin !== null;
 $total = $products->total();
 $ico = fn ($d) => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$d.'</svg>';
 $bars = function (int $n) {
     $w = (22 - ($n - 1) * 2) / $n; $out = '';
     for ($i = 0; $i < $n; $i++) { $out .= '<rect x="'.round($i * ($w + 2), 2).'" y="1" width="'.round($w, 2).'" height="14" rx=".8"/>'; }
     return '<svg width="22" height="16" viewBox="0 0 22 16" fill="currentColor" aria-hidden="true">'.$out.'</svg>';
 };
@endphp
@if($shippingPromo)<div class="promo-bar">Spedizione gratuita per ordini da <b>€ {{ $shippingPromo }}</b></div>@endif

@if($isHome)
<section class="shop-hero">
 <div class="hero-copy">
  <span class="eyebrow">Negozio Lamèss</span>
  <h1>I prodotti Lamèss, spediti a casa tua.</h1>
  <p>Con l’abbonamento mensile la spedizione è gratuita e puoi interromperlo quando vuoi.</p>
 </div>
 <ul class="trust" aria-label="Perché ordinare da noi">
  <li>{!! $ico('<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>') !!}<span><b>Pagamenti sicuri</b>Carta, bonifico o KMoney</span></li>
  <li>{!! $ico('<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7.5" cy="17.5" r="1.7"/><circle cx="17.5" cy="17.5" r="1.7"/>') !!}<span><b>Spedizione a casa</b>@if($shippingPromo)Gratuita da € {{ $shippingPromo }}@else Gratuita con l’abbonamento @endif</span></li>
  <li>{!! $ico('<path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5"/><path d="M20 4v4.5h-4.5"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 15.5"/><path d="M4 20v-4.5h4.5"/>') !!}<span><b>Abbonamento flessibile</b>Si interrompe quando vuoi</span></li>
  <li>{!! $ico('<path d="M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-8l-5 4v-4H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/>') !!}<span><b>Assistenza</b>Dalla tua area personale</span></li>
 </ul>
</section>
@endif

<section class="catalog" id="prodotti">
 @if($category?->isSubcategory() && $navRoot)<p class="cat-crumb"><a href="{{ $url(['categoria' => $navRoot->slug]) }}">{{ $navRoot->name }}</a> <span aria-hidden="true">/</span></p>@endif
 @if($category)<h1 class="cat-title">{{ $category->name }}</h1>@elseif(! $isHome)<h1 class="sr">Negozio</h1>@endif
 @if($category?->description)<p class="lead cat-lead">{{ $category->description }}</p>@endif
 @if($navRoot && $navRoot->children->isNotEmpty())
 <nav class="cat-chips" aria-label="Sottocategorie di {{ $navRoot->name }}">
  <a href="{{ $url(['categoria' => $navRoot->slug]) }}" class="{{ $category->id === $navRoot->id ? 'on' : '' }}">Tutto {{ $navRoot->name }} <i>{{ $navRoot->shop_count }}</i></a>
  @foreach($navRoot->children as $s)<a href="{{ $url(['categoria' => $s->slug]) }}" class="{{ $category->id === $s->id ? 'on' : '' }}">{{ $s->name }} <i>{{ $s->shop_count }}</i></a>@endforeach
 </nav>
 @endif

 <form class="shop-form" method="get" action="/negozio" role="search" aria-label="Filtri del negozio">
  @if($category)<input type="hidden" name="categoria" value="{{ $category->slug }}">@endif
  <input type="hidden" name="vista" value="{{ $view }}"><input type="hidden" name="colonne" value="{{ $cols }}">

  <div class="filters">
   <div class="f-search">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
    <input type="search" name="q" value="{{ $term }}" placeholder="Cerca un prodotto" aria-label="Cerca un prodotto">
   </div>

   @if($categories->isNotEmpty() || $category)
   <details class="dd dd-cat {{ $category ? 'active' : '' }}" data-dd>
    <summary>@if($category)<b>{{ $category->name }}</b>@else Categorie @endif</summary>
    <div class="dd-panel">
     <ul class="cat-list" aria-label="Categorie">
      <li><a href="{{ $url(['categoria' => null]) }}" class="{{ $category ? '' : 'on' }}">Tutti i prodotti <i>{{ $totalVisible }}</i></a></li>
      @foreach($categories as $c)
      <li><a href="{{ $url(['categoria' => $c->slug]) }}" class="{{ $category?->id === $c->id ? 'on' : '' }}">{{ $c->name }} <i>{{ $c->shop_count }}</i></a>
       @if($c->children->isNotEmpty())
       <ul class="cat-sub" aria-label="Sottocategorie di {{ $c->name }}">@foreach($c->children as $s)<li><a href="{{ $url(['categoria' => $s->slug]) }}" class="{{ $category?->id === $s->id ? 'on' : '' }}">{{ $s->name }} <i>{{ $s->shop_count }}</i></a></li>@endforeach</ul>
       @endif
      </li>
      @endforeach
     </ul>
    </div>
   </details>
   @endif

   @if($priceBounds || $priceActive)
   <details class="dd {{ $priceActive ? 'active' : '' }}" data-dd>
    <summary>Prezzo @if($priceActive)<b>{{ $priceMin !== null ? '€ '.\App\Support\Money::eur($priceMin) : '' }}{{ $priceMin !== null && $priceMax !== null ? ' – ' : ($priceMax !== null ? 'fino a ' : '+') }}{{ $priceMax !== null ? '€ '.\App\Support\Money::eur($priceMax) : '' }}</b>@endif</summary>
    <div class="dd-panel">
     <p class="muted small">Prezzi IVA inclusa{{ $priceBounds ? ' · da € '.$priceBounds[0].' a € '.$priceBounds[1] : '' }}</p>
     <div class="range">
      <label>Da<input type="number" name="prezzo_min" min="0" step="1" inputmode="decimal" value="{{ $priceMin }}" placeholder="{{ $priceBounds[0] ?? 0 }}"></label>
      <label>A<input type="number" name="prezzo_max" min="0" step="1" inputmode="decimal" value="{{ $priceMax }}" placeholder="{{ $priceBounds[1] ?? '' }}"></label>
     </div>
     <div class="dd-act"><button class="add">Applica</button><a href="{{ $url(['prezzo_min' => null, 'prezzo_max' => null]) }}">Azzera</a></div>
    </div>
   </details>
   @endif

   @if($kmoneyAvailable)
   <details class="dd {{ $kmoneyMin !== null ? 'active' : '' }}" data-dd>
    <summary>KMoney @if($kmoneyMin !== null)<b>{{ $kmoneyFilters[$kmoneyMin] }}</b>@endif</summary>
    <div class="dd-panel">
     <p class="muted small">Quota del prezzo che puoi pagare con KMoney</p>
     <ul class="cat-list" aria-label="Filtro KMoney">
      <li><a href="{{ $url(['kmoney' => null]) }}" class="{{ $kmoneyMin === null ? 'on' : '' }}">Tutti i prodotti</a></li>
      @foreach($kmoneyFilters as $min => $label)<li><a href="{{ $url(['kmoney' => $min]) }}" class="{{ $kmoneyMin === $min ? 'on' : '' }}">{{ $label }}</a></li>@endforeach
     </ul>
    </div>
   </details>
   @endif

   <p class="count" aria-live="polite">@if($total > 1){{ $products->firstItem() }}–{{ $products->lastItem() }} di {{ $total }} prodotti @else {{ $total }} {{ $total === 1 ? 'prodotto' : 'prodotti' }} @endif</p>
   <div class="tb-right">
    <label class="tb-sel" for="ordina">Ordina<select id="ordina" name="ordina" onchange="this.form.submit()">@foreach($sorts as $k => $label)<option value="{{ $k }}" @selected($sort === $k)>{{ $label }}</option>@endforeach</select></label>
    <label class="tb-sel" for="perpagina">Per pagina<select id="perpagina" name="perpagina" onchange="this.form.submit()">@foreach($perPageOptions as $n)<option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>@endforeach</select></label>
    <div class="view-switch" role="group" aria-label="Vista dei prodotti">
     <a class="vs {{ $view === 'elenco' ? 'on' : '' }}" href="{{ $url(['vista' => 'elenco', 'perpagina' => null]) }}" title="Elenco" aria-label="Elenco" @if($view === 'elenco') aria-current="true" @endif><svg width="18" height="16" viewBox="0 0 24 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8 4h14M8 10h14M8 16h14"/><circle cx="3" cy="4" r="1"/><circle cx="3" cy="10" r="1"/><circle cx="3" cy="16" r="1"/></svg></a>
     <a class="vs grid-btn {{ $view === 'griglia' ? 'on' : '' }}" href="{{ $url(['vista' => 'griglia', 'perpagina' => null]) }}" title="Griglia" aria-label="Griglia">{!! $bars(2) !!}</a>
     @foreach([3, 4, 5, 6] as $n)<a class="vs num {{ $view === 'griglia' && $cols === $n ? 'on' : '' }}" href="{{ $url(['vista' => 'griglia', 'colonne' => $n, 'perpagina' => null]) }}" title="{{ $n }} colonne" aria-label="{{ $n }} colonne" @if($view === 'griglia' && $cols === $n) aria-current="true" @endif>{!! $bars($n) !!}</a>@endforeach
    </div>
   </div>
  </div>
  <noscript><button class="add">Applica</button></noscript>
 </form>

 @if($filtered)
 <div class="active-filters" aria-label="Filtri attivi">
  @if($category)<a class="pill-x" href="{{ $url(['categoria' => null]) }}">{{ $category->name }} <span aria-hidden="true">✕</span><span class="sr">Rimuovi</span></a>@endif
  @if($term !== '')<a class="pill-x" href="{{ $url(['q' => null]) }}">«{{ $term }}» <span aria-hidden="true">✕</span><span class="sr">Rimuovi</span></a>@endif
  @if($priceActive)<a class="pill-x" href="{{ $url(['prezzo_min' => null, 'prezzo_max' => null]) }}">Prezzo <span aria-hidden="true">✕</span><span class="sr">Rimuovi</span></a>@endif
  @if($kmoneyMin !== null)<a class="pill-x" href="{{ $url(['kmoney' => null]) }}">KMoney: {{ $kmoneyFilters[$kmoneyMin] }} <span aria-hidden="true">✕</span><span class="sr">Rimuovi</span></a>@endif
  <a class="clear-all" href="/negozio">Azzera tutti i filtri</a>
 </div>
 @endif

 <div class="pgrid {{ $view === 'elenco' ? 'list' : '' }}" data-cols="{{ $view === 'elenco' ? 1 : $cols }}" style="--cols:{{ $cols }}">
  @forelse($products as $p)@include('site.shop._card', ['product' => $p])@empty
  <div class="empty" style="grid-column:1/-1"><strong>Nessun prodotto trovato</strong>@if($filtered)Prova a cambiare i filtri o <a href="/negozio">guarda tutto il negozio</a>.@else Torna a trovarci presto.@endif</div>
  @endforelse
 </div>
 {{ $products->links('vendor.pagination.lamess') }}
</section>
@endsection
