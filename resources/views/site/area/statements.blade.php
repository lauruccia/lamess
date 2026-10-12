@extends('site.layout')
@section('title','I miei statini — Lamèss')
@section('width','area')
@section('content')
@php
    $eur = fn ($v) => \App\Support\Money::eur($v);
    $last = $rows->first();
    $toPay = $rows->filter(fn ($r) => $r->status !== 'paid')->sum(fn ($r) => (float) $r->accrued);
    $open = $statements->filter(fn ($s) => in_array($s->status, ['published', 'disputed'], true))->count();
@endphp
<div class="pg-head"><div><h1>I miei statini</h1><p class="sub">Ogni mese chiuso ha il suo statino: importi, origine e motivo di ogni riga. Gli importi sotto la soglia minima si cumulano.</p></div></div>
@if($rows->isEmpty())
 <section class="panel"><div class="empty"><span class="ico">@include('site.area._i', ['n' => 'euro'])</span><div><strong>Ancora nessuno statino</strong><p>Compare alla chiusura del primo mese con provvigioni. Nel frattempo puoi far crescere la rete con il tuo link referral.</p><a class="btn accent" href="/area/referral">Condividi il tuo link</a></div></div></section>
@else
 <div class="kpis">
  <div class="kpi"><small>Ultimo mese ({{ $last->period->code }})</small><strong>€ {{ $eur($last->accrued) }}</strong><span>{{ $last->status === 'paid' ? 'Pagato' : ($last->payout_id ? 'In pagamento' : 'Da pagare o cumulato') }}</span></div>
  <div class="kpi"><small>Ancora da incassare</small><strong>€ {{ $eur($toPay) }}</strong><span>Somma degli statini non pagati</span></div>
  <div class="kpi"><small>Totale maturato</small><strong>€ {{ $eur($rows->sum(fn ($r) => (float) $r->accrued)) }}</strong><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'mese' : 'mesi' }}</span></div>
  <div class="kpi {{ $open ? 'k-warn' : '' }}"><small>Reclami possibili o aperti</small><strong>{{ $open }}</strong><span>{{ $open ? 'Controlla i termini qui sotto' : 'Nessuno' }}</span></div>
 </div>
 <section class="panel">
  <div class="tbl-wrap"><table class="tbl cards">
   <thead><tr><th>Mese</th><th class="num">Maturato</th><th class="num">Trattenuto</th><th>Pagamento</th><th>Reclamo</th><th></th></tr></thead>
   <tbody>
   @foreach($rows as $r)
    @php($doc = $statements[$r->period_id] ?? null)
    <tr>
     <td data-l="Mese"><a href="/area/statini/{{ $r->period->code }}"><strong>{{ $r->period->code }}</strong></a></td>
     <td data-l="Maturato" class="num"><strong>€ {{ $eur($r->accrued) }}</strong></td>
     <td data-l="Trattenuto" class="num">{{ (float) $r->held != 0 ? '€ '.$eur($r->held) : '—' }}</td>
     <td data-l="Pagamento"><span class="st st-{{ $r->status === 'paid' ? 'ok' : ($r->payout_id ? 'info' : 'warn') }}">{{ $r->status === 'paid' ? 'pagato' : ($r->payout_id ? 'in pagamento' : 'da pagare / cumulato') }}</span></td>
     <td data-l="Reclamo">@if($doc){{ ['published' => 'entro il '.$doc->dispute_deadline_at->setTimezone('Europe/Rome')->format('d/m/Y'), 'disputed' => 'aperto', 'accepted' => 'accettato', 'resolved' => 'deciso'][$doc->status] ?? '—' }}@else<span class="muted">—</span>@endif</td>
     <td data-l="Documento"><a href="/area/statini/{{ $r->period->code }}/pdf">PDF</a></td>
    </tr>
   @endforeach
   </tbody>
  </table></div>
 </section>
@endif
@endsection
