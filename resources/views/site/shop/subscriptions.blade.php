@extends('site.layout')
@section('title', __('I miei abbonamenti — Lamèss'))
@section('width','area')
@section('content')
@php
    $active = $subs->where('status', 'active');
    $rd = array_values(array_filter(array_map('intval', (array) config('lamess.subscription.retry_days')), fn ($d) => $d > 0)) ?: [1];
    $retryText = (count($rd) > 1 ? implode(', ', array_slice($rd, 0, -1)).' e '.end($rd) : $rd[0]).' '.(end($rd) === 1 ? 'giorno' : 'giorni');
    $next = $active->pluck('next_run_at')->filter()->sort()->first();
    $live = $subs->where('status', '!=', 'cancelled');
    $old = $subs->where('status', 'cancelled');
    // Nome leggibile: toglie il prefisso numerico di catalogo ("1 - ") e il MAIUSCOLO urlato
    $nice = fn ($pid) => \Illuminate\Support\Str::title(mb_strtolower(trim(preg_replace('/^\s*\d+\s*[-–]\s*/u', '', (string) ($products[$pid] ?? 'Prodotto')))));
@endphp
<div class="pg-head">
 <div>
  <h1>{{ __('I miei abbonamenti') }}</h1>
  <p class="sub">{{ __('Riordino automatico dei tuoi prodotti. Puoi metterlo in pausa o annullarlo quando vuoi.') }}</p>
 </div>
 <div class="pg-actions"><a class="btn accent" href="/negozio">{{ __('Scegli i prodotti') }}</a></div>
</div>

@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif
@if(request('carta') === 'ok')<div class="note note-ok"><div>{{ __('Stiamo registrando la nuova carta: entro pochi istanti sarà attiva per i prossimi addebiti.') }}</div></div>@endif
@error('order')<div class="note note-err"><div>{{ $message }}</div></div>@enderror

@if($subs->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'repeat'])</span><div><strong>{{ __('Nessun abbonamento attivo') }}</strong><p>{{ __('Attivalo dal checkout pagando con carta o KMoney: ripeti l\'ordine ogni mese con spedizione gratuita. L\'impegno è di 12 mesi, ma puoi annullare quando vuoi.') }}</p><a class="btn accent" href="/negozio">{{ __('Vai al negozio') }}</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi k-ok"><small>{{ __('Attivi') }}</small><strong>{{ $active->count() }}</strong><span>{{ __(':p in pausa', ['p' => $subs->where('status', 'paused')->count()]) }}</span></div>
  <div class="kpi"><small>{{ __('Prossimo addebito') }}</small><strong>{{ $next ? \App\Support\Ui::date($next, 'd/m/Y') : '—' }}</strong><span>{{ $next ? ucfirst(\App\Support\Ui::inDays($next)) : 'Nessuno in programma' }}</span></div>
 </div>
 <div class="split">
 <div class="stack">
 @foreach($live->concat($old) as $s)
  @php
      [$lab, $tone] = \App\Support\Ui::subscriptionStatus($s->status);
      $cancelled = $s->status === 'cancelled';
      $qty = collect($s->lines)->sum();
      $first = collect($s->lines)->keys()->first();
      $more = max(0, count($s->lines) - 1);
      $sh = $s->billing['shipping'] ?? $s->billing;
      $pct = $s->commitment_months ? min(100, (int) round($s->cycles_done / $s->commitmentCycles() * 100)) : null;
  @endphp
  <details class="panel sub-row {{ $cancelled ? 'is-off' : '' }}" @if($loop->first && ! $cancelled && $live->count() === 1) open @endif>
   <summary>
    <span class="sr-main"><strong>{{ $nice($first) }}@if($more) <span class="sub-more">{{ __('+ altri :more', ['more' => $more]) }}</span>@endif</strong>
     <span class="muted">{{ __(':qty× :p · :p2 · ciclo :p3', ['qty' => $qty, 'p' => $s->cadenceLabel(), 'p2' => $s->payment_method === 'kmoney' ? 'Saldo KMoney' : 'Carta', 'p3' => $s->cycles_done]) }}@if($s->commitment_months)/{{ $s->commitmentCycles() }}@endif</span></span>
    <span class="sr-next">@if($s->status === 'active' && $s->next_run_at)<small>{{ __('Prossimo addebito') }}</small><b>{{ \App\Support\Ui::date($s->next_run_at, 'd/m/Y') }}</b>@endif</span>
    <span class="st st-{{ $tone }}">{{ $lab }}</span>
    <span class="sr-chev" aria-hidden="true"></span>
   </summary>
   <div class="panel-body">
    @if(count($s->lines) > 1)
    <ul class="sub-lines">
     @foreach($s->lines as $pid => $q)<li><span class="q">{{ $q }}×</span><span class="n">{{ $nice($pid) }}</span></li>@endforeach
    </ul>
    @endif
    <dl class="dl dl-sub">
     @if($s->status === 'active' && $s->next_run_at)<dt>{{ __('Prossimo addebito') }}</dt><dd><strong>{{ \App\Support\Ui::date($s->next_run_at) }}</strong> <span class="muted">· {{ \App\Support\Ui::inDays($s->next_run_at) }}</span></dd>@endif
     <dt>{{ __('Cicli completati') }}</dt><dd>{{ $s->cycles_done }}@if($s->commitment_months) {{ __('di :p', ['p' => $s->commitmentCycles()]) }}@endif</dd>
     <dt>{{ __('Pagamento') }}</dt><dd>{{ $s->payment_method === 'kmoney' ? 'Saldo KMoney' : 'Carta' }}</dd>
     <dt>{{ __('Impegno') }}</dt><dd>{{ $s->commitment_months ? $s->commitment_months.' mesi (puoi annullare quando vuoi)' : 'nessuno' }}</dd>
     @if(! $cancelled)<dt>{{ __('Spedizione a') }}</dt><dd>{{ ! empty($sh['recipient']) ? $sh['recipient'].' · ' : '' }}{{ $sh['address'] ?? '—' }}, {{ $sh['zip'] ?? '' }} {{ $sh['city'] ?? '' }} {{ ! empty($sh['province']) ? '('.$sh['province'].')' : '' }}</dd>@endif
     @if($s->skip_months > 0 && $s->status === 'active')<dt>{{ __('Mesi saltati') }}</dt><dd>{{ $s->skip_months }}</dd>@endif
     @if($s->free_shipping)<dt>{{ __('Costo spedizione') }}</dt><dd><span class="st st-ok">{{ __('gratuita') }}</span></dd>@endif
    </dl>
    @if($pct !== null && ! $cancelled)
     <div class="meter" style="margin-top:16px" role="progressbar" aria-label="{{ __('Avanzamento impegno') }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ $pct }}%"></i></div>
    @endif
    @if($s->last_error && ! $cancelled)<div class="note note-err" style="margin:16px 0 0"><div><strong>{{ __('Ultimo addebito non riuscito') }}</strong>{{ $s->last_error }}</div></div>@endif
    @if(! $cancelled)
    <div class="form-actions sub-actions">
     @if($s->status === 'active')
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/salta" onsubmit="return confirm('Saltare il prossimo addebito? Slitterà di un mese.')">@csrf<button class="btn ghost" title="{{ __('Ad esempio se sei in vacanza: il prossimo addebito slitta di un mese') }}">{{ __('Salta il prossimo mese') }}</button></form>
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/pausa" onsubmit="return confirm('Mettere in pausa questo abbonamento?')">@csrf<button class="btn ghost">{{ __('Metti in pausa') }}</button></form>
     @elseif($s->status === 'paused')
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/riprendi">@csrf<button class="btn accent">{{ __('Riprendi') }}</button></form>
     @endif
     @if(($canUpdateCard ?? false) && in_array($s->status, ['active', 'paused'], true))
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/carta">@csrf<button class="btn ghost" title="{{ __('Inserisci una nuova carta sulla pagina sicura di Stripe: nessun addebito') }}">{{ __('Cambia carta') }}</button></form>
     @endif
     @if(($canTasca ?? false) && $s->payment_method === 'card' && in_array($s->status, ['active', 'paused'], true))
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/{{ $s->use_tasca ? 'tasca-no' : 'tasca-si' }}" @unless($s->use_tasca) onsubmit="return confirm('Dai prossimi rinnovi useremo i compensi della Tasca (definitivi, non prelevabili) e, se non bastano, la carta. Confermi?')" @endunless>@csrf<button class="btn ghost">{{ $s->use_tasca ? __('Non usare più la Tasca nei rinnovi') : __('Usa la Tasca nei rinnovi') }}</button></form>
     @endif
     <form method="post" action="/area/abbonamenti/{{ $s->id }}/annulla" class="sub-cancel" onsubmit="return confirm('Vuoi davvero annullare questo abbonamento? L\'operazione non si può annullare.')">@csrf<button class="btn-link-danger">{{ __('Annulla abbonamento') }}</button></form>
    </div>
    <details class="sub-addr"><summary>{{ __('Cambia indirizzo di spedizione') }}</summary>
     <form method="post" action="/area/abbonamenti/{{ $s->id }}/indirizzo">@csrf
      @include('site.shop._ship_picker', ['addresses' => $addresses, 'max' => $maxAddresses, 'selected' => ($sh['id'] ?? null) ?: 'new'])
      <p class="muted" style="font-size:13px">{{ __('Vale dal prossimo ordine; quelli già spediti non cambiano. La fatturazione resta quella dei tuoi dati.') }}</p>
      <button class="btn accent">{{ __('Salva indirizzo') }}</button>
     </form>
    </details>
    @endif
   </div>
  </details>
 @endforeach
 </div>
 <aside class="stack">
  <section class="panel accent">
   <header><h2>{{ __('Come funziona') }}</h2></header>
   <div class="panel-body">
    <ul class="sub-help">
     <li>{!! __('<strong>Riordino periodico.</strong> Parte ogni mese o ogni 2 mesi, secondo l\'abbonamento scelto, alla data indicata, con la carta usata al checkout oppure sul tuo saldo KMoney.') !!}</li>
     <li>{!! __('<strong>In vacanza?</strong> Salta il prossimo mese, oppure metti in pausa e riprendi senza perdere i cicli fatti.') !!}</li>
     <li>{!! __('<strong>Indirizzo.</strong> Cambialo quando vuoi (uno dei tuoi indirizzi salvati o uno nuovo): vale dal prossimo ordine.') !!}</li>
     <li>{!! __('<strong>Spedizione gratuita</strong> su ogni riordino. Puoi annullare quando vuoi.') !!}</li>
     <li>{!! __('<strong>Addebito non riuscito?</strong> Carta rifiutata o saldo KMoney insufficiente: riproviamo dopo :p e ti avvisiamo ogni volta; se anche l\'ultimo tentativo non riesce, l\'abbonamento va in pausa e lo riprendi da qui. Se KMoney chiede la tua conferma, usa il pulsante nell\'email.', ['p' => e($retryText)]) !!}</li>
    </ul>
   </div>
  </section>
  <section class="panel">
   <div class="panel-body"><strong>{{ __('Hai bisogno di cambiare i prodotti?') }}</strong><p class="muted" style="margin:4px 0 12px">{{ __('Annulla questo abbonamento e creane uno nuovo dal checkout, oppure scrivici.') }}</p><a class="btn ghost" href="/area/assistenza">{{ __('Scrivi all\'assistenza') }}</a></div>
  </section>
 </aside>
 </div>
@endif
@endsection
