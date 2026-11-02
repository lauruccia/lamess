{{-- Popup «Cosa c'è in una bustina?»: benefici del prodotto a schede animate. Testi presi dal sito (Perché Lamèss, Prodotto). --}}
@php
    $benefitSlides = [
        ['kicker' => 'Una bustina', 'title' => 'Caffè e integrazione nello stesso gesto', 'text' => 'Il caffè lo bevi già: mettendo tutto nella stessa bustina non devi ricordarti di niente in più.', 'visual' => 'cup'],
        ['kicker' => 'In ogni dose giornaliera', 'title' => 'Dosi chiare, scritte in etichetta', 'text' => 'Insieme a vitamine E e C, selenio, beta-carotene e biotina. Valori per la dose massima di 2 bustine.', 'stats' => [['to' => '2.5', 'dec' => 1, 'unit' => 'g', 'label' => 'collagene VERISOL®'], ['to' => '190', 'dec' => 0, 'unit' => 'mg', 'label' => 'acido ialuronico'], ['to' => '20', 'dec' => 0, 'unit' => 'mg', 'label' => 'coenzima Q10']]],
        ['kicker' => 'Il gusto', 'title' => 'Un caffè vero', 'text' => 'Caffè istantaneo 100% Arabica di classe tripla AAA: il gusto del caffè, senza compromessi.', 'chips' => ['100% Arabica AAA', 'Zero zuccheri', 'Senza glutine', 'Senza lattosio']],
        ['kicker' => 'Ovunque tu sia', 'title' => 'Una bustina e acqua, calda o fredda', 'text' => 'Ufficio, viaggio, hotel, palestra. Non servono macchina né capsule.', 'swap' => ['Calda', 'Fredda'], 'chips' => ['Ufficio', 'Viaggio', 'Hotel', 'Palestra']],
        ['kicker' => 'Abbonamento', 'title' => 'Nessuna trappola', 'text' => 'Se scegli l’abbonamento non paghi la spedizione, puoi saltare un mese se parti per le vacanze e interromperlo quando vuoi.', 'ticks' => ['Spedizione gratuita', 'Salti un mese quando vuoi', 'Interrompi quando vuoi']],
    ];
@endphp
@php
    // chicchi che cadono piano dietro le schede: [sinistra %, larghezza px, durata s, ritardo s (negativo: già in volo), rotazione gradi, deriva px, colore]
    $beans = [[6, 20, 13, -2, 20, 18, '#C2663A'], [15, 12, 17, -9, -40, -14, '#E3AA8D'], [24, 24, 11, -5, 70, 22, '#894C2D'], [33, 14, 15, -12, 10, -20, '#C2663A'], [42, 18, 12, -7, -25, 16, '#E3AA8D'], [51, 11, 18, -1, 55, -12, '#C2663A'], [59, 22, 14, -10, -60, 20, '#894C2D'], [67, 13, 16, -4, 35, -18, '#E3AA8D'], [75, 26, 10, -8, -15, 24, '#C2663A'], [83, 15, 15, -13, 80, -16, '#894C2D'], [91, 19, 13, -6, -35, 14, '#E3AA8D'], [96, 12, 19, -3, 45, -10, '#C2663A']];
@endphp
<dialog id="benefits" class="bn" aria-labelledby="bn-title" aria-describedby="bn-sub">
  <div class="bn-box">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><symbol id="bn-bean" viewBox="0 0 24 32"><ellipse cx="12" cy="16" rx="10" ry="14" fill="currentColor"/><path d="M12 3c-5 8 5 18 0 26" fill="none" stroke="#13110E" stroke-width="2" stroke-linecap="round"/></symbol></svg>
    <div class="bn-beans" aria-hidden="true">
      @foreach($beans as [$x, $w, $t, $d, $r, $sw, $c])<svg viewBox="0 0 24 32" style="--x: {{ $x }}%; --s: {{ $w }}px; --t: {{ $t }}s; --d: {{ $d }}s; --r: {{ $r }}deg; --sw: {{ $sw }}px; color: {{ $c }}"><use href="#bn-bean"/></svg>@endforeach
    </div>
    <header class="bn-top">
      <p class="bn-brand" id="bn-title">Lamèss Cosmetic Coffee</p>
      <p class="bn-sr" id="bn-sub">Cinque schede sui benefici del prodotto. Usa le frecce per scorrerle.</p>
      <button type="button" class="bn-x" data-bn-close aria-label="Chiudi">×</button>
    </header>

    <div class="bn-stage" aria-live="off">
      @foreach($benefitSlides as $i => $s)
      <section class="bn-slide" data-i="{{ $i }}" aria-label="Scheda {{ $i + 1 }} di {{ count($benefitSlides) }}" @if($i > 0) hidden @endif>
        <p class="bn-k">{{ $s['kicker'] }}</p>
        @if(($s['visual'] ?? null) === 'cup')
        <div class="bn-cup" aria-hidden="true">
          <svg class="bn-scene" viewBox="0 0 160 150" width="200" height="188">
            <defs><clipPath id="bn-in"><path d="M42 82h76v26a28 28 0 0 1-28 28H70a28 28 0 0 1-28-28Z"/></clipPath></defs>
            <g class="steam"><path class="st s1" d="M58 66c-8-10 8-14 0-26"/><path class="st s2" d="M80 66c-8-10 8-14 0-26"/><path class="st s3" d="M102 66c-8-10 8-14 0-26"/></g>
            <g clip-path="url(#bn-in)"><rect class="liq" x="30" y="82" width="100" height="60" fill="#9A6240"/></g>
            <path d="M36 78h88v30a32 32 0 0 1-32 32H68a32 32 0 0 1-32-32Z" fill="none" stroke="currentColor" stroke-width="3"/><path d="M124 88h10a14 14 0 0 1 0 28h-14" fill="none" stroke="currentColor" stroke-width="3"/><path d="M30 144h100" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            <line class="pour" x1="80" y1="44" x2="80" y2="90" stroke="#E9D9C6" stroke-width="3" stroke-linecap="round" stroke-dasharray="1 7"/>
            <g class="sach"><rect x="-22" y="-31" width="44" height="62" rx="3" fill="#FFFFFF"/><rect x="-22" y="-8" width="44" height="7" fill="#F28C28"/><rect x="-7" y="-12" width="14" height="14" fill="#FFFFFF" stroke="#F28C28" stroke-width="1.2"/><text x="0" y="-1.5" text-anchor="middle" font-family="Georgia,serif" font-size="11" fill="#2B2B2B">L</text><rect class="tear" x="-22" y="-34" width="44" height="5" fill="#EFE6DC"/></g>
          </svg>
        </div>
        @endif
        <h2>{{ $s['title'] }}</h2>
        @isset($s['stats'])
        <ul class="bn-stats">
          @foreach($s['stats'] as $st)
          <li><b><span data-to="{{ $st['to'] }}" data-dec="{{ $st['dec'] }}">{{ str_replace('.', ',', $st['to']) }}</span><small>{{ $st['unit'] }}</small></b><span>{{ $st['label'] }}</span></li>
          @endforeach
        </ul>
        @endisset
        @isset($s['swap'])
        <div class="bn-temp" aria-hidden="true">
          <span class="lay hot"><svg viewBox="0 0 90 60" width="90" height="60"><path class="w w1" d="M20 56c-7-9 7-13 0-24"/><path class="w w2" d="M45 56c-7-9 7-13 0-24"/><path class="w w3" d="M70 56c-7-9 7-13 0-24"/></svg><b>{{ $s['swap'][0] }}</b></span>
          <span class="lay cold"><b>{{ $s['swap'][1] }}</b><i class="ice c1"></i><i class="ice c2"></i><i class="ice c3"></i></span>
        </div>
        @endisset
        @isset($s['chips'])
        <ul class="bn-chips">@foreach($s['chips'] as $c)<li style="--d: {{ $loop->index * 90 }}ms">{{ $c }}</li>@endforeach</ul>
        @endisset
        @isset($s['ticks'])
        <ul class="bn-ticks">@foreach($s['ticks'] as $c)<li style="--d: {{ $loop->index * 140 }}ms"><i aria-hidden="true">✓</i>{{ $c }}</li>@endforeach</ul>
        @endisset
        <p class="bn-t">{{ $s['text'] }}</p>
      </section>
      @endforeach
    </div>

    <div class="bn-nav">
      <button type="button" class="bn-arrow" data-bn-prev aria-label="Scheda precedente">‹</button>
      <ol class="bn-dots" role="tablist" aria-label="Schede">
        @foreach($benefitSlides as $i => $s)<li><button type="button" role="tab" data-go="{{ $i }}" aria-label="Scheda {{ $i + 1 }}: {{ $s['title'] }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"><i></i></button></li>@endforeach
      </ol>
      <button type="button" class="bn-arrow" data-bn-next aria-label="Scheda successiva">›</button>
      <button type="button" class="bn-pause" data-bn-pause aria-pressed="false">Pausa</button>
    </div>

    <footer class="bn-foot">
      <a class="bn-cta" href="/il-prodotto" target="_blank" rel="noopener">Tutti gli ingredienti</a>
      <button type="button" class="bn-back" data-bn-close>Torna alla registrazione</button>
    </footer>
    <p class="bn-legal">Lamèss Cosmetic Coffee è un integratore alimentare e non un medicinale; non sostituisce una dieta varia ed equilibrata e uno stile di vita sano.</p>
  </div>
</dialog>
