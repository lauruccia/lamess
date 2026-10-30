@extends('site.layout')
@php
 $name = $d['display_name'] ?: $c->name;
 $headline = $d['headline'] ?: 'Incaricato Lamèss';
 $title = $name.' — '.$headline.' | LAMÈSS';
 $desc = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) ($d['bio'] ?: $shared['product_text'])), 155);
 $initial = mb_strtoupper(mb_substr($name, 0, 1));
@endphp
@section('title', $title)
@section('width', 'pp')
@section('bodyclass', 'is-pp')
@push('head')
<meta name="description" content="{{ $desc }}">
<meta name="theme-color" content="#13110E">
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
<a class="hide-md" href="#prodotto">Il prodotto</a>@if($d['media'] !== [])<a class="hide-md" href="#contenuti">Da vedere</a>@endif<a class="hide-md" href="#contatti">Contatti</a><a class="hide-md" href="#faq">Domande</a>
@endsection
@section('content')
@if($preview)<div class="preview-bar" role="status">Anteprima: così vedrà la pagina chi la visita, comprese le modifiche in attesa di approvazione. <a href="/area/pagina">← Torna alla modifica</a></div>@endif

<header class="hero" id="top">
 <div class="wrap hero-in">
  <div>
   <div class="eyebrow">Incaricato LAMÈSS · {{ $c->code }}</div>
   <h1>{{ $name }}</h1>
   <p class="role">{{ $headline }}</p>
   @if($d['bio'])<p class="bio">{{ $d['bio'] }}</p>@endif
   <div class="cta-row">
    <a class="btn-p gold" href="{{ $refUrl }}">Registrati con me</a>
    @if($wa)<a class="btn-p line" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Ciao '.$name.', vorrei saperne di più su LAMÈSS.') }}" target="_blank" rel="noopener">Scrivimi su WhatsApp</a>@endif
    <a class="btn-p line" href="/negozio">Vai al negozio</a>
   </div>
  </div>
  <div class="photo {{ $photoUrl ? '' : 'empty' }}">@if($photoUrl)<img src="{{ $photoUrl }}" alt="{{ $name }}" width="640" height="800" fetchpriority="high">@else<span aria-hidden="true">{{ $initial }}</span>@endif</div>
 </div>
</header>

<section id="prodotto">
 <div class="wrap">
  <div class="intro">
   <div><p class="kicker">Il prodotto</p><h2>{{ $shared['product_title'] }}</h2></div>
   <p>{{ $shared['product_text'] }}</p>
  </div>
  <div class="pillars">
   @foreach($shared['pillars'] as $p)<div class="pillar"><div class="n">{{ $p['n'] }}</div><h3>{{ $p['title'] }}</h3><p>{{ $p['text'] }}</p></div>@endforeach
  </div>
 </div>
</section>

<section class="banner">
 <div class="wrap">
  <h2>{{ $shared['banner_title'] }}</h2>
  <p>{{ $shared['banner_text'] }}</p>
  <div class="cta-row"><a class="btn-p gold" href="{{ $refUrl }}">Inizia da qui</a></div>
 </div>
</section>

@if($d['story'])
<section>
 <div class="wrap story">
  <p class="kicker">Chi sono</p>
  <h2>La mia storia con LAMÈSS</h2>
  <p>{{ $d['story'] }}</p>
 </div>
</section>
@endif

@if($d['media'] !== [])
<section id="contenuti" style="padding-top:{{ $d['story'] ? '0' : '84px' }}">
 <div class="wrap">
  <p class="kicker">Da vedere e da leggere</p>
  <h2>Consigliati da {{ $name }}</h2>
  <div class="cards">
   @foreach($d['media'] as $m)
    <article class="card">
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

<section class="contact" id="contatti">
 <div class="wrap contact-in">
  <div>
   <p class="kicker">Contatti</p>
   <h2>Vuoi maggiori informazioni?</h2>
   <p>Scrivimi direttamente: ti rispondo volentieri e ti aiuto a scegliere. Per ordinare basta registrarsi, è gratuito e senza obbligo di acquisto.</p>
   <div class="cta-row">
    @if($wa)<a class="btn-p dark" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Ciao '.$name.', vorrei saperne di più su LAMÈSS.') }}" target="_blank" rel="noopener">Scrivimi su WhatsApp</a>@endif
    @if($d['email_public'])<a class="btn-p {{ $wa ? 'line' : 'dark' }}" style="{{ $wa ? 'color:#13110E;border-color:#BBB4AF' : '' }}" href="mailto:{{ $d['email_public'] }}">Scrivimi una email</a>@endif
    <a class="btn-p {{ $wa || $d['email_public'] ? 'gold' : 'dark' }}" href="{{ $refUrl }}">Registrati con me</a>
   </div>
   @if($socials !== [])
    <div class="socials" aria-label="Social">@foreach($socials as $k => $u)<a href="{{ $u }}" target="_blank" rel="noopener noreferrer nofollow">{{ \App\Domain\Pages\PersonalPageService::SOCIALS[$k] }}</a>@endforeach</div>
   @endif
  </div>
  <div class="qr">{!! \App\Domain\Content\ReferralQr::svg($c, 220) !!}Inquadra per registrarti</div>
 </div>
</section>

<section id="faq">
 <div class="wrap faq">
  <p class="kicker">FAQ</p>
  <h2>Domande frequenti</h2>
  @foreach($shared['faq'] as $f)<details><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
  <p class="disclaimer">{{ $shared['disclaimer'] }}</p>
 </div>
</section>
@endsection
