<!doctype html>
<html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#13110E;line-height:1.5}
h1{font-size:17px;color:#894C2D;border-bottom:2px solid #C2663A;padding-bottom:6px}
table{width:100%;border-collapse:collapse}td{padding:3px 6px;vertical-align:top}td.l{width:32%;color:#555}
.box{border:1px solid #BBB4AF;padding:8px;margin:10px 0;background:#FCF6F3}.small{font-size:9px;color:#666}
</style></head><body>
<h1>Lettera di Incarico — Incaricato alle Vendite a Domicilio</h1>
<p class="small">Modello versione {{ $contract->template_version }} · Lamèss® — Kadeo Group</p>
<div class="box"><table>
<tr><td class="l">Incaricato</td><td><strong>{{ $profile->fullName() }}</strong></td></tr>
<tr><td class="l">Codice fiscale</td><td>{{ $profile->tax_code }}</td></tr>
<tr><td class="l">Nato/a</td><td>{{ $profile->birth_place }} il {{ $profile->birth_date?->format('d/m/Y') }}</td></tr>
<tr><td class="l">Residenza</td><td>{{ $profile->address }}, {{ $profile->zip }} {{ $profile->city }} {{ $profile->province }}</td></tr>
<tr><td class="l">Codice attività</td><td>{{ $consultant->code }}</td></tr>
<tr><td class="l">Sponsor</td><td>{{ $sponsor ? $sponsor->name.' ['.$sponsor->code.']' : '—' }}</td></tr>
</table></div>
<p>Con la sottoscrizione della presente lettera l'Incaricato accetta l'incarico di vendita diretta a domicilio ai sensi dell'art. 19 D.Lgs. 114/1998 e L. 173/2005, alle condizioni del <strong>Regolamento «Principi generali»</strong> e del <strong>Piano Compensi</strong> allegati e vigenti alla data di firma.</p>
<p><strong>Durata.</strong> L'incarico dura un anno dalla data di firma e si rinnova tacitamente.@if(config('lamess.annual_fee_enabled', true)) Il primo anno non prevede canone; dal secondo anno è dovuto un canone amministrativo annuale di {{ number_format((float) $feeAmount, 2, ',', '.') }} € (IVA compresa). In caso di mancato pagamento alla scadenza la lettera si risolve automaticamente.@else Non è previsto alcun canone annuale.@endif</p>
<p><strong>Accettazione.</strong> Il Consorzio può accettare o rifiutare la domanda senza obbligo di motivazione.</p>
<div class="box"><strong>Firma elettronica</strong><br>
Firmato il {{ $signedAt->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i') }} (ora italiana) con verifica OTP · provider: {{ $contract->signature_provider }} · rif. {{ $contract->signature_ref }}</div>
<p class="small">Copia generata automaticamente dal portale. L'impronta SHA-256 del documento firmato è conservata insieme alle evidenze di firma.</p>
</body></html>
