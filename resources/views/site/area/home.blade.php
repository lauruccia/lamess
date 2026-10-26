@extends('site.layout')
@section('title','La mia area — Lamèss')
@section('width','area')
@section('content')
@php
    $c = $user->consultant && $user->consultant->status !== 'terminated' ? $user->consultant : null;
    $d = app(\App\Domain\Customers\AreaDashboard::class)->for($user);
    $x = $c ? app(\App\Domain\Customers\AreaDashboard::class)->forConsultant($c->id) : null;
    $n = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $first = $user->profile?->type === 'company' ? $user->profile->company_name : ($user->profile?->first_name ?: $user->name);
    $lastOrder = $d['orders']['recent']->first();
    $kk = $progress['kosmos'] ?? null;
    $nt = $kk ? collect($kk['tiers'])->firstWhere('reached', false) : null;
    $walletOn = $c && $c->status === 'active' && \App\Domain\Wallet\WithdrawalService::enabled();
@endphp

<div class="pg-head">
 <div>
  <h1>Ciao, {{ $first }}</h1>
  <p class="sub">
   @if($c)
    <span class="st st-{{ $c->status === 'suspended' ? 'warn' : 'ok' }}">Incaricato {{ $c->status === 'suspended' ? 'sospeso' : 'attivo' }}</span>
    &nbsp;Codice attività <strong>{{ $c->code }}</strong>
   @else
    Ecco la situazione del tuo account: ordini, abbonamenti e novità in un colpo d'occhio.
   @endif
  </p>
 </div>
 <div class="pg-actions">
  <a class="btn accent" href="/negozio">Vai al negozio</a>
  <a class="btn ghost" href="/area/assistenza">Serve aiuto?</a>
 </div>
</div>

{{-- Cose da fare, in ordine di urgenza --}}
@if(! empty($letterToSign))
 <div class="note"><div><strong>Lettera di Incarico da firmare</strong>@if(filled($letterToSign->version->change_summary)){{ \Illuminate\Support\Str::limit($letterToSign->version->change_summary, 160) }}@else Il Consorzio ti chiede di firmare la versione {{ $letterToSign->version->version }} della lettera.@endif</div><a class="btn" href="/area/lettera">Leggi e firma</a></div>
@endif
@if($c && $contract?->status === 'signed')
 <div class="note note-info"><div><strong>Domanda firmata</strong>È in attesa di accettazione da parte del Consorzio: ti avvisiamo appena è pronta.</div></div>
@endif
@if($c && ! $c->onboarding_complete)
 <div class="note"><div><strong>Onboarding da completare</strong>Senza onboarding non puoi ricevere pagamenti né commissioni indirette.</div><a class="btn" href="/area/onboarding">Completa ora</a></div>
@endif
@foreach($d['orders']['pending'] as $o)
 <div class="note"><div><strong>Ordine {{ $o->number }} in attesa di pagamento</strong>€ {{ $eur($o->total) }} · {{ $o->payment_method === 'transfer' ? 'ti serve il bonifico: nell\'ordine trovi IBAN e causale.' : ($o->payment_method === 'kmoney' ? 'completa il pagamento con KMoney.' : 'completa il pagamento con carta.') }}</div><a class="btn" href="/area/ordini/{{ $o->id }}">{{ $o->payment_method === 'transfer' ? 'Vedi istruzioni' : 'Paga ora' }}</a></div>
@endforeach
@if($d['subs']['failed'])
 <div class="note note-err"><div><strong>Ultimo addebito dell'abbonamento non riuscito</strong>{{ $d['subs']['failed']->last_error }}</div><a class="btn" href="/area/abbonamenti">Controlla</a></div>
@endif
@if($d['tickets']['answered'] > 0)
 <div class="note note-ok"><div><strong>{{ $d['tickets']['answered'] === 1 ? 'Hai una risposta' : 'Hai '.$d['tickets']['answered'].' risposte' }} dall'assistenza</strong>Aprila per leggerla o per chiudere la richiesta.</div><a class="btn" href="/area/assistenza">Leggi</a></div>
@endif

{{-- Numeri --}}
<div class="kpis{{ $c ? ' kpis-row' : '' }}">
 @if($c && ($month ?? null))
  @if(\App\Domain\Plan\LegacyPlan::visible() && ! $kk)
  <div class="kpi"><small>PVF di {{ $month['period'] }}</small><strong>{{ $n($month['pvf']) }}</strong><span>Personali {{ $n($month['pvc']) }} · gruppo {{ $n($month['group_pv']) }}</span></div>
  <div class="kpi"><small>PR del mese</small><strong>{{ $n($month['pr']) }}</strong><span>Gruppo {{ $n($month['group_pr']) }} PR</span></div>
  @endif
  <div class="kpi {{ $month['active'] ? 'k-ok' : 'k-warn' }}"><small>Qualifica</small><strong>{{ $month['rank'] ? (strtolower($month['rank']) === 'consultant' ? 'Incaricato' : ucfirst(strtolower($month['rank']))) : '—' }}</strong>@if($kk)<span>{{ $kk['known'] ? 'Compenso diretto '.$n($progress['rank']['direct_pct']).'%' : 'Calcolo in arrivo' }}@if($kk['next_name']) · prossima {{ $kk['next_name'] }} al {{ $kk['percent'] }}%@endif</span>@elseif(\App\Domain\Plan\LegacyPlan::visible())<span>{{ $month['active'] ? 'Attivo questo mese' : 'Non ancora attivo questo mese' }}</span>@endif</div>
 @endif
 @if($c && $kk)
  <a class="kpi" href="/area/clienti"><small>Clienti personali</small><strong>{{ $kk['known'] ? $kk['clients'] : '—' }}</strong><span>@if($kk['known'] && $nt)Ne mancano {{ $nt['missing'] }} per il {{ $n($nt['pct']) }}% diretto @elseif($kk['known'])Scaglione massimo raggiunto @else In attesa del calcolo @endif</span></a>
  <a class="kpi" href="/area/rete"><small>Diretti Pearl</small><strong>{{ $kk['pearl'] ?? '—' }}</strong><span>Incaricati del tuo 1° livello con qualifica Pearl o superiore</span></a>
  @if($earn)
   <a class="kpi" href="/area/statini"><small>Compensi {{ $earn['period'] }} (stima)</small><strong>€ {{ $eur($earn['accrued']) }}</strong><span>@if($earn['held'] != 0)€ {{ $eur($earn['held']) }} trattenuti · @endif{{ $earn['closed'] ? 'Mese chiuso' : 'Mese in corso: non definitivo' }}</span></a>
   @if($earn['structure']['count'] > 0)
    <a class="kpi" href="/area/statini"><small>Bonus di Struttura</small><strong>€ {{ $eur($earn['structure']['calculated'] + $earn['structure']['confirmed'] + $earn['structure']['paid']) }}</strong><span>Pagati € {{ $eur($earn['structure']['paid']) }} · confermati € {{ $eur($earn['structure']['confirmed']) }} · in calcolo € {{ $eur($earn['structure']['calculated']) }}</span></a>
   @endif
  @endif
 @endif
 @if($c)
  <a class="kpi" href="/area/rete"><small>La mia rete</small><strong>{{ $x['network'] }}</strong><span>{{ $x['direct'] }} {{ $x['direct'] === 1 ? 'diretta' : 'dirette' }} · {{ $x['clients'] }} {{ $x['clients'] === 1 ? 'cliente' : 'clienti' }}</span></a>
  <a class="kpi" href="/area/statini"><small>Ultimo statino</small><strong>{{ $x['settlement'] ? '€ '.$eur($x['settlement']->accrued) : '—' }}</strong><span>{{ $x['settlement'] ? 'Mese '.$x['settlement']->period->code.' · '.($x['settlement']->status === 'paid' ? 'pagato' : 'da pagare o cumulato') : 'Compare alla prima chiusura mese' }}</span></a>
 @endif
 <a class="kpi" href="/area/ordini"><small>Ordini</small><strong>{{ $d['orders']['count'] }}</strong><span>{{ $lastOrder ? 'Ultimo il '.\App\Support\Ui::date($lastOrder->created_at) : 'Nessun ordine ancora' }}</span></a>
 @unless($c)
  <a class="kpi" href="/area/ordini"><small>Totale acquistato</small><strong>€ {{ $eur($d['orders']['spent']) }}</strong><span>Ordini pagati, IVA inclusa</span></a>
 @endunless
 @unless($c)
 <a class="kpi {{ $d['subs']['failed'] ? 'k-warn' : '' }}" href="/area/abbonamenti"><small>Abbonamenti</small><strong>{{ $d['subs']['active'] }}</strong><span>{{ $d['subs']['next'] ? 'Prossimo addebito '.\App\Support\Ui::date($d['subs']['next'], 'd/m').' ('.\App\Support\Ui::inDays($d['subs']['next']).')' : 'Nessuno attivo' }}</span></a>
 @endunless
 @unless($c)
  <a class="kpi" href="/area/assistenza"><small>Assistenza</small><strong>{{ $d['tickets']['open'] + $d['tickets']['answered'] }}</strong><span>{{ ($d['tickets']['open'] + $d['tickets']['answered']) ? 'richieste aperte' : 'Nessuna richiesta aperta' }}</span></a>
 @endunless
</div>

<div class="split">
 <div class="stack">
  @if($c && $c->status === 'active')
   <section class="panel">
    <header><h2>Il mio avanzamento</h2><a href="/area/rete">Il mio ramo →</a></header>
    <div class="panel-body">@include('partials.career-progress', ['p' => $progress])</div>
   </section>
  @endif

  <section class="panel">
   <header><h2>Ultimi ordini</h2><a href="/area/ordini">Tutti gli ordini →</a></header>
   @if($d['orders']['recent']->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'bag'])</span><div><strong>Il tuo primo ordine è a un click</strong><p>Scegli i prodotti nel negozio: qui ritrovi stato, fatture e spedizioni.</p><a class="btn accent" href="/negozio">Vai al negozio</a></div></div>
   @else
    <div class="list">
     @foreach($d['orders']['recent'] as $o)
      @php
[$lab, $tone] = \App\Support\Ui::orderStatus($o->status);
@endphp
      <a class="item" href="/area/ordini/{{ $o->id }}">
       <span class="ico">@include('site.area._i', ['n' => 'bag'])</span>
       <span class="main"><span class="t nw">{{ $o->number }}</span><span class="m">{{ \App\Support\Ui::date($o->created_at) }}</span></span>
       <span class="end"><b>€ {{ $eur($o->total) }}</b><span class="st st-{{ $tone }}">{{ $lab }}</span></span>
      </a>
     @endforeach
    </div>
   @endif
  </section>

  <section class="panel">
   <header><h2>Abbonamenti</h2><a href="/area/abbonamenti">Gestisci →</a></header>
   @if($d['subs']['list']->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'repeat'])</span><div><strong>Ricevi i tuoi prodotti ogni mese</strong><p>@if(\App\Models\Subscription::isOffered())Con l'abbonamento riordini in automatico senza pagare la spedizione e puoi annullare quando vuoi. Lo attivi dal checkout pagando con carta.@else Ordina quando vuoi dal negozio: qui trovi i tuoi abbonamenti, quando ne avrai uno.@endif</p><a class="btn ghost" href="/negozio">Scopri il negozio</a></div></div>
   @else
    <div class="list">
     @foreach($d['subs']['list'] as $s)
      @php
[$lab, $tone] = \App\Support\Ui::subscriptionStatus($s->status);
@endphp
      <a class="item" href="/area/abbonamenti">
       <span class="ico">@include('site.area._i', ['n' => 'repeat'])</span>
       <span class="main"><span class="t">{{ collect($s->lines)->sum() }} {{ collect($s->lines)->sum() === 1 ? 'prodotto' : 'prodotti' }} {{ $s->cadenceLabel() }}</span><span class="m">{{ $s->status === 'active' && $s->next_run_at ? 'Prossimo addebito '.\App\Support\Ui::date($s->next_run_at).' · '.\App\Support\Ui::inDays($s->next_run_at) : 'Cicli completati: '.$s->cycles_done }}</span></span>
       <span class="end"><span class="st st-{{ $tone }}">{{ $lab }}</span></span>
      </a>
     @endforeach
    </div>
   @endif
  </section>

  <section class="panel">
   <header><h2>Prossimi eventi</h2><a href="/area/eventi">Calendario →</a></header>
   @if($d['events']->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'calendar'])</span><div><strong>Nessun evento in programma</strong><p>Quando il Consorzio pubblica corsi e webinar li trovi qui, con iscrizione in un click.</p></div></div>
   @else
    <div class="list">
     @foreach($d['events'] as $row)
      @php
          $e = $row['event'];
          [$day, $mon] = \App\Support\Ui::chip($e->starts_at);
      @endphp
      <a class="item" href="/area/eventi">
       <span class="chip-date"><b>{{ $day }}</b><small>{{ $mon }}</small></span>
       <span class="main"><span class="t">{{ $e->title }}</span><span class="m">{{ \App\Models\CorporateEvent::KINDS[$e->kind] ?? $e->kind }} · {{ \App\Support\Ui::date($e->starts_at, 'H:i') }}@if($e->location) · {{ $e->location }}@elseif($e->online_url) · online @endif</span></span>
       <span class="end">@if($row['mine'])<span class=\"st st-{{ $row['mine']->status === 'waitlist' ? 'warn' : 'ok' }}\">{{ $row['mine']->status === 'waitlist' ? 'In lista d\'attesa' : 'Iscritto' }}</span>@else<span class="st st-info">Iscriviti</span>@endif</span>
      </a>
     @endforeach
    </div>
   @endif
  </section>
 </div>

 <aside class="stack">
  @if($c)
   <section class="panel accent">
    <header><h2>Il tuo link referral</h2></header>
    <div class="panel-body">
     <div class="copy"><input type="text" id="ref-url" readonly value="{{ \App\Domain\Content\ReferralQr::pretty($c) }}" data-link="{{ \App\Domain\Content\ReferralQr::url($c) }}" aria-label="Link referral"><button type="button" class="btn" id="ref-copy">Copia</button></div>
     <p class="muted" style="margin:10px 0 12px">Chi si registra da questo link diventa tuo cliente.</p>
     <div class="pg-actions"><a class="btn ghost" href="/area/referral">QR code</a><a class="btn ghost" href="/area/clienti">I miei clienti</a></div>
    </div>
   </section>
   @if(! empty($grants) && $grants['count'] > 0)
   <section class="panel">
    <header><h2>Punti assegnati</h2></header>
    <div class="panel-body">
     <p class="muted" style="margin:0 0 10px">Punti riconosciuti dall'azienda: <b>{{ $grants['total'] }}</b> in totale.</p>
     <dl class="dl">
      @foreach($grants['rows'] as $r)
       <dt>{{ $r['date'] }}</dt><dd>@if($r['cancelled'])<s>@endif<b>{{ $r['points'] }}</b> · {{ $r['reason'] }}@if($r['cancelled'])</s> (annullata)@endif</dd>
      @endforeach
     </dl>
    </div>
   </section>
   @endif
   <section class="panel">
    <header><h2>Compensi e documenti</h2></header>
    <div class="list">
     <a class="item" href="/area/statini"><span class="ico">@include('site.area._i', ['n' => 'euro'])</span><span class="main"><span class="t">I miei statini</span><span class="m">Importi e motivi di ogni riga</span></span></a>
     @if($walletOn)<a class="item" href="/area/wallet"><span class="ico">@include('site.area._i', ['n' => 'wallet'])</span><span class="main"><span class="t">Tasca</span><span class="m">I tuoi documenti, saldo e prelievi</span></span></a>@endif
     <a class="item" href="/area/rete"><span class="ico">@include('site.area._i', ['n' => 'tree'])</span><span class="main"><span class="t">Il mio ramo</span><span class="m">{{ $x['network'] }} persone nella tua rete</span></span></a>
     <a class="item" href="/area/materiali"><span class="ico">@include('site.area._i', ['n' => 'download'])</span><span class="main"><span class="t">Materiali</span><span class="m">Documenti e presentazioni da scaricare</span></span></a>
     @if($contract?->pdf_path)<a class="item" href="/area/file/lettera"><span class="ico">@include('site.area._i', ['n' => 'doc'])</span><span class="main"><span class="t">Lettera d'incarico</span><span class="m">PDF firmato{{ $contract->expires_at ? ' · in vigore fino al '.\App\Support\Ui::date($contract->expires_at) : '' }}</span></span></a>@endif
     @if($contract?->signed_at && $contract->signature_provider !== 'odoo')<a class="item" href="/area/file/certificato"><span class="ico">@include('site.area._i', ['n' => 'doc'])</span><span class="main"><span class="t">Certificato di firma</span><span class="m">PDF con le prove della firma{{ $contract->signed_at ? ' · firmata il '.\App\Support\Ui::date($contract->signed_at) : '' }}</span></span></a>@endif
     @if($c->status === 'active')<a class="item" href="/area/file/tesserino" target="_blank" rel="noopener"><span class="ico">@include('site.area._i', ['n' => 'user'])</span><span class="main"><span class="t">Tesserino</span><span class="m">PDF da stampare</span></span></a>@endif
    </div>
   </section>
  @else
   @if($user->customer?->consultant)
    @php
        $cc = $user->customer->consultant;
    @endphp
    <section class="panel">
     <header><h2>Il tuo incaricato</h2></header>
     <div class="panel-body who"><span class="avatar">{{ \App\Support\Ui::initials($cc->name) }}</span><div><b>{{ $cc->name }}</b><span>Codice {{ $cc->code }}</span></div></div>
    </section>
   @endif
  @endif

  <section class="panel">
   <header><h2>Il tuo account</h2><a href="/area/profilo">Modifica →</a></header>
   <div class="panel-body">
    <dl class="dl">
     <dt>Nome</dt><dd>{{ $user->profile?->fullName() ?: $user->name }}</dd>
     <dt>Email</dt><dd>{{ $user->email }}</dd>
     @if($user->profile?->phone)<dt>Telefono</dt><dd>{{ $user->profile->phone }}</dd>@endif
     @if($user->profile?->city)<dt>Città</dt><dd>{{ $user->profile->city }}@if($user->profile->province) ({{ $user->profile->province }})@endif</dd>@endif
    </dl>
    <div style="margin-top:14px">
     <div class="meter {{ $d['profile']['percent'] === 100 ? 'ok' : '' }}" role="progressbar" aria-valuenow="{{ $d['profile']['percent'] }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $d['profile']['percent'] }}%"></i></div>
     <p class="muted" style="margin:7px 0 0;font-size:13px">@if($d['profile']['missing'])Profilo completo al {{ $d['profile']['percent'] }}%: mancano {{ implode(', ', $d['profile']['missing']) }}. Servono per fatture e spedizioni.@else Profilo completo: fatture e spedizioni sono pronte.@endif</p>
    </div>
   </div>
  </section>

  <section class="panel">
   <header><h2>Novità</h2><a href="/area/novita">Tutte →</a></header>
   @if($d['news']->isEmpty())
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'news'])</span><div><p>Nessuna novità per ora. Le comunicazioni del Consorzio compariranno qui.</p></div></div>
   @else
    <div class="list">
     @foreach($d['news'] as $p)
      <a class="item" href="/area/novita/{{ $p->slug }}"><span class="main"><span class="t">{{ $p->title }}</span><span class="m">{{ \App\Support\Ui::date($p->published_at) }}@if($p->pinned) · in evidenza @endif</span></span></a>
     @endforeach
    </div>
   @endif
  </section>

  @if(! $c && $user->profile?->type === 'private')
   <section class="panel accent">
    <header><h2>Diventa Incaricato Lamèss</h2></header>
    <div class="panel-body">
     <p class="muted" style="margin-bottom:12px">Invia la richiesta: dopo la firma della lettera d'incarico completi l'onboarding e inizi a costruire la tua rete.</p>
     <a class="btn accent" href="/area/diventa-incaricato">Invia la richiesta</a>
    </div>
   </section>
  @endif
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
</script>
@endsection
