<!doctype html>
<html lang="it">
<head>
<meta name="color-scheme" content="light">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title') — Lamèss</title>
<link rel="icon" href="/favicon.ico?v=2">
<link rel="stylesheet" href="/css/lamess-tokens.css">
<link rel="stylesheet" href="/css/lamess-site.css">
<style>.err-box{max-width:520px;margin:12vh auto 0;text-align:center;padding:0 20px}.err-box .code{font:400 84px/1 var(--lm-font-display);color:var(--lm-terracotta);letter-spacing:.04em}.err-box h1{margin-top:10px}</style>
</head>
<body>
<main>
 <div class="err-box">
  <a class="brand" href="/" style="justify-content:center;margin-bottom:28px">
   <img src="/img/lamess-logo.png" width="38" height="38" alt="">
   <b>Lamèss</b>
  </a>
  <div class="code">@yield('code')</div>
  <h1>@yield('heading')</h1>
  <p class="lead" style="margin:0 auto">@yield('message')</p>
  <p><a class="btn" href="/">Torna alla pagina iniziale</a> @hasSection('extra')@yield('extra')@endif</p>
 </div>
</main>
</body></html>
