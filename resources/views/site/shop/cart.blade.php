@extends('site.layout')
@section('title', __('Carrello — Lamèss'))
@section('width', 'full')
@section('content')
<header class="page-head"><h1>{{ __('Carrello') }}</h1>@unless($priced->isEmpty() && count($others) === 0)@include('site.shop._steps', ['current' => 1])@endunless</header>
@foreach($notes as $n)<div class="warn">{{ $n }}</div>@endforeach
@if(! empty($pendingOrder))<div class="warn">{{ __('Hai un ordine in attesa di pagamento (:p):', ['p' => $pendingOrder->number]) }} <a href="/area/ordini/{{ $pendingOrder->id }}">{{ __('completa il pagamento') }}</a>{{ __('. Se invece riordini da qui, quell\'ordine in sospeso viene annullato.') }}</div>@endif
@if($priced->isEmpty() && count($others) === 0)
 <div class="empty empty-cart"><span class="empty-ico"><svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg></span>{!! __('<strong>Il carrello è vuoto</strong>Quando aggiungi un prodotto lo trovi qui.<br><a class="btn accent" href="/negozio">Vai al negozio</a>') !!}</div>
@else
<div class="cart-grid">
 <div>
  <form method="post" action="/carrello/aggiorna" class="card cart-lines" id="cart-form">@csrf
   @include('site.shop._shipbar')
   @foreach($priced->lines as $key => $line)@if(str_starts_with($key, 'me:'))@include('site.shop._line', ['key' => $key, 'line' => $line, 'variant' => 'cart'])@endif @endforeach
   @foreach($others as $uid => $ls)
    <p class="line-group">{{ __('Per :p', ['p' => $names[$uid] ?? 'destinatario']) }}</p>
    @foreach($priced->lines as $key => $line)@if(str_starts_with($key, $uid.':'))@include('site.shop._line', ['key' => $key, 'line' => $line, 'variant' => 'cart'])@endif @endforeach
   @endforeach
   @foreach($gifts as $g)<p class="gift">{{ __('🎁 Omaggio: :p × :p2', ['p' => $g['qty'], 'p2' => $g['name']]) }}</p>@endforeach
   <noscript><button class="ghostbtn">{{ __('Aggiorna quantità') }}</button></noscript>
   <button class="ghostbtn" data-js-hide>{{ __('Aggiorna quantità') }}</button>
  </form>
  <p class="back-link"><a href="/negozio">{{ __('← Continua gli acquisti') }}</a></p>

  @if(count($recipients) > 0)
  <form method="post" action="/area/carrello/per-altri" class="card">@csrf
   <h2>{{ __('Ordine per altri') }}</h2>
   <p class="muted">{{ __('Aggiungi prodotti per un tuo cliente o per un incaricato della tua rete: un solo ordine, una sola spedizione:p.', ['p' => \App\Domain\Plan\LegacyPlan::visible() ? ', i punti vanno a chi riceve' : '']) }}</p>
   <div class="row">
    <div><label>{{ __('Destinatario') }}</label><select name="recipient_id">@foreach($recipients as $uid=>$label)<option value="{{ $uid }}">{{ $label }}</option>@endforeach</select></div>
    <div><label>{{ __('Prodotto') }}</label><select name="product_id">@foreach($catalog as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div><label>{{ __('Quantità') }}</label><input type="number" name="qty" value="1" min="1" max="99"></div>
   </div>
   @error('order')<div class="err">{{ $message }}</div>@enderror
   <button class="btn ghost">{{ __('Aggiungi per questo destinatario') }}</button>
  </form>
  @endif
 </div>

 <aside class="summary card">
  <h2>{{ __('Riepilogo') }}</h2>
  @include('site.shop._code', ['uid' => 'cart'])
  @include('site.shop._totals')
  <a class="btn accent wide cta-lock" href="/area/checkout?nuovo=1"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> {{ __('Procedi al checkout') }}</a>
  <p class="secure-note">{{ __('Pagamento sicuro · Carta, bonifico o KMoney') }}</p>
  @guest<p class="muted small center">{!! __('Per ordinare serve un account: <a href="/accedi">accedi</a>') !!}@if(\App\Domain\Customers\RegistrationGate::linksVisible()) {{ __('oppure') }} <a href="/registrazione">{{ __('registrati') }}</a>@endif{{ __('. Il carrello resta salvato.') }}</p>@endguest
 </aside>
</div>
@endif
@endsection
