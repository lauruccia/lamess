@php($url = $product->coverUrl())
@if($url)<img src="{{ $url }}" alt="{{ $alt ?? $product->name }}" loading="{{ $eager ?? false ? 'eager' : 'lazy' }}" decoding="async">@else<span class="ph" aria-hidden="true">L</span>@endif
