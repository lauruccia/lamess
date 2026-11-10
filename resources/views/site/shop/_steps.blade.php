{{-- Passi dell'acquisto. Variabile: $current = 1 (carrello), 2 (checkout) --}}
<ol class="steps" aria-label="{{ __('Passi dell\'acquisto') }}">
 <li class="{{ $current > 1 ? 'done' : 'on' }}"><span class="n">{{ $current > 1 ? '✓' : '1' }}</span>{{ __('Carrello') }}</li>
 <li class="{{ $current > 2 ? 'done' : ($current === 2 ? 'on' : '') }}" @if($current === 2) aria-current="step" @endif><span class="n">{{ $current > 2 ? '✓' : '2' }}</span>{{ __('Checkout') }}</li>
 <li><span class="n">3</span>{{ __('Conferma') }}</li>
</ol>
