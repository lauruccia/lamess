@extends('site.layout')
@section('title','Ordine '.$order->number)
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    [$lab, $tone] = \App\Support\Ui::orderStatus($order->status);
    $isPaid = in_array($order->status, array_merge(\App\Models\Order::PAID_STATES, ['refunded']), true);
    $isShipped = in_array($order->status, ['shipped', 'delivered'], true) || $order->shipped_at !== null || filled($order->tracking_code);
    $closed = in_array($order->status, ['cancelled', 'refunded'], true);
    $b = (array) ($order->billing ?? []);
    $pieces = (int) $order->items->sum('qty');
@endphp
<p class="crumb"><a href="/area/ordini">← Tutti gli ordini</a></p>
<div class="pg-head">
 <div>
  <h1>Ordine {{ $order->number }}</h1>
  <p class="sub">Effettuato il {{ \App\Support\Ui::date($order->created_at, 'd/m/Y \a\l\l\e H:i') }} · {{ $pieces }} {{ $pieces === 1 ? 'pezzo' : 'pezzi' }} · <span class="st st-{{ $tone }}">{{ $lab }}</span></p>
 </div>
 @if($order->invoice)
  <div class="pg-actions"><a class="btn accent" href="/area/ordini/{{ $order->id }}/fattura">{{ $order->invoice->type === 'receipt' ? 'Ricevuta' : 'Fattura' }} {{ $order->invoice->number }} (PDF)</a></div>
 @endif
</div>

@error('order')<div class="note note-err"><div>{{ $message }}</div></div>@enderror

@if($order->status === 'pending_payment')
 @if($order->payment_method === 'transfer')
  <div class="note"><div><strong>Fai il bonifico per confermare l'ordine</strong>{{ config('lamess.bank_transfer_instructions') }}<br>Causale: <strong>{{ $order->number }}</strong> · Importo: <strong>€ {{ $eur($order->total) }}</strong>. L'ordine sarà confermato alla ricezione del pagamento.</div></div>
 @elseif($order->payment_method === 'kmoney')
  @php($kmPaid = $order->payments->contains(fn ($p) => $p->method === 'kmoney' && $p->status === 'paid'))
  @if(! $kmPaid)
   <div class="note"><div><strong>Pagamento KMoney da completare</strong>Addebitiamo € {{ $eur($order->kmoney_amount) }} dal tuo saldo KMoney. Se è la prima volta ti portiamo su KMoney per collegare il conto o confermare l'acquisto. Non hai ancora un conto? <a href="{{ config('lamess.kmoney.register_url') ?: 'https://kmoney.it/register' }}" target="_blank" rel="noopener">Apri un conto KMoney</a>.</div>
    <form method="post" action="/area/ordini/{{ $order->id }}/riprova">@csrf<button class="btn">Paga con KMoney · € {{ $eur($order->kmoney_amount) }}</button></form></div>
  @elseif($order->kmoney_rest_method === 'transfer')
   <div class="note"><div><strong>Quota KMoney incassata · manca il bonifico</strong>{{ config('lamess.bank_transfer_instructions') }}<br>Causale: <strong>{{ $order->number }}</strong> · Importo: <strong>€ {{ $eur($order->restDue()) }}</strong>. L'ordine sarà confermato alla ricezione del pagamento.</div></div>
  @else
   <div class="note"><div><strong>Quota KMoney incassata · manca il resto</strong>Paga i restanti € {{ $eur($order->restDue()) }} con carta per confermare l'ordine.</div>
    <form method="post" action="/area/ordini/{{ $order->id }}/riprova">@csrf<button class="btn">Paga con carta · € {{ $eur($order->restDue()) }}</button></form></div>
  @endif
 @else
  <div class="note"><div><strong>Pagamento non completato</strong>Per confermare l'ordine paga con carta: ci vuole un minuto.</div>
   <form method="post" action="/area/ordini/{{ $order->id }}/riprova">@csrf<button class="btn">Paga con carta · € {{ $eur($order->total) }}</button></form></div>
 @endif
@endif

@if($order->status === 'pending_payment' && $order->payment_method === 'transfer')
 <form method="post" action="/area/ordini/{{ $order->id }}/annulla" onsubmit="return confirm('Annullare questo ordine?')" style="margin:0 0 18px">@csrf<button class="btn ghost">Annulla ordine</button></form>
@endif

@unless($closed)
 <section class="panel" style="margin-bottom:18px"><div class="panel-body">
  <ol class="track">
   <li class="done">Ordine ricevuto</li>
   <li class="{{ $isPaid ? 'done' : 'now' }}">Pagamento{{ $isPaid && $order->paid_at ? ' · '.\App\Support\Ui::date($order->paid_at, 'd/m') : '' }}</li>
   <li class="{{ $isShipped ? 'done' : ($isPaid ? 'now' : '') }}">Spedizione{{ $order->status === 'processing' ? ' · in preparazione' : ($order->shipped_at ? ' · '.\App\Support\Ui::date($order->shipped_at, 'd/m') : '') }}</li>
   <li class="{{ $order->delivered_at ? 'done' : ($isShipped ? 'now' : '') }}">Consegna{{ $order->delivered_at ? ' · '.\App\Support\Ui::date($order->delivered_at, 'd/m') : '' }}</li>
  </ol>
 </div></section>
@else
 <div class="note note-info"><div><strong>Ordine {{ $order->status === 'refunded' ? 'reso' : 'annullato' }}</strong>Per dubbi o rimborsi scrivi all'assistenza indicando il numero d'ordine.</div><a class="btn" href="/area/assistenza">Contatta l'assistenza</a></div>
@endunless

<div class="split">
 <section class="panel">
  <header><h2>Prodotti</h2></header>
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Prodotto</th><th class="num">Q.tà</th><th class="num">Importo</th></tr></thead>
   <tbody>
   @foreach($order->items as $i)
    <tr>
     <td data-l="Prodotto"><strong>{{ $i->name }}</strong>
      @if($i->recipient_user_id && $i->recipient_user_id !== $order->buyer_user_id)<br><span class="muted">per {{ \App\Models\User::find($i->recipient_user_id)?->name }}</span>@endif
      @if($i->is_gift) <span class="st st-ok">omaggio</span>@elseif($i->parent_item_id)<br><span class="muted">incluso nel kit</span>@endif</td>
     <td data-l="Q.tà" class="num">{{ $i->qty }}</td>
     <td data-l="Importo" class="num">€ {{ $eur($i->unit_net * $i->qty) }}</td>
    </tr>
   @endforeach
   </tbody>
  </table></div>
  <div class="panel-body" style="border-top:1px solid var(--hair)">
   <dl class="dl">
    @if($order->shipping_net > 0)<dt>Spedizione</dt><dd>€ {{ $eur($order->shipping_net) }}</dd>@endif
    <dt>IVA</dt><dd>€ {{ $eur($order->vat_total) }}</dd>
    <dt><strong>Totale</strong></dt><dd style="font-size:18px"><strong>€ {{ $eur($order->total) }}</strong></dd>
   </dl>
  </div>
 </section>

 <aside class="stack">
  <section class="panel">
   <header><h2>Pagamento e consegna</h2></header>
   <div class="panel-body">
    <dl class="dl">
     <dt>Pagamento</dt><dd>@if($order->payment_method === 'kmoney')KMoney € {{ $eur($order->kmoney_amount) }}@if((float) $order->restDue() > 0) + {{ $order->kmoney_rest_method === 'transfer' ? 'bonifico' : 'carta' }} € {{ $eur($order->restDue()) }}@endif @else{{ $order->payment_method === 'transfer' ? 'Bonifico bancario' : 'Carta' }}@endif</dd>
     @if($order->paid_at)<dt>Pagato il</dt><dd>{{ \App\Support\Ui::date($order->paid_at) }}</dd>@endif
     @if($order->shipped_at)<dt>Spedito il</dt><dd>{{ \App\Support\Ui::date($order->shipped_at) }}</dd>@endif
     @if($order->carrier)<dt>Corriere</dt><dd>{{ $order->carrier }}</dd>@endif
     @if($order->delivered_at)<dt>Consegnato il</dt><dd>{{ \App\Support\Ui::date($order->delivered_at) }}</dd>@endif
     @if($order->tracking_code)<dt>Tracking</dt><dd><strong>{{ $order->tracking_code }}</strong></dd>@endif
     @if(! empty($b['address']))<dt>Fatturazione</dt><dd>{{ $b['address'] }}@if(! empty($b['zip']) || ! empty($b['city']))<br>{{ trim(($b['zip'] ?? '').' '.($b['city'] ?? '')) }}@if(! empty($b['province'])) ({{ $b['province'] }})@endif @endif</dd>@endif
     @php($sp = (array) ($b['shipping'] ?? []))
     @if(! empty($sp['address']))<dt>Spedizione a</dt><dd>@if(! empty($sp['recipient'])){{ $sp['recipient'] }}<br>@endif{{ $sp['address'] }}<br>{{ trim(($sp['zip'] ?? '').' '.($sp['city'] ?? '')) }}@if(! empty($sp['province'])) ({{ $sp['province'] }})@endif</dd>@endif
    </dl>
   </div>
  </section>
  @if($order->invoice || $order->creditNotes->isNotEmpty())
   <section class="panel">
    <header><h2>Documenti</h2></header>
    <div class="list">
     @if($order->invoice)<a class="item" href="/area/ordini/{{ $order->id }}/fattura"><span class="ico">@include('site.area._i', ['n' => 'doc'])</span><span class="main"><span class="t">{{ $order->invoice->type === 'receipt' ? 'Ricevuta' : 'Fattura' }} {{ $order->invoice->number }}</span><span class="m">PDF</span></span></a>@endif
     @foreach($order->creditNotes as $n)<a class="item" href="/area/note-credito/{{ $n->id }}"><span class="ico">@include('site.area._i', ['n' => 'doc'])</span><span class="main"><span class="t">Nota di credito {{ $n->number }}</span><span class="m">PDF</span></span></a>@endforeach
    </div>
   </section>
  @endif
  @php($retSvc = app(\App\Domain\Orders\ReturnRequestService::class))
  @php($retReqs = $order->returnRequests()->latest('id')->get())
  @if($retReqs->isNotEmpty() || $retSvc->canRequest($order))
   <section class="panel">
    <header><h2>Reso</h2></header>
    <div class="panel-body">
     @foreach($retReqs as $rq)
      <p style="margin:0 0 10px"><strong>{{ $rq->statusLabel() }}</strong> · {{ \App\Support\Ui::date($rq->created_at) }}@if($rq->admin_note)<br><span class="muted">{{ $rq->admin_note }}</span>@endif</p>
     @endforeach
     @if($retSvc->canRequest($order))
      @error('return')<p class="err">{{ $message }}</p>@enderror
      <form method="post" action="/area/ordini/{{ $order->id }}/reso" class="stack">@csrf
       <p class="muted small" style="margin:0">Puoi chiedere il reso entro {{ \App\Domain\Orders\ReturnRequestService::windowDays() }} giorni dalla consegna.</p>
       @foreach($order->items->where('parent_item_id', null)->filter(fn ($i) => (float) $i->unit_net > 0 && $i->qty > $i->qty_refunded) as $it)
        <label class="small">{{ $it->name }} — quantità da rendere (max {{ $it->qty - $it->qty_refunded }})
         <input type="number" name="qty[{{ $it->id }}]" min="0" max="{{ $it->qty - $it->qty_refunded }}" value="{{ old('qty.'.$it->id, 0) }}"></label>
       @endforeach
       <label class="small">Motivo<textarea name="reason" rows="3" required>{{ old('reason') }}</textarea></label>
       <button class="btn">Richiedi il reso</button>
      </form>
     @endif
    </div>
   </section>
  @endif
  @php($refundRows = $order->refunds()->where('status', '!=', 'failed')->get())
  @if($refundRows->isNotEmpty())
   <section class="panel">
    <header><h2>Rimborsi</h2></header>
    <div class="panel-body">
     <dl class="dl">
      @foreach($refundRows as $rf)
       <dt>{{ \App\Domain\Orders\OrderService::methodLabel($rf->method) === 'bonifico' ? 'Bonifico' : ucfirst(\App\Domain\Orders\OrderService::methodLabel($rf->method)) }}</dt><dd>€ {{ $eur($rf->amount) }} · {{ $rf->status === 'done' ? 'rimborsato'.($rf->refunded_at ? ' il '.\App\Support\Ui::date($rf->refunded_at) : '') : 'in corso' }}</dd>
      @endforeach
     </dl>
    </div>
   </section>
  @endif
  <section class="panel">
   <div class="panel-body"><strong>Problemi con l'ordine?</strong><p class="muted" style="margin:4px 0 12px">Scrivici indicando il numero {{ $order->number }}: rispondiamo dall'area assistenza.</p><a class="btn ghost" href="/area/assistenza">Apri una richiesta</a></div>
  </section>
 </aside>
</div>
@endsection
