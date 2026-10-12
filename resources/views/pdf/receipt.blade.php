<!doctype html>
<html lang="it"><head><meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#13110E}
h1{font-size:19px;margin:0 0 4px;color:#894C2D}h2{font-size:13px;margin:16px 0 4px}.muted{color:#6b625b}
table{width:100%;border-collapse:collapse;margin-top:6px}td{padding:5px;border-bottom:1px solid #eee;vertical-align:top}
.r{text-align:right}.total td{font-weight:bold;border-top:2px solid #13110E}.note{margin-top:22px;font-size:8.5px;color:#6b625b}
</style></head><body>
@php $eur = fn ($v) => number_format((float) $v, 2, ',', '.'); $c = $r->consultant; @endphp
<h1>Ricevuta provvigionale {{ $r->number }}</h1>
<div class="muted">Emessa il {{ $r->issued_at->setTimezone('Europe/Rome')->format('d/m/Y') }} per conto di {{ $c->name }} [{{ $c->code }}] da {{ $seller['name'] ?? 'Lamèss' }}@if(!empty($seller['vat_number'])) · P.IVA/CF {{ $seller['vat_number'] }}@endif</div>

<h2>Compenso</h2>
<table>
<tr><td>Provvigioni lorde liquidate (pagamento del {{ $r->payout->paid_at?->setTimezone('Europe/Rome')->format('d/m/Y') ?? '—' }}, @if($r->payout->structureBonuses()->exists())Bonus di Struttura @else mese {{ $r->payout->period->code }}@endif)</td><td class="r">€ {{ $eur($r->gross) }}</td></tr>
@if($r->regime === 'vat_holder')
<tr><td colspan="2" class="muted">Incaricato con partita IVA: nessuna ritenuta né contributo trattenuti. Il compenso va fatturato dall'incaricato con la propria partita IVA.</td></tr>
@else
<tr><td>Quota imponibile ({{ rtrim(rtrim((string) ($r->params['taxable_pct'] ?? ''), '0'), '.') }}%)</td><td class="r">€ {{ $eur($r->taxable_base) }}</td></tr>
@if((float) $r->withholding != 0)<tr><td>Ritenuta d'acconto ({{ rtrim(rtrim((string) ($r->params['withholding_pct'] ?? ''), '0'), '.') }}% sulla quota imponibile)</td><td class="r">− € {{ $eur($r->withholding) }}</td></tr>@endif
@if((float) $r->inps_total != 0)<tr><td>Contributo INPS Gestione Separata: base € {{ $eur($r->inps_base) }}, totale € {{ $eur($r->inps_total) }}; quota a carico dell'incaricato</td><td class="r">− € {{ $eur($r->inps_worker) }}</td></tr>@endif
@endif
@if((float) $r->fee != 0)<tr><td>Commissione bonifico</td><td class="r">− € {{ $eur($r->fee) }}</td></tr>@endif
<tr class="total"><td>Netto pagato</td><td class="r">€ {{ $eur($r->net) }}</td></tr>
</table>
<p class="muted">Eventuali anomalie vanno segnalate in modo circostanziato entro il {{ $r->dispute_deadline_at->setTimezone('Europe/Rome')->format('d/m/Y') }} tramite il servizio di assistenza (Reg. 5.1); dopo tale data la ricevuta si considera accettata.</p>
<p class="note">Documento emesso dal Consorzio per conto dell'incaricato. Il trattamento fiscale indicato segue i parametri in vigore al momento del pagamento.</p>
</body></html>
