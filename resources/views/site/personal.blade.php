@extends('site.layout')
@php
 $name = $d['display_name'] ?: $c->name;
 $headline = $d['headline'] ?: 'Incaricato Lamèss';
 $title = $name.' — '.$headline.' | LAMÈSS';
 $desc = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) ($d['bio'] ?: $shared['hero_lead'])), 155);
 $first = trim(explode(' ', $name)[0] ?? $name);
 $waHello = $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode('Ciao '.$name.', vorrei saperne di più su LAMÈSS.') : null;
 $waJoin = $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode('Ciao '.$name.', mi piacerebbe sapere come diventare incaricato LAMÈSS.') : null;
 $mailJoin = $d['email_public'] ? 'mailto:'.$d['email_public'].'?subject='.rawurlencode('Voglio diventare incaricato LAMÈSS') : null;
@endphp
@section('title', $title)
@section('width', 'pp')
@section('bodyclass', 'is-pp')
@push('head')
<meta name="description" content="{{ $desc }}">
<meta name="theme-color" content="#0d0a08">
@if($preview)<meta name="robots" content="noindex,nofollow">@else
<link rel="canonical" href="{{ url('/i/'.$page->slug) }}">
<meta property="og:type" content="profile"><meta property="og:title" content="{{ $title }}"><meta property="og:description" content="{{ $desc }}"><meta property="og:url" content="{{ url('/i/'.$page->slug) }}">
@if($photoUrl)<meta property="og:image" content="{{ url($photoUrl) }}">@endif
@endif
@endpush
@push('styles')
<link rel="stylesheet" href="/css/lamess-page.css?v={{ @filemtime(public_path('css/lamess-page.css')) ?: 1 }}">
@endpush
@section('nav_extra')
<a class="hide-md" href="#lamess">Lamèss</a><a class="hide-md" href="#bustina">Ingredienti</a><a class="hide-md" href="#ricerca">La ricerca</a><a class="hide-md" href="#ordina">Come ordinare</a>@if($d['media'] !== [])<a class="hide-md" href="#contenuti">Da vedere</a>@endif<a class="hide-md" href="#faq">Domande</a>
@endsection
@section('content')
@if($preview)<div class="preview-bar" role="status">Anteprima: così vedrà la pagina chi la visita, comprese le modifiche in attesa di approvazione.@if(($backUrl ?? '/area/pagina') !== '') <a href="{{ $backUrl ?? '/area/pagina' }}">{{ $backLabel ?? '← Torna alla modifica' }}</a>@endif</div>@endif

{{-- 1. Apertura --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="pp-bean" viewBox="0 0 24 34"><ellipse cx="12" cy="17" rx="10" ry="15" fill="currentColor"/><path d="M12 3c-5 8 5 20 0 28" fill="none" stroke="#0d0a08" stroke-opacity=".55" stroke-width="2" stroke-linecap="round"/></symbol></svg>
<header class="hero" id="top">
 <div class="beans" aria-hidden="true">@for($b = 1; $b <= 8; $b++)<svg class="bean b{{ $b }}"><use href="#pp-bean"/></svg>@endfor</div>
 <div class="wrap hero-in">
  <div class="hero-text">
   <p class="eyebrow">LAMÈSS · Cosmetic Coffee</p>
   <h1>{{ $name }}</h1>
   <p class="role">{{ $headline }}</p>
   <p class="hero-claim">{{ $shared['hero_claim'] }}</p>
   <p class="pp-lead">{{ $d['bio'] ?: $shared['hero_lead'] }}</p>
   <div class="cta-row">
    <a class="btn-p gold" href="{{ $refUrl }}">Registrati con me</a>
    @if($waHello)<a class="btn-p line pp-hero-wa" href="{{ $waHello }}" target="_blank" rel="noopener">Scrivimi su WhatsApp</a>@endif
    <a class="btn-p line" href="#perche">Scopri Lamèss</a>
   </div>
   <ul class="pp-chips">@foreach($shared['hero_chips'] as $chip)<li>{{ $chip }}</li>@endforeach</ul>
  </div>
  <div class="hero-visual">
   <div class="photo {{ $photoUrl ? '' : 'empty' }}">@if($photoUrl)<img src="{{ $photoUrl }}" alt="{{ $name }}" width="640" height="800" fetchpriority="high">@else<img src="{{ $shared['product_image'] }}" alt="Box Lamèss Cosmetic Coffee" width="640" height="800" fetchpriority="high">@endif</div>
   @if($photoUrl)<div class="product-card"><img src="{{ $shared['product_image'] }}" alt="Box Lamèss Cosmetic Coffee" width="300" height="300"></div>@endif
   <div class="seal"><span>Incaricato</span><b>LAMÈSS</b><small>{{ $c->code }}</small></div>
  </div>
 </div>
</header>

<div class="ribbon" aria-hidden="true">
 <div class="ribbon-track">
  @for($r = 0; $r < 4; $r++)@foreach($shared['ribbon'] as $w)<span>{{ $w }}</span><i>✦</i>@endforeach @endfor
 </div>
</div>

{{-- 2. Ti spiego Lamèss --}}
<section class="intro" id="lamess">
 <div class="wrap intro-in">
  <div class="intro-text">
   <div class="sec-head reveal"><p class="kicker">{{ $shared['intro_kicker'] }}</p><h2>{{ $shared['intro_title'] }}</h2></div>
   @foreach($shared['intro'] as $para)<p class="reveal">{{ $para }}</p>@endforeach
   <ul class="intro-facts reveal">@foreach($shared['intro_facts'] as $f)<li><b>{{ $f['value'] }}</b><span>{{ $f['label'] }}</span></li>@endforeach</ul>
  </div>
  <div class="scene reveal" aria-hidden="false">
   <svg class="cup-scene" viewBox="0 0 360 320" role="img" aria-label="Una bustina Lamèss si versa in una tazza di caffè">
    <defs><clipPath id="pp-cup-clip"><path d="M70 160H240V200A85 85 0 0 1 70 200Z"/></clipPath></defs>
    <use href="#pp-bean" class="sb sb1" x="30" y="70" width="22" height="30"/><use href="#pp-bean" class="sb sb2" x="300" y="110" width="18" height="26"/><use href="#pp-bean" class="sb sb3" x="288" y="238" width="20" height="28"/><use href="#pp-bean" class="sb sb4" x="22" y="210" width="16" height="22"/>
    <g class="pour"><line x1="160" y1="108" x2="160" y2="165"/></g>
    <g clip-path="url(#pp-cup-clip)"><rect class="coffee" x="60" y="160" width="190" height="140"/></g>
    <path class="cup" d="M70 160H240V200A85 85 0 0 1 70 200Z"/>
    <path class="cup" d="M240 178h20a26 26 0 0 1 0 52h-14"/>
    <path class="cup" d="M44 296H266"/>
    <g class="steam"><path d="M118 148c-10-14 10-22 0-38"/><path d="M155 148c-10-14 10-22 0-38"/><path d="M192 148c-10-14 10-22 0-38"/></g>
    <g class="sachet"><g transform="translate(128 24)"><rect width="64" height="84" rx="7" fill="#fff"/><rect y="30" width="64" height="9" fill="#E8862E"/><rect x="22" y="24" width="20" height="20" rx="2" fill="#fff" stroke="#E8862E" stroke-width="2"/><text x="32" y="40" text-anchor="middle" font-family="Marcellus,serif" font-size="15" fill="#13110E">L</text></g></g>
   </svg>
  </div>
 </div>
</section>

{{-- 3. Perché Lamèss --}}
<section class="why" id="perche">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">{{ $shared['why_kicker'] }}</p>
   <h2>{{ $shared['why_title'] }}</h2>
  </div>
  <ol class="why-list">
   @foreach($shared['why'] as $i => $w)
    <li class="reveal"><span class="pp-num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $w['title'] }}</h3><p>{{ $w['text'] }}</p></div></li>
   @endforeach
  </ol>
 </div>
</section>

{{-- 3. Nella bustina --}}
<section class="dose" id="bustina">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">{{ $shared['dose_kicker'] }}</p>
   <h2>{{ $shared['dose_title'] }}</h2>
   <p class="sub">{{ $shared['dose_text'] }}</p>
  </div>
  <div class="dose-grid">
   @foreach($shared['doses'] as $x)
    @php($num = (float) str_replace(',', '.', explode(' ', $x['value'])[0]))
    <div class="dose-card reveal"><b data-count="{{ $num }}" data-dec="{{ str_contains($x['value'], ',') ? 1 : 0 }}" data-unit=" {{ explode(' ', $x['value'])[1] ?? '' }}">{{ $x['value'] }}</b><h3>{{ $x['label'] }}</h3><p>{{ $x['text'] }}</p></div>
   @endforeach
  </div>
  <h3 class="ing-title reveal">{{ $shared['ingredients_title'] }}</h3>
  <ul class="ing-list">
   @foreach($shared['ingredients'] as $ing)
    <li class="reveal"><div><b>{{ $ing['name'] }}</b><span>{{ $ing['text'] }}</span></div><em>{{ $ing['dose'] }}</em></li>
   @endforeach
  </ul>
  <p class="ing-note reveal">{{ $shared['ingredients_note'] }}</p>
  <p class="monodose reveal">{{ $shared['monodose'] }}</p>
  <ul class="facts reveal">@foreach($shared['dose_facts'] as $f)<li>{{ $f }}</li>@endforeach</ul>
  <div class="prepare">
   @foreach($shared['prepare'] as $p)
    <div class="reveal"><h3>{{ $p['title'] }}</h3><p>{{ $p['text'] }}</p></div>
   @endforeach
  </div>
  <p class="more reveal"><a href="/il-prodotto">Scheda del prodotto →</a><a href="/la-scienza">La scienza, con le fonti →</a></p>
 </div>
</section>


{{-- 5. Ricerca --}}
<section class="research" id="ricerca">
 <div class="wrap">
  <div class="sec-head reveal"><p class="kicker">{{ $shared['research_kicker'] }}</p><h2>{{ $shared['research_title'] }}</h2><p class="sub">{{ $shared['research_text'] }}</p></div>
  <div class="stat-grid">
   @foreach($shared['research_stats'] as $st)
    @php($sn = (float) str_replace(',', '.', $st['value']))
    <div class="pp-stat reveal"><b data-count="{{ $sn }}" data-dec="{{ str_contains($st['value'], ',') ? 1 : 0 }}" data-unit="{{ $st['unit'] }}">{{ $st['value'].$st['unit'] }}</b><span>{{ $st['label'] }}</span></div>
   @endforeach
  </div>
  <p class="limit reveal"><strong>Cosa non dimostra.</strong> {{ $shared['research_limit'] }}</p>
  <p class="more dark reveal"><a href="/la-scienza">Tutti gli studi, con dosi e fonti →</a></p>
 </div>
</section>

{{-- 6. Per chi è --}}
<section class="foryou">
 <div class="wrap">
  <div class="sec-head reveal"><p class="kicker">{{ $shared['for_kicker'] }}</p><h2>{{ $shared['for_title'] }}</h2></div>
  <div class="for-grid">
   <div class="for-col yes reveal"><h3>{{ $shared['for_yes_title'] }}</h3><ul>@foreach($shared['for_yes'] as $t)<li>{{ $t }}</li>@endforeach</ul></div>
   <div class="for-col care reveal"><h3>{{ $shared['for_care_title'] }}</h3><ul>@foreach($shared['for_care'] as $t)<li>{{ $t }}</li>@endforeach</ul></div>
  </div>
 </div>
</section>

{{-- 7. Stop Time --}}
<section class="stop">
 <div class="wrap reveal">
  <svg class="clock" viewBox="0 0 200 200" role="img" aria-label="Un orologio la cui lancetta rallenta fino a fermarsi">
   <circle cx="100" cy="100" r="92" class="ring"/><circle cx="100" cy="100" r="84" class="ring thin"/>
   @for($t = 0; $t < 12; $t++)<line x1="100" y1="{{ $t % 3 === 0 ? 20 : 24 }}" x2="100" y2="32" class="tick" transform="rotate({{ $t * 30 }} 100 100)"/>@endfor
   <line x1="100" y1="100" x2="100" y2="58" class="hand-h"/><line x1="100" y1="100" x2="100" y2="38" class="hand-m" transform="rotate(62 100 100)"/>
   <g class="hand"><line x1="100" y1="112" x2="100" y2="30" class="hand-s"/></g><circle cx="100" cy="100" r="5" class="pin"/>
  </svg>
  <p class="kicker">{{ $shared['banner_kicker'] }}</p>
  <h2>{{ $shared['banner_title'] }}</h2>
  <p>{{ $shared['banner_text'] }}</p>
  <small>{{ $shared['banner_note'] }}</small>
 </div>
</section>

{{-- 5. Come ordinare --}}
<section class="pp-steps" id="ordina">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">{{ $shared['steps_kicker'] }}</p>
   <h2>{{ $shared['steps_title'] }}</h2>
  </div>
  <div class="pp-steps-grid">
   @foreach($shared['steps'] as $i => $s)
    <div class="pp-step reveal"><span class="pp-num">{{ $i + 1 }}</span><h3>{{ $s['title'] }}</h3><p>{{ $s['text'] }}</p></div>
   @endforeach
  </div>
  <h3 class="terms-title reveal">{{ $shared['terms_title'] }}</h3>
  <div class="terms">@foreach($shared['terms'] as $t)<div class="reveal"><h4>{{ $t['title'] }}</h4><p>{{ $t['text'] }}</p></div>@endforeach</div>
  <div class="cta-row center reveal">
   <a class="btn-p dark" href="{{ $refUrl }}">Registrati con me</a>
   @if(\App\Support\ShopAccess::canBuy())<a class="btn-p outline" href="/negozio">Vai al negozio</a>@else<a class="btn-p outline" href="/accedi">Accedi per acquistare</a>@endif
  </div>
 </div>
</section>


{{-- 8bis. Io e Lamèss --}}
<section class="pp-trust" id="fiducia">
 <div class="wrap">
  <div class="sec-head reveal"><p class="kicker">{{ $shared['trust_kicker'] }}</p><h2>{{ $shared['trust_title'] }}</h2></div>
  <div class="trust-grid">
   <div>
    <h3 class="sub-h reveal">{{ $shared['trust_role_title'] }}</h3>
    <ul class="role-list">@foreach($shared['trust_role'] as $r)<li class="reveal"><b>{{ $r['title'] }}</b><span>{{ $r['text'] }}</span></li>@endforeach</ul>
   </div>
   <div>
    <h3 class="sub-h reveal">{{ $shared['trust_behind_title'] }}</h3>
    <p class="behind reveal">{{ $shared['trust_behind_text'] }}</p>
    <div class="behind-facts">
     @foreach($shared['trust_facts'] as $tf)
      <div class="reveal"><b data-count="{{ $tf['value'] }}" data-dec="0" data-unit="{{ $tf['unit'] }}">{{ number_format((float) $tf['value'], 0, ',', '.').$tf['unit'] }}</b><span>{{ $tf['label'] }}</span></div>
     @endforeach
    </div>
   </div>
  </div>
 </div>
</section>

{{-- 9. Chi sono --}}
@if($d['story'])
<section class="about">
 <div class="wrap about-in reveal">
  <div><p class="kicker">Chi sono</p><h2>La mia storia con LAMÈSS</h2></div>
  <p class="story">{{ $d['story'] }}</p>
 </div>
</section>
@endif

@if($d['media'] !== [])
<section class="media" id="contenuti">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">Da vedere e da leggere</p>
   <h2>Consigliati da {{ $first }}</h2>
  </div>
  <div class="cards">
   @foreach($d['media'] as $m)
    <article class="pp-card reveal">
     <span class="pp-tag">{{ \App\Domain\Pages\PersonalPageService::MEDIA_KINDS[$m['kind'] ?? 'article'] ?? 'Articolo' }}</span>
     <h3>{{ $m['title'] }}</h3>
     @if(!empty($m['description']))<p>{{ $m['description'] }}</p>@endif
     <a href="{{ $m['url'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ ($m['kind'] ?? '') === 'video' ? 'Guarda il video' : 'Leggi l’articolo' }} →</a>
    </article>
   @endforeach
  </div>
 </div>
</section>
@endif

{{-- 7. Contatti --}}
<section class="contact" id="contatti">
 <div class="wrap contact-in">
  <div class="reveal">
   <p class="kicker">Contatti</p>
   <h2>Hai una domanda? Scrivimi.</h2>
   <p>Ti rispondo volentieri e ti aiuto a scegliere la box giusta. Per ordinare basta registrarsi: è gratuito e senza obbligo di acquisto.</p>
   <div class="cta-row">
    @if($waHello)<a class="btn-p dark" href="{{ $waHello }}" target="_blank" rel="noopener">Scrivimi su WhatsApp</a>@endif
    @if($d['email_public'])<a class="btn-p outline" href="mailto:{{ $d['email_public'] }}">Scrivimi una email</a>@endif
    <a class="btn-p {{ $waHello || $d['email_public'] ? 'gold-dark' : 'dark' }}" href="{{ $refUrl }}">Registrati con me</a>
   </div>
   @if($socials !== [])
    <div class="socials" aria-label="Social">@foreach($socials as $k => $u)<a href="{{ $u }}" target="_blank" rel="noopener noreferrer nofollow">{{ \App\Domain\Pages\PersonalPageService::SOCIALS[$k] }}</a>@endforeach</div>
   @endif
  </div>
  <div class="qr reveal">{!! \App\Domain\Content\ReferralQr::svg($c, 220) !!}<span>Inquadra per registrarti</span></div>
 </div>
</section>

{{-- 8. Domande --}}
<section class="faq-sec" id="faq">
 <div class="wrap faq">
  <div class="sec-head reveal"><p class="kicker">FAQ</p><h2>Domande frequenti</h2></div>
  @foreach($shared['faq'] as $f)<details class="reveal"><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
 </div>
</section>

{{-- 9. Invito finale: diventare incaricato --}}
<section class="join" id="incaricato">
 <div class="wrap join-in reveal">
  <p class="kicker">{{ $shared['join_kicker'] }}</p>
  <h2>{{ $shared['join_title'] }}</h2>
  <p class="join-text">{{ $shared['join_text'] }}</p>
  <ul class="join-points">@foreach($shared['join_points'] as $jp)<li>{{ $jp }}</li>@endforeach</ul>
  <div class="cta-row center">
   @if($waJoin)<a class="btn-p gold" href="{{ $waJoin }}" target="_blank" rel="noopener">Contattami su WhatsApp</a>@endif
   @if($mailJoin)<a class="btn-p {{ $waJoin ? 'line' : 'gold' }}" href="{{ $mailJoin }}">Scrivimi una email</a>@endif
   <a class="btn-p line" href="/lavora-con-noi">Come funziona</a>
  </div>
  <p class="disclaimer">{{ $shared['disclaimer'] }}</p>
 </div>
</section>

<div class="dock" role="navigation" aria-label="Azioni rapide">
 @if($waHello)<a class="dock-a" href="{{ $waHello }}" target="_blank" rel="noopener">WhatsApp</a>@endif
 <a class="dock-b" href="{{ $refUrl }}">Registrati con me</a>
</div>

<script>
(function(){var els=document.querySelectorAll('.pp .reveal');if(!('IntersectionObserver' in window)||window.matchMedia('(prefers-reduced-motion: reduce)').matches){els.forEach(function(e){e.classList.add('in')});return}
document.documentElement.classList.add('pp-anim');var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}})},{threshold:.12,rootMargin:'0px 0px -6% 0px'});els.forEach(function(e){io.observe(e)})})();
</script>
<script>
(function(){var cs=document.querySelectorAll('.pp [data-count]');if(!('IntersectionObserver' in window)||window.matchMedia('(prefers-reduced-motion: reduce)').matches){return}
function run(el){var to=parseFloat(el.dataset.count),dec=parseInt(el.dataset.dec||'0',10),unit=el.dataset.unit||'',t0=null,dur=1500,f=new Intl.NumberFormat('it-IT',{minimumFractionDigits:dec,maximumFractionDigits:dec});
function step(ts){if(t0===null){t0=ts}var p=Math.min((ts-t0)/dur,1),e=1-Math.pow(1-p,3);el.textContent=f.format(to*e)+unit;if(p<1){requestAnimationFrame(step)}}requestAnimationFrame(step)}
var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){run(x.target);io.unobserve(x.target)}})},{threshold:.6});cs.forEach(function(c){io.observe(c)})})();
</script>
@endsection
