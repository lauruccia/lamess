{{-- Passi dell'acquisto. Variabile: $current = 1 (carrello), 2 (checkout) --}}
<ol class="steps" aria-label="Passi dell'acquisto">
 <li class="{{ $current > 1 ? 'done' : 'on' }}"><span class="n">{{ $current > 1 ? '✓' : '1' }}</span>Carrello</li>
 <li class="{{ $current > 2 ? 'done' : ($current === 2 ? 'on' : '') }}" @if($current === 2) aria-current="step" @endif><span class="n">{{ $current > 2 ? '✓' : '2' }}</span>Checkout</li>
 <li><span class="n">3</span>Conferma</li>
</ol>
