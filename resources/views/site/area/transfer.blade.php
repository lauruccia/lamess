@extends('site.layout')
@section('title','Cessione del codice — Lamèss')
@section('width','area')
@section('content')
@php($open = $transfers->contains(fn ($t) => $t->isOpen()))
<div class="pg-head"><div><h1>Cessione del codice attività</h1><p class="sub">Puoi cedere il tuo codice e il tuo account a una persona che non sia già Incaricata. Serve il consenso scritto del Consorzio, che decide a sua discrezione e senza obbligo di motivare il diniego.</p></div></div>
@foreach($errors->all() as $m)<div class="note note-err"><div>{{ $m }}</div></div>@endforeach
<div class="split">
 <div class="stack">
  <section class="panel">
   <header><h2>Come funziona</h2></header>
   <div class="list">
    @foreach(['Il cessionario si registra sul portale e compila i suoi dati anagrafici.', 'Tu invii qui la richiesta con la sua email.', 'Paghi in anticipo '.number_format((float) $fee, 2, ',', '.').' € a titolo di rimborso spese di gestione amministrativa della richiesta.', 'Se il Consorzio approva, il cessionario firma una nuova Lettera di Incarico e completa la verifica dei documenti. Fino ad allora il codice resta sospeso.'] as $i => $t)
     <div class="item"><span class="ico">{{ $i + 1 }}</span><span class="main"><span class="t" style="font-weight:500">{{ $t }}</span></span></div>
    @endforeach
   </div>
   <div class="panel-body" style="border-top:1px solid var(--hair)"><p class="muted" style="margin:0;font-size:13.5px">Compensi maturati e non ancora pagati: il Consorzio ti chiederà se liquidarli a te prima o lasciarli al cessionario. Con la cessione smetti di essere Incaricato e il tuo accesso diventa quello di un cliente.</p></div>
  </section>
  <section class="panel">
   <header><h2>Le mie richieste</h2></header>
   @forelse($transfers as $t)
    <div class="item">
     <span class="main"><span class="t">{{ $t->number }} <span class="st st-{{ ['awaiting_payment' => 'warn', 'paid' => 'info', 'approved' => 'ok', 'rejected' => 'err', 'cancelled' => ''][$t->status] ?? '' }}">{{ ['awaiting_payment' => 'in attesa del pagamento', 'paid' => 'in esame', 'approved' => 'approvata', 'rejected' => 'non accolta', 'cancelled' => 'annullata'][$t->status] ?? $t->status }}</span></span>
      <span class="m">Cessionario: {{ $t->assignee?->profile?->fullName() ?: $t->assignee?->email }} · richiesta del {{ \App\Support\Ui::date($t->requested_at) }}</span></span>
     @if($t->isOpen())<span class="end"><form method="post" action="/area/cessione/{{ $t->id }}/annulla" onsubmit="return confirm('Annullare questa cessione?')">@csrf<button class="btn ghost" style="padding:7px 14px;font-size:13.5px">Annulla</button></form></span>@endif
    </div>
   @empty
    <div class="empty"><span class="ico">@include('site.area._i', ['n' => 'users'])</span><div><strong>Nessuna richiesta</strong><p>Non hai ancora avviato una cessione.</p></div></div>
   @endforelse
  </section>
 </div>
 <aside class="stack">
  @unless($open)
   <form method="post" action="/area/cessione" class="panel accent">@csrf
    <header><h2>Nuova richiesta</h2></header>
    <div class="panel-body">
     <label for="c-em">Email del cessionario (già registrato)</label><input id="c-em" type="email" name="email" value="{{ old('email') }}" autocapitalize="none">
     <label class="chk"><input type="checkbox" name="confirm" value="1"> Ho letto le condizioni e voglio procedere</label>
     <div class="form-actions"><button>Invia la richiesta</button></div>
    </div>
   </form>
  @else
   <div class="note note-info"><div>Hai già una richiesta in corso: finché non si conclude non puoi aprirne un'altra.</div></div>
  @endunless
 </aside>
</div>
@endsection
