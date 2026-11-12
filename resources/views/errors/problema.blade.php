{{-- Pagina che spiega un errore del server (al posto del «500» generico): vedi App\Support\Errors\ErrorExplainer. --}}
<!doctype html>
<html lang="it">
<head>
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $problem['title'] }} — Lamèss</title>
<link rel="icon" href="/favicon.ico?v=2">
<link rel="stylesheet" href="/css/lamess-tokens.css">
<link rel="stylesheet" href="/css/lamess-site.css">
<style>
.lmerr{max-width:640px;margin:7vh auto 48px;padding:0 20px}
.lmerr .brand{justify-content:center;margin-bottom:22px}
.lmerr-kicker{text-align:center;font:600 12px/1 var(--lm-font-text);letter-spacing:.14em;text-transform:uppercase;color:var(--lm-terracotta)}
.lmerr h1{text-align:center;margin:10px 0 6px}
.lmerr-what{text-align:center;color:var(--muted);font-size:17px;margin:0 auto 22px;max-width:56ch}
.lmerr-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-top:14px}
.lmerr-card h2{font:600 13px/1.2 var(--lm-font-text);letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
.lmerr-card p{margin:0}.lmerr-card p+p{margin-top:8px}
.lmerr-card.main{border-left:4px solid var(--lm-terracotta)}
.lmerr-card.staff{background:#FBF6EF}
.lmerr-links{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;align-items:center;margin-top:20px}.lmerr-links .btn{margin-top:0}
.lmerr-ref{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.lmerr-ref code{font:600 15px ui-monospace,Menlo,Consolas,monospace;color:var(--lm-brown,#3a2f28);background:#F3ECE3;border-radius:6px;padding:3px 8px;user-select:all}
.lmerr-tech{font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;word-break:break-word;color:#4a3f38}
</style>
</head>
<body>
<main class="lmerr">
 <a class="brand" href="/">
  <img src="/img/lamess-logo.png" width="38" height="38" alt="">
  <b>Lamèss</b>
 </a>
 <div class="lmerr-kicker">Errore {{ $status }} · non è colpa tua</div>
 <h1>{{ $problem['title'] }}</h1>
 <p class="lmerr-what">{{ $problem['what'] }}</p>

 <div class="lmerr-card main">
  <h2>Che cosa è successo alla tua operazione</h2>
  <p>{{ $context['doing'] }}</p>
  <p><strong>{{ $context['effect'] }}</strong></p>
 </div>

 <div class="lmerr-card">
  <h2>Che cosa puoi fare</h2>
  <p>{{ $problem['retry'] }}</p>
 </div>

 @if ($staff)
 <div class="lmerr-card staff">
  <h2>Per chi gestisce il sito</h2>
  <p>{{ $problem['staff'] }}</p>
  @if ($technical)
  <p class="lmerr-tech">{{ $technical['class'] }}<br>{{ $technical['message'] }}<br>{{ $technical['where'] }}</p>
  @endif
  <p>Nel log del giorno (storage/logs) l'errore completo si trova cercando il codice qui sotto.</p>
 </div>
 @endif

 <div class="lmerr-links">
  @foreach ($context['links'] as $label => $href)
  <a class="btn{{ $loop->first ? '' : ' ghost' }}" href="{{ $href }}">{{ $label }}</a>
  @endforeach
  <a class="btn ghost" href="/">Pagina iniziale</a>
 </div>

 <p class="lmerr-ref">Se ci scrivi, indica questo codice: <code>{{ $ref }}</code><br>{{ $when }}</p>
</main>
</body></html>
