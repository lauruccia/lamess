@extends('site.layout')
@php
 $name = $d['display_name'] ?: $c->name;
 $headline = $d['headline'] ?: 'Incaricato Lamèss';
 $title = $name.' — '.$headline.' | LAMÈSS';
 $desc = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) ($d['bio'] ?: $shared['hero_lead'])), 155);
 $initial = mb_strtoupper(mb_substr($name, 0, 1));
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
<a class="hide-md" href="#perche">Perché Lamèss</a><a class="hide-md" href="#bustina">Il prodotto</a><a class="hide-md" href="#ordina">Come ordinare</a>@if($d['media'] !== [])<a class="hide-md" href="#contenuti">Da vedere</a>@endif<a class="hide-md" href="#faq">Domande</a>
@endsection
@section('content')
@if($preview)<div class="preview-bar" role="status">Anteprima: così vedrà la pagina chi la visita, comprese le modifiche in attesa di approvazione. <a href="{{ $backUrl ?? '/area/pagina' }}">{{ $backLabel ?? '← Torna alla modifica' }}</a></div>@endif

{{-- 1. Apertura --}}
<header class="hero" id="top">
 <div class="wrap hero-in">
  <div class="hero-text">
   <p class="eyebrow">LAMÈSS · Cosmetic Coffee</p>
   <h1>{{ $name }}</h1>
   <p class="role">{{ $headline }}</p>
   <p class="lead">{{ $d['bio'] ?: $shared['hero_lead'] }}</p>
   <div class="cta-row">
    <a class="btn-p gold" href="{{ $refUrl }}">Registrati con me</a>
    <a class="btn-p line" href="#perche">Scopri Lamèss</a>
   </div>
   <p class="hero-note">Registrazione gratuita · nessun obbligo di acquisto</p>
  </div>
  <div class="hero-visual">
   <div class="photo {{ $photoUrl ? '' : 'empty' }}">@if($photoUrl)<img src="{{ $photoUrl }}" alt="{{ $name }}" width="640" height="800" fetchpriority="high">@else<span aria-hidden="true">{{ $initial }}</span>@endif</div>
   <div class="seal"><span>Incaricato</span><b>LAMÈSS</b><small>{{ $c->code }}</small></div>
  </div>
 </div>
</header>

<div class="ribbon" aria-hidden="true">
 <div class="ribbon-track">
  @for($r = 0; $r < 4; $r++)@foreach($shared['ribbon'] as $w)<span>{{ $w }}</span><i>✦</i>@endforeach @endfor
 </div>
</div>

{{-- 2. Perché Lamèss --}}
<section class="why" id="perche">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">{{ $shared['why_kicker'] }}</p>
   <h2>{{ $shared['why_title'] }}</h2>
  </div>
  <ol class="why-list">
   @foreach($shared['why'] as $i => $w)
    <li class="reveal"><span class="num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $w['title'] }}</h3><p>{{ $w['text'] }}</p></div></li>
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
    <div class="dose-card reveal"><b>{{ $x['value'] }}</b><h3>{{ $x['label'] }}</h3><p>{{ $x['text'] }}</p></div>
   @endforeach
  </div>
  <ul class="facts reveal">@foreach($shared['dose_facts'] as $f)<li>{{ $f }}</li>@endforeach</ul>
  <div class="prepare">
   @foreach($shared['prepare'] as $p)
    <div class="reveal"><h3>{{ $p['title'] }}</h3><p>{{ $p['text'] }}</p></div>
   @endforeach
  </div>
  <p class="more reveal"><a href="/il-prodotto">Tabella degli ingredienti →</a><a href="/la-scienza">La scienza, con le fonti →</a></p>
 </div>
</section>

{{-- 4. Stop Time --}}
<section class="stop">
 <div class="wrap reveal">
  <p class="kicker">{{ $shared['banner_kicker'] }}</p>
  <h2>{{ $shared['banner_title'] }}</h2>
  <p>{{ $shared['banner_text'] }}</p>
  <small>{{ $shared['banner_note'] }}</small>
 </div>
</section>

{{-- 5. Come ordinare --}}
<section class="steps" id="ordina">
 <div class="wrap">
  <div class="sec-head reveal">
   <p class="kicker">{{ $shared['steps_kicker'] }}</p>
   <h2>{{ $shared['steps_title'] }}</h2>
  </div>
  <div class="steps-grid">
   @foreach($shared['steps'] as $i => $s)
    <div class="step reveal"><span class="num">{{ $i + 1 }}</span><h3>{{ $s['title'] }}</h3><p>{{ $s['text'] }}</p></div>
   @endforeach
  </div>
  <div class="cta-row center reveal">
   <a class="btn-p dark" href="{{ $refUrl }}">Registrati con me</a>
   <a class="btn-p outline" href="/negozio">Vai al negozio</a>
  </div>
 </div>
</section>

{{-- 6. Chi sono --}}
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
    <article class="card reveal">
     <span class="tag">{{ \App\Domain\Pages\PersonalPageService::MEDIA_KINDS[$m['kind'] ?? 'article'] ?? 'Articolo' }}</span>
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
@endsection
