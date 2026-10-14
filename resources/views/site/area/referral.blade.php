@extends('site.layout')
@section('title','Il mio referral — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Il mio referral</h1><p class="sub">Chi apre questo link o inquadra il QR e si registra viene assegnato a te.</p></div></div>
<div class="split even">
 <section class="panel accent">
  <header><h2>Il tuo link</h2><span class="st st-ok">Codice {{ $code }}</span></header>
  <div class="panel-body">
   <div class="copy"><input type="text" id="ref-url" readonly value="{{ $pretty }}" data-link="{{ $url }}" aria-label="Link referral"><button type="button" class="btn" id="ref-copy">Copia</button></div>
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
@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
@if(session('invite_wa'))<div class="note note-ok"><div><strong>Invito pronto per WhatsApp</strong>Il messaggio contiene già il link personale.</div><a class="btn accent" href="{{ session('invite_wa') }}" target="_blank" rel="noopener">Apri WhatsApp</a></div>@endif
@if(session('invite_link'))<div class="note"><div><strong>Link personale dell'invito</strong><span class="muted" style="word-break:break-all">{{ session('invite_link') }}</span></div><button type="button" class="btn ghost" data-copy="{{ session('invite_link') }}">Copia</button></div>@endif
<div class="pg-head" style="margin-top:28px"><div><h2 style="margin:0">I miei inviti</h2><p class="sub">Invita una persona con un link personale: vedi chi l'ha ricevuto, chi l'ha aperto e chi si è registrato.</p></div></div>
<div class="kpis">
 <div class="kpi"><small>Inviti inviati</small><strong>{{ $stats['sent'] }}</strong><span>{{ $stats['total'] }} creati in tutto</span></div>
 <div class="kpi"><small>Link aperti</small><strong>{{ $stats['opened'] }}</strong><span>{{ $stats['pending'] }} in attesa di registrazione</span></div>
 <div class="kpi k-ok"><small>Registrati</small><strong>{{ $stats['registered'] }}</strong><span>{{ $stats['ordered'] }} hanno già ordinato</span></div>
 <div class="kpi"><small>Non registrati</small><strong>{{ $stats['expired'] }}</strong><span>Inviti scaduti dopo {{ (int) config('lamess.referral_cookie_days') }} giorni</span></div>
</div>
<p class="muted" style="margin:-6px 0 18px;font-size:13.5px">Registrati dal tuo link generico o dal QR, senza un invito personale: <strong>{{ $stats['direct'] }}</strong>. Li trovi in <a href="/area/clienti">I miei clienti</a>.</p>
<div class="split">
 <section class="panel">
  <header><h2>Elenco inviti</h2>
   <span class="pills">@foreach(['tutti' => 'Tutti', 'attesa' => 'In attesa', 'registrati' => 'Registrati', 'scaduti' => 'Non registrati'] as $k => $l)<a class="st {{ $filter === $k ? 'st-ok' : '' }}" href="/area/referral{{ $k === 'tutti' ? '' : '?stato='.$k }}">{{ $l }}</a>@endforeach</span>
  </header>
  @if($rows->isEmpty())
   <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'users'])</span><div><strong>Nessun invito{{ $filter === 'tutti' ? '' : ' in questo elenco' }}</strong><p>Crea il primo invito dal modulo: la persona riceve un link personale e qui vedrai a che punto è.</p></div></div>
  @else
   <div class="tbl-wrap"><table class="tbl cards">
    <thead><tr><th>Persona</th><th>Canale</th><th>Inviato il</th><th>Stato</th><th>Registrata il</th><th>Primo ordine</th><th></th></tr></thead>
    <tbody>
    @foreach($rows as $r)
     <tr>
      <td data-l="Persona"><b>{{ $r->recipientLabel() }}</b>@if($r->recipient_name && ($r->email || $r->phone))<br><span class="muted">{{ $r->email ?: $r->phone }}</span>@endif</td>
      <td data-l="Canale">{{ \App\Models\ReferralInvite::CHANNELS[$r->channel] ?? $r->channel }}</td>
      <td data-l="Inviato il">{{ $r->sent_at ? \App\Support\Ui::date($r->sent_at) : '—' }}@if($r->reminders_count)<br><span class="muted">{{ $r->reminders_count }} promemoria</span>@endif</td>
      <td data-l="Stato"><span class="st st-{{ $r->displayTone() }}">{{ $r->displayLabel() }}</span>@if($r->status === 'opened' && $r->opened_at)<br><span class="muted">aperto il {{ \App\Support\Ui::date($r->opened_at) }}</span>@elseif(in_array($r->status, ['created', 'sent'], true) && $r->expires_at)<br><span class="muted">scade il {{ \App\Support\Ui::date($r->expires_at) }}</span>@endif @if($r->failure_reason)<br><span class="muted">{{ $r->failure_reason }}</span>@endif</td>
      <td data-l="Registrata il">{{ $r->registered_at ? \App\Support\Ui::date($r->registered_at) : '—' }}</td>
      <td data-l="Primo ordine">{{ $r->first_paid_at ? \App\Support\Ui::date($r->first_paid_at) : '—' }}</td>
      <td class="end" style="white-space:nowrap">
       @if($r->status !== 'registered')
        <button type="button" class="btn ghost" style="padding:6px 12px;font-size:13px" data-copy="{{ $invites->url($r) }}">Copia link</button>
        @if($r->channel === 'whatsapp')<a class="btn ghost" style="padding:6px 12px;font-size:13px" href="{{ $invites->whatsappUrl($r) }}" target="_blank" rel="noopener">WhatsApp</a>@endif
        @if($r->reminders_count < \App\Domain\Customers\ReferralInviteService::MAX_REMINDERS)
         <form method="post" action="/area/referral/inviti/{{ $r->id }}/ricorda" style="display:inline">@csrf<button class="btn ghost" style="padding:6px 12px;font-size:13px">{{ in_array($r->status, ['expired', 'failed'], true) ? 'Reinvia' : 'Ricorda' }}</button></form>
        @endif
       @endif
      </td>
     </tr>
    @endforeach
    </tbody>
   </table></div>
  @endif
 </section>
 <aside class="stack">
  <form method="post" action="/area/referral/inviti" class="panel accent" id="invite-form">@csrf
   <header><h2>Nuovo invito</h2></header>
   <div class="panel-body">
    <label for="i-ch">Come inviti la persona</label>
    <select id="i-ch" name="channel">@foreach(['email' => 'Email (la invia il portale)', 'whatsapp' => 'WhatsApp (la mandi tu)', 'link' => 'Solo link (lo copi e lo mandi tu)'] as $k => $l)<option value="{{ $k }}" @selected(old('channel', 'email') === $k)>{{ $l }}</option>@endforeach</select>
    <label for="i-nm">Nome (facoltativo)</label><input id="i-nm" type="text" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="120" autocomplete="off">
    <div data-ch="email"><label for="i-em">Email</label><input id="i-em" type="email" name="email" value="{{ old('email') }}" autocapitalize="none" autocomplete="off"></div>
    <div data-ch="whatsapp"><label for="i-ph">Telefono</label><input id="i-ph" type="tel" name="phone" value="{{ old('phone') }}" placeholder="+39 333 1234567" autocomplete="off"></div>
    <p class="muted" style="font-size:13px;margin:10px 0 0">Il link è personale: quando la persona si registra, l'invito passa a «Registrato» e lei diventa tuo cliente.</p>
    <div class="form-actions"><button>Crea invito</button></div>
   </div>
  </form>
 </aside>
</div>
<script>
(function () {
  var b = document.getElementById('ref-copy'), i = document.getElementById('ref-url');
  if (!b || !i) { return; }
  b.addEventListener('click', function () {
    var done = function () { b.textContent = 'Copiato'; setTimeout(function () { b.textContent = 'Copia'; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(i.getAttribute('data-link') || i.value).then(done, function () { i.select(); }); }
    else { i.select(); try { document.execCommand('copy'); done(); } catch (e) {} }
  });
})();
(function () {
  var sel = document.getElementById('i-ch');
  var sync = function () { document.querySelectorAll('#invite-form [data-ch]').forEach(function (d) { d.style.display = (sel && d.getAttribute('data-ch') === sel.value) ? '' : 'none'; }); };
  if (sel) { sel.addEventListener('change', sync); sync(); }
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy'), label = btn.textContent;
      var done = function () { btn.textContent = 'Copiato'; setTimeout(function () { btn.textContent = label; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copia il link', text); }); }
      else { window.prompt('Copia il link', text); }
    });
  });
})();
</script>
@endsection
