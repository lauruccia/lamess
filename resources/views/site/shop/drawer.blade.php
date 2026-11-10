<div class="drawer-head"><h2>{{ __('Carrello') }} <span class="muted">({{ $count }})</span></h2><button type="button" class="x" data-drawer-close aria-label="{{ __('Chiudi il carrello') }}">✕</button></div>
@if($priced->isEmpty())
 <div class="drawer-empty"><span class="ph-sm" aria-hidden="true">L</span><p><b>{{ __('Il carrello è vuoto') }}</b></p><p class="muted">{{ __('Aggiungi un prodotto per iniziare.') }}</p><a class="btn accent" href="/negozio" data-drawer-close>{{ __('Vai al negozio') }}</a></div>
@else
 <div class="drawer-scroll">
  @include('site.shop._shipbar')
  @php($shown = [])
  @foreach($priced->lines as $key => $line)
   @if(str_starts_with($key, 'me:'))@include('site.shop._line', ['key' => $key, 'line' => $line, 'variant' => 'drawer'])@endif
  @endforeach
  @foreach($others as $uid => $ls)
   <p class="line-group">{{ __('Per :p', ['p' => $names[$uid] ?? 'destinatario']) }}</p>
   @foreach($priced->lines as $key => $line)@if(str_starts_with($key, $uid.':'))@include('site.shop._line', ['key' => $key, 'line' => $line, 'variant' => 'drawer'])@endif @endforeach
  @endforeach
  @foreach($gifts as $g)<p class="gift">{{ __('🎁 Omaggio: :p × :p2', ['p' => $g['qty'], 'p2' => $g['name']]) }}</p>@endforeach
 </div>
 <div class="drawer-foot">
  @include('site.shop._totals')
  <a class="btn accent wide" href="/area/checkout?nuovo=1">{{ __('Procedi al checkout') }}</a>
  <a class="btn ghost wide" href="/carrello">{{ __('Vedi il carrello') }}</a>
 </div>
@endif
