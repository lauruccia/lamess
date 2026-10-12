<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Lamèss apre presto</title>
<link rel="icon" href="/favicon.ico?v=2">
<link rel="stylesheet" href="/css/lamess-tokens.css">
<link rel="stylesheet" href="/css/lamess-site.css">
<style>.err-box{max-width:520px;margin:14vh auto 0;text-align:center;padding:0 20px}.err-box h1{margin-top:18px}</style>
</head>
<body>
<main>
 <div class="err-box">
  <span class="brand" style="justify-content:center;margin-bottom:28px">
   <img src="/img/lamess-logo.png" width="38" height="38" alt="">
   <b>Lamèss</b>
  </span>
  <h1>Lamèss apre presto</h1>
  <p class="lead" style="margin:0 auto">Stiamo ultimando gli ultimi dettagli del portale. Torna a trovarci tra poco.</p>
  @if(config('lamess.company.email'))
   <p style="margin-top:22px">Per informazioni: <a href="mailto:{{ config('lamess.company.email') }}">{{ config('lamess.company.email') }}</a></p>
  @endif
 </div>
</main>
</body></html>
