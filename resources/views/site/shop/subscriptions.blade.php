@extends('site.layout')
@section('title','I miei abbonamenti — Lamèss')
@section('width','area')
@section('content')
@php
    $active = $subs->where('status', 'active');
    $next = $active->pluck('next_run_at')->filter()->sort()->first();
@endphp
<div class="pg-head">
 <div>
  <h1>I miei abbonamenti</h1>
  <p class="sub">Riordino automatico dei tuoi prodotti. Puoi metterlo in pausa o annullarlo quando vuoi.</p>
 </div>
 <div class="pg-actions"><a class="btn accent" href="/negozio">Scegli i prodotti</a></div>
</div>

@if(session('status'))<div class="note note-ok"><div>{{ session('status') }}</div></div>@endif
@if(request('carta') === 'ok')<div class="note note-ok"><div>Stiamo registrando la nuova carta: entro pochi istanti sarà attiva per i prossimi addebiti.</div></div>@endif
@error('order')<div class="note note-err"><div>{{ $message }}</div></div>@enderror

@if($subs->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'repeat'])</span><div><strong>Nessun abbonamento attivo</strong><p>Attivalo dal checkout pagando con carta o KMoney: ripeti l'ordine ogni mese con spedizione gratuita. L'impegno è di 12 mesi, ma puoi annullare quando vuoi.</p><a class="btn accent" href="/negozio">Vai al negozio</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi k-ok"><small>Attivi</small><strong>{{ $active->count() }}</strong><span>{{ $subs->where('status', 'paused')->count() }} in pausa</span></div>
  <div class="kpi"><small>Prossimo addebito</small><strong>{{ $next ? \App\Support\Ui::date($next, 'd/m/Y') : '—' }}</strong><span>{{ $next ? ucfirst(\App\Support\Ui::inDays($next)) : 'Nessuno in programma' }}</span></div>
 </div>
 <div class="split">
 <div class="stack">
 @foreach($subs as $s)
  @php
      [$lab, $tone] = \App\Support\Ui::subscriptionStatus($s->status);
      $qty = collect($s->lines)->sum();
  @endphp
  <section class="panel">
   <header><h2>{{ $qty }} {{ $qty === 1 ? 'prodotto' : 'prodotti' }} al mese</h2><span class="st st-{{ $tone }}">{{ $lab }}</span></header>
   <div class="panel-body">
    <ul class="todo" style="margin:0 0 14px;padding-left:18px">
     @foreach($s->lines as $pid => $q)<li>{{ $q }} × {{ $products[$pid] ?? 'prodotto' }}</li>@endforeach
    </ul>
    <dl class="dl">
     @if($s->status === 'active' && $s->next_run_at)<dt>Prossimo addebito</dt><dd>{{ \App\Support\Ui::date($s->next_run_at) }} · {{ \App\Support\Ui::inDays($s->next_run_at) }}</dd>@endif
     <dt>Cicli completati</dt><dd>{{ $s->cycles_done }}@if($s->commitment_months) di {{ $s->commitment_months }}@endif</dd>
     <dt>Pagamento</dt><dd>{{ $s->payment_method === 'kmoney' ? 'Saldo KMoney' : 'Carta' }}</dd>
     <dt>Impegno</dt><dd>{{ $s->commitment_months ? $s->commitment_months.' mesi' : 'nessuno' }}</dd>
     @php($sh = $s->billing['shipping'] ?? $s->billing)
     @if($s->status !== 'cancelled')<dt>Spedizione a</dt><dd>{{ ! empty($sh['recipient']) ? $sh['recipient'].' · ' : '' }}{{ $sh['address'] ?? '—' }}, {{ $sh['zip'] ?? '' }} {{ $sh['city'] ?? '' }} {{ ! empty($sh['province']) ? '('.$sh['province'].')' : '' }}</dd>@endif
     @if($s->skip_months > 0 && $s->status === 'active')<dt>Mesi saltati</dt><dd>{{ $s->skip_months }}</dd>@endif
     @if($s->free_shipping)<dt>Spedizione</dt><dd><span class="st st-ok">spedizione gratuita</span></dd>@endif
    </dl>
    @if($s->commitment_months)
     <div class="meter" style="margin-top:14px" role="progressbar" aria-valuenow="{{ min(100, (int) round($s->cycles_done / $s->commitment_months * 100)) }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ min(100, (int) round($s->cycles_done / $s->commitment_months * 100)) }}%"></i></div>
    @endif
    @if($s->last_error && $s->status !== 'cancelled')<div class="note note-err" style="margin:14px 0 0"><div><strong>Ultimo addebito non riuscito</strong>{{ $s->last_error }}</div></div>@endif
    @if($s->status !== 'cancelled')
     <div class="form-actions">
      @if($s->status === 'active')
       <form method="post" action="/area/abbonamenti/{{ $s->id }}/salta">@csrf<button class="btn ghost" title="Ad esempio se sei in vacanza: il prossimo addebito slitta di un mese">Salta il prossimo mese</button></form>
       <form method="post" action="/area/abbonamenti/{{ $s->id }}/pausa">@csrf<button class="btn ghost">Metti in pausa</button></form>
      @elseif($s->status === 'paused')
       <form method="post" action="/area/abbonamenti/{{ $s->id }}/riprendi">@csrf<button class="btn accent">Riprendi</button></form>
      @endif
      @if(($canUpdateCard ?? false) && in_array($s->status, ['active', 'paused'], true))
       <form method="post" action="/area/abbonamenti/{{ $s->id }}/carta">@csrf<button class="btn ghost" title="Inserisci una nuova carta sulla pagina sicura di Stripe: nessun addebito">Cambia carta</button></form>
      @endif
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/annulla">@csrf<button class="btn ghost">Annulla abbonamento</button></form>
     </div>
     <details style="margin-top:14px"><summary style="cursor:pointer">Cambia indirizzo di spedizione</summary>
      <form method="post" action="/area/abbonamenti/{{ $s->id }}/indirizzo" style="margin-top:10px">@csrf
       @include('site.shop._ship_picker', ['addresses' => $addresses, 'max' => $maxAddresses, 'selected' => ($sh['id'] ?? null) ?: 'new'])
       <p class="muted" style="font-size:13px">Vale dal prossimo ordine; quelli già spediti non cambiano. La fatturazione resta quella dei tuoi dati.</p>
       <button class="btn accent">Salva indirizzo</button>
      </form>
     </details>
     @if($s->free_shipping && $s->cycles_done < $s->commitment_months)<p class="muted" style="margin:12px 0 0;font-size:13px">L'impegno è di {{ $s->commitment_months }} mesi, ma puoi annullare quando vuoi: la disdetta anticipata viene solo registrata.</p>@endif
    @endif
   </div>
  </section>
 @endforeach
 </div>
 <aside class="stack">
  <section class="panel accent">
   <header><h2>Come funziona</h2></header>
   <div class="panel-body">
    <ul class="todo" style="margin:0;padding-left:18px;line-height:1.7">
     <li>Il riordino parte ogni mese alla data indicata, con la carta usata al checkout oppure sul tuo saldo KMoney.</li>
     <li>Se sei in vacanza puoi saltare il prossimo mese, oppure mettere in pausa e riprendere senza perdere i cicli fatti.</li>
     <li>Puoi cambiare l'indirizzo di spedizione in qualsiasi momento (scegli uno dei tuoi indirizzi salvati o ne aggiungi uno): vale dal prossimo ordine.</li>
     <li>La spedizione è gratuita su ogni riordino. Puoi annullare quando vuoi.</li>
     <li>Se un addebito non va a buon fine (carta rifiutata o saldo KMoney insufficiente) riproviamo dopo un giorno, fino a 3 volte; poi l'abbonamento va in pausa e lo riprendi da qui.</li>
    </ul>
   </div>
  </section>
  <section class="panel">
   <div class="panel-body"><strong>Hai bisogno di cambiare i prodotti?</strong><p class="muted" style="margin:4px 0 12px">Annulla questo abbonamento e creane uno nuovo dal checkout, oppure scrivici.</p><a class="btn ghost" href="/area/assistenza">Scrivi all'assistenza</a></div>
  </section>
 </aside>
 </div>
@endif
@endsection
