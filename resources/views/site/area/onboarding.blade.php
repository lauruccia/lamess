@extends('site.layout')
@section('title','Onboarding — Lamèss')
@section('width','area')
@section('content')
<div class="pg-head"><div><h1>Completa l'onboarding</h1><p class="sub">Documenti e IBAN servono per ricevere pagamenti e commissioni. Una volta approvati non devi rifarlo.</p></div><div class="pg-actions"><a class="btn ghost" href="/area">← La mia area</a></div></div>
@if($kycRequest)
 <div class="note"><div><strong>L'amministrazione ti ha chiesto dei documenti</strong>
  <ul class="todo" style="margin:4px 0 0;padding-left:18px">@foreach($kycRequest->labels() as $l)<li>{{ $l }}</li>@endforeach</ul>
  @if($kycRequest->message)<p style="margin:6px 0 0">«{{ $kycRequest->message }}»</p>@endif
  @if($kycRequest->due_at)<p class="muted" style="margin:6px 0 0">Entro il {{ $kycRequest->due_at->setTimezone('Europe/Rome')->format('d/m/Y') }}</p>@endif
 </div></div>
@endif
@if($missing === [])
 <div class="note note-ok"><div><strong>Tutto a posto</strong>Onboarding completo: puoi ricevere pagamenti.</div></div>
@else
 <div class="note"><div><strong>Cosa manca ({{ count($missing) }})</strong><ul class="todo" style="margin:4px 0 0;padding-left:18px">@foreach($missing as $m)<li>{{ $m }}</li>@endforeach</ul></div></div>
@endif
<div class="split">
 <div class="stack">
  @if($onlineKyc)
   <section class="panel accent">
    <header><h2>Verifica d'identità online</h2>@if($onlineVerified)<span class="st st-ok">verificata</span>@endif</header>
    <div class="panel-body">
     @if($onlineVerified)<p style="margin:0">Il tuo documento d'identità è stato verificato online.</p>
     @else<p style="margin-top:0">Documento e selfie in pochi minuti dal telefono, senza caricare file. In alternativa usa i documenti qui sotto.</p>
      @foreach($errors->get('type') as $e)<p class="err">{{ $e }}</p>@endforeach
      <form method="post" action="/area/onboarding/verifica-online">@csrf<button>Verifica ora</button></form>@endif
    </div>
   </section>
  @endif
  <section class="panel">
   <header><h2>Documenti</h2></header>
   <div class="list">
    @foreach($types as $key => $label)
     @php($d = $docs[$key] ?? null)
     <form method="post" action="/area/onboarding/documento" enctype="multipart/form-data" class="item" style="flex-wrap:wrap;align-items:flex-start">@csrf
      <input type="hidden" name="type" value="{{ $key }}">
      <span class="ico">@include('site.area._i', ['n' => 'doc'])</span>
      <span class="main"><span class="t">{{ $label }}
       @if($d)<span class="st st-{{ ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'err'][$d->status] ?? '' }}">{{ ['pending' => 'in verifica', 'approved' => 'approvato', 'rejected' => 'rifiutato'][$d->status] }}</span>@endif</span>
       @if($d && $d->notes)<span class="m" style="color:var(--err)">{{ $d->notes }}</span>@endif
       <span class="m">JPG, PNG o PDF</span></span>
      <span class="end" style="display:flex;gap:8px;align-items:center"><input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf" style="max-width:220px;padding:6px;font-size:13px"><button class="btn ghost" style="margin:0;padding:9px 16px;font-size:14px">Carica</button></span>
     </form>
    @endforeach
   </div>
  </section>
 </div>
 <aside class="stack">
  <form method="post" action="/area/onboarding/iban" class="panel">@csrf
   <header><h2>Dati bancari</h2></header>
   <div class="panel-body">
    <label for="o-iban">IBAN</label><input id="o-iban" name="iban" value="{{ old('iban', $consultant->iban) }}" autocapitalize="characters" autocomplete="off">
    <label for="o-bic">BIC/SWIFT della banca <span class="muted">(obbligatorio se l'IBAN non è di un paese SEPA)</span></label><input id="o-bic" name="bic" value="{{ old('bic', $consultant->bic) }}" autocapitalize="characters" autocomplete="off" maxlength="11" placeholder="Per esempio BCITITMM">
    <label for="o-hold">Intestatario</label><input id="o-hold" name="iban_holder" value="{{ old('iban_holder', $consultant->iban_holder ?: $consultant->name) }}">
    @foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
    <div class="form-actions"><button>Salva</button></div>
   </div>
  </form>
  <section class="panel"><div class="panel-body"><strong>Hai dubbi sui documenti?</strong><p class="muted" style="margin:4px 0 12px">Ti aiutiamo dall'assistenza.</p><a class="btn ghost" href="/area/assistenza">Scrivici</a></div></section>
 </aside>
</div>
@endsection
