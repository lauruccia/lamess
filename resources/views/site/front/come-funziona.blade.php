@extends('site.front.layout')
@section('title', 'Come funziona — Lamèss')
@section('description', 'Come funziona il sistema Lamèss per gli incaricati: clienti, rete e qualifiche, spiegati in modo semplice, con una simulazione da provare.')
@push('head')
<link rel="stylesheet" href="/css/lamess-how.css?v={{ @filemtime(public_path('css/lamess-how.css')) ?: 1 }}">
@endpush
@php
    $tiers = $plan['tiers'];
    $levels = $plan['levels'];
    $ranks = $plan['ranks'];
    $minPct = $tiers[0]['pct'];
    $maxPct = end($tiers)['pct'];
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
    $regUrl = '/registrazione'.($ref !== '' ? '?ref='.urlencode($ref) : '');
    $simData = ['tiers' => $tiers, 'levels' => $levels, 'ranks' => $ranks, 'pearl' => $plan['pearlCode'], 'euro' => $plan['showEuro'], 'box' => $plan['clientMinValue'], 'vap' => $plan['vapShare']];
    $isGuest = ! auth()->check();
    $ctaUrl = $isGuest ? $regUrl : '/area/diventa-incaricato';
    $ctaLabel = $isGuest ? 'Registrati gratis' : 'Diventa incaricato';
    $clientText = $plan['clientRule'] === 'min_amount'
        ? 'Un cliente è chi fa un ordine di almeno '.$plan['clientMin'].' € (IVA inclusa).'
        : 'Un cliente è chi fa almeno un ordine.';
@endphp
@section('content')
<section class="dark pagehead"><div class="wrap">
  <p class="crumbs"><a href="/">Home</a> / <a href="/lavora-con-noi">Lavora con noi</a> / Come funziona</p>
  <h1>Come funziona, in due minuti.</h1>
  <p>Come si guadagna e si cresce con Lamèss, spiegato passo dopo passo. Prova la simulazione e guarda cosa trovi nell’area riservata.</p>
  @if($sponsor)<p class="how-from">Ti ha invitato <b>{{ $sponsor->name }}</b>: se ti registri dal suo link entri nella sua struttura.</p>@endif
</div></section>

{{-- 1. I cinque passi --}}
<section class="light band" id="passi"><div class="wrap">
  <h2 style="max-width:18ch">Cinque passi, nessun obbligo.</h2>
  <ol class="how-steps">
    <li><span class="n" aria-hidden="true">1</span><h3>Sei prima di tutto cliente</h3><p>Ti registri gratis e acquisti Lamèss quando vuoi. Nessun obbligo di acquisto, nessun kit da comprare.</p></li>
    <li><span class="n" aria-hidden="true">2</span><h3>Condividi il tuo link</h3><p>Ogni incaricato ha un link referral personale. Chi si registra da quel link entra nella sua struttura.</p></li>
    <li><span class="n" aria-hidden="true">3</span><h3>Guadagni sui tuoi clienti</h3><p>Una percentuale sul valore di ogni box acquistata dai tuoi clienti, dal {{ $fmt($minPct) }}% al {{ $fmt($maxPct) }}%: sale a scaglioni con il numero dei clienti.</p></li>
    <li><span class="n" aria-hidden="true">4</span><h3>Guadagni anche sulla rete</h3><p>Se persone che hai invitato diventano a loro volta incaricati, partecipi ai loro risultati fino a {{ count($levels) }} livelli, se hai i requisiti.</p></li>
    <li><span class="n" aria-hidden="true">5</span><h3>Cresci per qualifiche</h3><p>{{ count($ranks) }} qualifiche, da {{ $ranks[0]['name'] ?? 'Pearl' }} a {{ end($ranks)['name'] ?? 'Diamond' }}, ognuna con il colore della sua pietra. Le vedi crescere dalla tua area.</p></li>
  </ol>
  <p class="how-note">{{ $clientText }}</p>
</div></section>

{{-- 2. Prova tu --}}
<section class="dark band" id="prova"><div class="wrap">
  <h2 style="max-width:16ch">Prova tu.</h2>
  <p class="lead muted" style="margin-top:18px;max-width:52ch">Aggiungi clienti e incaricati e guarda come cambiano percentuale e qualifica. È un esempio: i numeri sono quelli del piano, il risultato reale dipende dalle vendite.</p>

  <div class="sim" id="sim" data-plan='@json($simData)'>
    <div class="sim-ctl">
      <div class="sim-row">
        <div><b>I tuoi clienti</b><span>Persone che acquistano dal tuo link</span></div>
        <div class="stp" role="group" aria-label="Numero dei tuoi clienti">
          <button type="button" data-me="-1" aria-label="Un cliente in meno">−</button>
          <output id="meN" aria-live="polite">0</output>
          <button type="button" data-me="1" aria-label="Un cliente in più">+</button>
        </div>
      </div>
      <input type="range" id="meRange" min="0" max="100" step="1" value="0" aria-label="Clienti, da 0 a 100">
      <div class="sim-row">
        <div><b>Incaricati nel tuo team</b><span>Persone che hai invitato e che sono diventate incaricati</span></div>
        <button type="button" class="btn sm" id="addMember">+ Aggiungi un incaricato</button>
      </div>
      <p class="sim-hint" id="teamHint">Nessun incaricato nel tuo team, per ora.</p>
      <button type="button" class="linkreset" id="simReset">Ricomincia da zero</button>
    </div>

    <div class="sim-out">
      <div class="sim-tree" id="tree" aria-label="La tua rete"></div>
      <dl class="sim-res" aria-live="polite">
        <div><dt>La tua qualifica</dt><dd id="outRank">—</dd></div>
        <div><dt>Percentuale sui tuoi clienti</dt><dd id="outPct">—</dd></div>
      </dl>
      <p class="sim-next" id="outNext"></p>
      <p class="sim-eur" id="outEur" hidden></p>
      <p class="sim-ind" id="outInd"></p>
    </div>
  </div>
  <p class="how-note" style="color:var(--taupe)">Nell’esempio le qualifiche si calcolano con clienti e incaricati diretti: dalla qualifica successiva servono anche altre strutture, che trovi nella tabella qui sotto.</p>

  <div class="tbls">
    <div>
      <h3>Scaglioni sui tuoi clienti</h3>
      <table class="how-tbl"><thead><tr><th scope="col">Clienti</th><th scope="col">Percentuale</th></tr></thead><tbody>
        @foreach($tiers as $i => $t)
        <tr><td>{{ $t['clients'] }}{{ isset($tiers[$i + 1]) ? '–'.($tiers[$i + 1]['clients'] - 1) : ' o più' }}</td><td>{{ $fmt($t['pct']) }}%</td></tr>
        @endforeach
      </tbody></table>
    </div>
    <div>
      <h3>I {{ count($levels) }} livelli della rete</h3>
      <table class="how-tbl"><thead><tr><th scope="col">Livello</th><th scope="col">%</th><th scope="col">Per ricevere servono</th></tr></thead><tbody>
        @foreach($levels as $l)
        <tr><td>{{ $l['level'] }}°</td><td>{{ $fmt($l['pct']) }}%</td><td>{{ $l['clients'] }} {{ $l['clients'] === 1 ? 'cliente' : 'clienti' }}@if($l['pearl'] > 0) e {{ $l['pearl'] }} {{ $l['pearl'] === 1 ? 'incaricato' : 'incaricati' }} {{ $ranks[0]['name'] ?? 'Pearl' }}@endif</td></tr>
        @endforeach
      </tbody></table>
    </div>
  </div>
</div></section>

{{-- 3. Le qualifiche --}}
<section class="white band" id="qualifiche"><div class="wrap">
  <h2 style="max-width:18ch">Le qualifiche, una per pietra.</h2>
  <ul class="rk">
    @foreach($ranks as $r)
    <li style="--c: {{ $r['color'] }}"><i aria-hidden="true"></i><b>{{ $r['name'] }}</b><span>{{ $r['text'] }}</span></li>
    @endforeach
  </ul>
</div></section>

{{-- 4. Anteprima area riservata --}}
<section class="light band" id="area"><div class="wrap">
  <h2 style="max-width:20ch">Cosa trovi nella tua area riservata.</h2>
  <p class="lead" style="margin-top:18px;max-width:54ch">Un’anteprima con dati di esempio: così vedi cosa significa far parte della rete.</p>
  <div class="tour" id="tour">
    <div class="tabs" role="tablist" aria-label="Anteprima dell’area riservata">
      <button role="tab" id="t1" aria-controls="p1" aria-selected="true">Qualifica</button>
      <button role="tab" id="t2" aria-controls="p2" aria-selected="false" tabindex="-1">Il mio ramo</button>
      <button role="tab" id="t3" aria-controls="p3" aria-selected="false" tabindex="-1">Statino</button>
      <button role="tab" id="t4" aria-controls="p4" aria-selected="false" tabindex="-1">Tasca</button>
    </div>
    <div class="mock">
      <span class="mock-tag">Dati di esempio</span>
      <div role="tabpanel" id="p1" aria-labelledby="t1">
        <p class="mk-k">La tua qualifica</p>
        <p class="mk-big"><i class="dot" style="--c:{{ $ranks[0]['color'] ?? '#C8B6A6' }}"></i> {{ $ranks[0]['name'] ?? 'Pearl' }}</p>
        <p class="mk-k" style="margin-top:22px">Verso {{ $ranks[1]['name'] ?? 'Crystal' }}</p>
        <div class="mk-bar"><label>Clienti personali <b>4 / {{ $ranks[1]['clients'] ?? 6 }}</b></label><span><i style="width:{{ min(100, round(4 / max(1, ($ranks[1]['clients'] ?? 6)) * 100)) }}%"></i></span></div>
        <div class="mk-bar"><label>Incaricati diretti almeno {{ $ranks[0]['name'] ?? 'Pearl' }} <b>1 / {{ $ranks[1]['pearl'] ?? 2 }}</b></label><span><i style="width:{{ min(100, round(1 / max(1, ($ranks[1]['pearl'] ?? 2)) * 100)) }}%"></i></span></div>
        <p class="mk-sub">Vedi sempre cosa hai raggiunto e cosa manca per la qualifica successiva.</p>
      </div>
      <div role="tabpanel" id="p2" aria-labelledby="t2" hidden>
        <p class="mk-k">Il mio ramo</p>
        <ul class="mk-tree">
          <li><i class="dot" style="--c:{{ $ranks[0]['color'] ?? '#C8B6A6' }}"></i><b>Tu</b> · {{ $ranks[0]['name'] ?? 'Pearl' }} · 4 clienti
            <ul>
              <li><i class="dot" style="--c:{{ $ranks[0]['color'] ?? '#C8B6A6' }}"></i><b>Incaricato A</b> · {{ $ranks[0]['name'] ?? 'Pearl' }} · 3 clienti
                <ul><li><i class="dot" style="--c:#BBB4AF"></i><b>Incaricato C</b> · 1 cliente</li></ul></li>
              <li><i class="dot" style="--c:#BBB4AF"></i><b>Incaricato B</b> · 2 clienti</li>
            </ul></li>
        </ul>
        <p class="mk-sub">L’albero della tua rete, con l’elenco filtrabile, per capire chi aiutare a crescere.</p>
      </div>
      <div role="tabpanel" id="p3" aria-labelledby="t3" hidden>
        <p class="mk-k">Statino mensile</p>
        <p class="mk-big">Totale del mese <b class="amt">{{ $plan['showEuro'] ? '€ 148,50' : '€ •••' }}</b></p>
        <table class="mk-tbl"><tbody>
          <tr><td>Compensi diretti</td><td class="amt">{{ $plan['showEuro'] ? '€ 90,00' : '€ •••' }}</td></tr>
          <tr><td>Compensi indiretti</td><td class="amt">{{ $plan['showEuro'] ? '€ 58,50' : '€ •••' }}</td></tr>
        </tbody></table>
        <p class="mk-sub">In alto il totale, sotto il dettaglio. I bonus settimanali hanno uno statino a parte.</p>
      </div>
      <div role="tabpanel" id="p4" aria-labelledby="t4" hidden>
        <p class="mk-k">La Tasca · i tuoi documenti</p>
        <ul class="mk-docs">
          <li>Statino mensile</li><li>Statino bonus settimanale</li><li>Ricevute e fatture</li>
        </ul>
        <button type="button" class="btn sm" disabled>Richiedi il prelievo</button>
        <p class="mk-sub">Tutti i tuoi compensi in un posto solo: quando vuoi incassare, richiedi il prelievo.</p>
      </div>
    </div>
  </div>
  <div class="feat" style="margin-top:48px">
    <div><h3>La tua pagina</h3><p>Una pagina pubblica tua, facile da modificare, con il tuo link referral personale.</p></div>
    <div><h3>Inviti</h3><p>L’elenco degli inviti spediti e se la persona si è già registrata dal tuo link.</p></div>
    <div><h3>Tesserino agente</h3><p>Il tesserino con QR code di verifica, per la vendita porta a porta.</p></div>
  </div>
</div></section>

{{-- 5. Cosa serve --}}
<section class="white band" id="iniziare"><div class="wrap">
  <h2 style="max-width:18ch">Per iniziare.</h2>
  <div class="feat" style="margin-top:40px">
    <div><h3>Nessun costo d’ingresso</h3><p>Registrarsi è gratis e non c’è un kit da acquistare né un acquisto minimo per restare incaricati.</p></div>
    <div><h3>Come si diventa incaricato</h3><p>Ti registri, acquisti la prima box, chiedi dal profilo di diventare incaricato e firmi la lettera d’incarico con un codice via email.</p></div>
    <div><h3>Canone annuale</h3><p>@if($plan['fee']) Il canone è di {{ $plan['fee']['amount'] }} € l’anno, IVA compresa{{ $plan['fee']['from_year'] > 1 ? ', dal '.$plan['fee']['from_year'].'° anno' : '' }}. @else Non è previsto alcun canone annuale. @endif</p></div>
  </div>
  <p class="how-legal">Percentuali ed esempi sono illustrativi e non garantiscono alcun guadagno: i compensi dipendono dalle vendite reali dei tuoi clienti e della tua rete. Il piano può essere modificato. Condizioni complete nel <a href="/piano-compensi">piano compensi</a> e nel <a href="/regolamento">regolamento</a>.</p>
</div></section>

<section class="dark band" style="padding-block:clamp(56px,7vw,90px)"><div class="wrap cta-band">
  <h2>{{ $isGuest ? 'Inizia da qui, è gratis.' : 'Vuoi diventare incaricato?' }}</h2>
  <a class="btn solid" href="{{ $ctaUrl }}">{{ $ctaLabel }}</a>
</div></section>
@endsection
@push('scripts')
<script src="/js/lamess-how.js?v={{ @filemtime(public_path('js/lamess-how.js')) ?: 1 }}"></script>
@endpush
