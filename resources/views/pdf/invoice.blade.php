<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#13110E}
h1{font-size:20px;margin:0 0 4px;color:#894C2D}.muted{color:#6b625b}
table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#FCF6F3;text-align:left;border-bottom:1px solid #BBB4AF;padding:5px}td{padding:5px;border-bottom:1px solid #eee}
.r{text-align:right}.box{width:48%;display:inline-block;vertical-align:top}.total td{font-weight:bold;border-top:2px solid #13110E}
.note{margin-top:24px;font-size:9px;color:#6b625b}
</style></head><body>
@php
 $title = $isCredit ? 'NOTA DI CREDITO' : ($doc->type === 'receipt' ? 'RICEVUTA' : 'FATTURA');
 $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<h1>{{ $title }} {{ $doc->number }}</h1>
<div class="muted">Data: {{ $doc->issued_at->timezone('Europe/Rome')->format('d/m/Y') }} · Ordine {{ $doc->order->number ?? '' }}
@if($isCredit) · Rettifica la fattura {{ $doc->invoice->number }}@endif</div>
<div style="margin-top:14px">
 <div class="box"><b>Cedente</b><br>{{ $seller['name'] }}<br>@if($seller['vat_number'])P.IVA {{ $seller['vat_number'] }}<br>@endif{{ $seller['address'] }}</div>
 <div class="box"><b>Cessionario</b><br>{{ $buyer['name'] }}<br>@if($buyer['vat_number'])P.IVA {{ $buyer['vat_number'] }}<br>@endif @if($buyer['tax_code'])C.F. {{ $buyer['tax_code'] }}<br>@endif{{ $buyer['address'] }}, {{ $buyer['zip'] }} {{ $buyer['city'] }} {{ $buyer['province'] }}<br>@if(!empty($buyer['sdi_code']))Codice SDI: {{ $buyer['sdi_code'] }}@elseif(!empty($buyer['pec']))PEC: {{ $buyer['pec'] }}@endif</div>
</div>
@if($isCredit)<p><b>Motivo:</b> {{ $doc->reason }}</p>@endif
<table><tr><th>Descrizione</th><th class="r">Q.tà</th><th class="r">Prezzo unit.</th><th class="r">Imponibile</th><th class="r">IVA %</th></tr>
@foreach($doc->lines as $l)
<tr><td>{{ $l->description }}</td><td class="r">{{ $l->qty }}</td><td class="r">{{ $eur($l->unit_net) }}</td><td class="r">{{ $eur($l->net) }}</td><td class="r">{{ $eur($l->vat_rate) }}</td></tr>
@endforeach</table>
<table style="width:55%;margin-left:45%">
@foreach($totals['by_rate'] as $rate => $g)
<tr><td>Imponibile IVA {{ $eur($rate) }}%</td><td class="r">€ {{ $eur($g['net']) }}</td></tr>
<tr><td>Imposta</td><td class="r">€ {{ $eur($g['vat']) }}</td></tr>
@endforeach
<tr class="total"><td>TOTALE {{ $isCredit ? 'DA RIMBORSARE' : '' }}</td><td class="r">€ {{ $eur($doc->total) }}</td></tr></table>
<p class="note">Copia di cortesia. Il documento fiscale è quello trasmesso tramite il sistema di fatturazione elettronica
@if(!$isCredit && $doc->type === 'receipt') (ricevuta non valida come fattura)@endif.</p>
</body></html>
