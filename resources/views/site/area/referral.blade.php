@extends('site.layout')
@section('title','Il mio referral — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Il mio referral</h1><p class="sub">Chi apre questo link o inquadra il QR e si registra viene assegnato a te.</p></div></div>
<div class="split even">
 <section class="panel accent">
  <header><h2>Il tuo link</h2><span class="st st-ok">Codice {{ $code }}</span></header>
  <div class="panel-body">
   <div class="copy"><input type="text" id="ref-url" readonly value="{{ $url }}" aria-label="Link referral"><button type="button" class="btn" id="ref-copy">Copia</button></div>
   <div class="pg-actions" style="margin-top:14px">
    <a class="btn ghost" href="https://wa.me/?text={{ rawurlencode('Registrati su Lamèss da qui: '.$url) }}" target="_blank" rel="noopener">Condividi su WhatsApp</a>
    <a class="btn ghost" href="mailto:?subject={{ rawurlencode('Il tuo account Lamèss') }}&body={{ rawurlencode('Registrati da questo link: '.$url) }}">Invia per email</a>
   </div>
   <h3 style="margin:22px 0 8px">Come funziona</h3>
   <ul class="todo" style="margin:0;padding-left:18px;line-height:1.7">
    <li>La persona apre il link e si registra: diventa tuo cliente per 15 giorni dal primo click.</li>
    <li>I suoi ordini compaiono in «I miei clienti».</li>
    <li>Se in seguito diventa incaricato, entra nella tua rete.</li>
   </ul>
  </div>
 </section>
 <section class="panel">
  <header><h2>QR code</h2></header>
  <div class="panel-body" style="text-align:center">
   <div style="max-width:260px;margin:0 auto">{!! $svg !!}</div>
   <p class="muted" style="margin:12px 0 14px;font-size:13px">Stampalo o mostralo dal telefono: si apre direttamente la registrazione.</p>
   <a class="btn accent" href="/area/referral/qr.svg?scarica=1">Scarica il QR (SVG)</a>
  </div>
 </section>
</div>
<script>
(function () {
  var b = document.getElementById('ref-copy'), i = document.getElementById('ref-url');
  if (!b || !i) { return; }
  b.addEventListener('click', function () {
    var done = function () { b.textContent = 'Copiato'; setTimeout(function () { b.textContent = 'Copia'; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(i.value).then(done, function () { i.select(); }); }
    else { i.select(); try { document.execCommand('copy'); done(); } catch (e) {} }
  });
})();
</script>
@endsection
