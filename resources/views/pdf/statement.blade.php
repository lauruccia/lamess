<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#13110E}
h1{font-size:19px;margin:0 0 4px;color:#894C2D}h2{font-size:13px;margin:16px 0 4px}.muted{color:#6b625b}
table{width:100%;border-collapse:collapse;margin-top:6px}th{background:#FCF6F3;text-align:left;border-bottom:1px solid #BBB4AF;padding:4px}td{padding:4px;border-bottom:1px solid #eee;vertical-align:top}
.r{text-align:right}.total td{font-weight:bold;border-top:2px solid #13110E}.held{color:#894C2D}.note{margin-top:22px;font-size:8.5px;color:#6b625b}
</style></head><body>
@php $eur = fn ($v) => number_format((float) $v, 2, ',', '.'); @endphp
<h1>Statino provvigionale {{ $period->code }}</h1>
<div class="muted">{{ $consultant->name }} [{{ $consultant->code }}] · emesso per conto di {{ $seller['name'] ?? 'Lamèss' }}</div>

<table style="width:100%;margin:12px 0 0"><tr class="total"><td style="font-size:15px">Totale del mese</td><td class="r" style="font-size:15px">€ {{ $eur($total) }}</td></tr></table>
@forelse($sections as $g)
<h2>{{ $g['label'] }}</h2>
<table><tr><th>Origine</th><th class="r">Base €</th><th class="r">%</th><th>Perché</th><th class="r">Importo €</th></tr>
@foreach($g['lines'] as $l)
<tr class="{{ $l['held'] ? 'held' : '' }}"><td>{{ $l['source'] }}@if($l['level']) · livello {{ $l['level'] }}@endif @if($l['extended'] ?? false)(esteso)@endif</td><td class="r">{{ $eur($l['base']) }}</td><td class="r">{{ $l['pct'] ?? '' }}</td><td>{{ $l['why'] }}@if($l['held']) (trattenuto)@endif</td><td class="r">{{ $eur($l['amount']) }}</td></tr>
@endforeach
<tr class="total"><td colspan="4">Totale {{ $g['label'] }}</td><td class="r">{{ $eur($g['accrued']) }}</td></tr></table>
@empty
<p>Nessuna provvigione maturata in questo mese.</p>
@endforelse

<h2>Riepilogo</h2>
<table style="width:60%">
<tr><td>Maturato nel mese</td><td class="r">€ {{ $eur($accrued) }}</td></tr>
@if((float) $held != 0)<tr><td>Trattenuto (non ancora pagabile)</td><td class="r held">€ {{ $eur($held) }}</td></tr>@endif
@if((float) $carried != 0)<tr><td>Residuo dei mesi precedenti</td><td class="r">€ {{ $eur($carried) }}</td></tr>@endif
<tr class="total"><td>Saldo da pagare</td><td class="r">€ {{ $eur((float) $accrued + (float) $carried) }}</td></tr></table>
<p class="muted">
@if($payout?->status === 'paid')Pagato il {{ $payout->paid_at->timezone('Europe/Rome')->format('d/m/Y') }} (rif. {{ $payout->reference }}).
@elseif($payout)Pagamento in preparazione per € {{ $eur($payout->amount) }}.
@elseif(\App\Domain\Wallet\WithdrawalService::enabled())Il totale del mese è accreditato nella Tasca alla chiusura del mese; il prelievo avviene su richiesta.
@else Il pagamento avviene quando il saldo raggiunge € {{ $eur($min_payout) }} e l'onboarding è completo; sotto soglia gli importi si cumulano.@endif
</p>
<p class="note">Importi in euro, IVA esclusa dove dovuta. Eventuali contestazioni circostanziate vanno inviate entro 10 giorni dalla pubblicazione tramite il servizio di assistenza (Reg. 5.2).
Gli esempi e i guadagni indicati non costituiscono garanzia di risultato.</p>
</body></html>
