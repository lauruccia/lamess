<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#13110E}
h1{font-size:19px;margin:0 0 4px;color:#894C2D}h2{font-size:13px;margin:16px 0 4px}.muted{color:#6b625b}
table{width:100%;border-collapse:collapse;margin-top:6px}th{background:#FCF6F3;text-align:left;border-bottom:1px solid #BBB4AF;padding:4px}td{padding:4px;border-bottom:1px solid #eee;vertical-align:top}
.r{text-align:right}.total td{font-weight:bold;border-top:2px solid #13110E}.note{margin-top:22px;font-size:8.5px;color:#6b625b}
</style></head><body>
@php $eur = fn ($v) => number_format((float) $v, 2, ',', '.'); @endphp
<h1>Statino bonus — settimana {{ $week }}</h1>
<div class="muted">{{ $consultant->name }} [{{ $consultant->code }}] · dal {{ $from->format('d/m/Y') }} al {{ $to->format('d/m/Y') }} · emesso per conto di {{ $seller['name'] ?? 'Lamèss' }}</div>
<table style="margin-top:12px"><tr class="total"><td style="font-size:15px">Totale della settimana</td><td class="r" style="font-size:15px">€ {{ $eur($total) }}</td></tr></table>
<h2>Bonus di Struttura</h2>
@if($lines === [])
<p>Nessun bonus confermato in questa settimana.</p>
@else
<table><tr><th>Incaricato qualificato (PearlQ)</th><th>Perché</th><th>Confermato</th><th>Stato</th><th class="r">Importo €</th></tr>
@foreach($lines as $l)
<tr><td>{{ $l['source'] }}@if($l['reached_at']) · 3° cliente il {{ \Carbon\Carbon::parse($l['reached_at'])->format('d/m/Y') }}@endif</td><td>{{ $l['why'] }}</td><td>{{ $l['confirmed_at']->timezone('Europe/Rome')->format('d/m/Y') }}</td><td>{{ $l['state'] }}</td><td class="r">{{ $eur($l['amount']) }}</td></tr>
@endforeach
<tr class="total"><td colspan="4">Totale Bonus di Struttura</td><td class="r">{{ $eur($total) }}</td></tr></table>
@endif
<p class="note">Importi in euro, IVA esclusa dove dovuta. I bonus confermati sono definitivi e si accreditano nella Tasca; il prelievo avviene su richiesta. Il Bonus di Struttura non compare nello statino mensile.
Gli esempi e i guadagni indicati non costituiscono garanzia di risultato.</p>
</body></html>
