@php
    $acc = (array) ($acceptances['items'] ?? []);
    $mark = fn (bool $on) => $on ? '☒' : '☐';
    $signature = public_path('img/firma-consorzio.png');
    $when = $signedAt->setTimezone(new DateTimeZone('Europe/Rome'));
@endphp
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><style>
@page{margin:42px 44px 48px 44px}
body{font-family:DejaVu Sans,sans-serif;font-size:9.5px;color:#13110E;line-height:1.45}
h1{font-size:16px;color:#894C2D;border-bottom:2px solid #C2663A;padding-bottom:6px;margin:0 0 6px}
h2{font-size:11px;color:#894C2D;margin:14px 0 4px}
table{width:100%;border-collapse:collapse}td{padding:3px 6px;vertical-align:top}td.l{width:32%;color:#555}
.box{border:1px solid #BBB4AF;padding:8px;margin:10px 0;background:#FCF6F3}.small{font-size:8px;color:#666}
.ivd-letter p{margin:0 0 5px;text-align:justify}
.ivd-letter ul.prem{margin:0 0 5px 14px;padding:0}.ivd-letter ul.prem li{margin:0 0 3px;text-align:justify}
.ivd-letter p.dich{margin-left:10px}
.att p{margin:0 0 5px}.att .ck{font-size:12px}.att .det{margin:0 0 6px 16px;color:#333}
.firme td{width:50%;padding-top:10px;vertical-align:bottom}
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

@include('legal.ivd-letter-body', ['decl' => $decl ?? [], 'feeEnabled' => $feeEnabled ?? (bool) config('lamess.annual_fee_enabled', true), 'feeAmount' => $feeAmount ?? null])

<p style="margin-top:10px"><strong>Luogo e data:</strong> firmato elettronicamente il {{ $when->format('d/m/Y') }} alle {{ $when->format('H:i') }} (ora italiana).</p>

<div class="att">
<h2>Allegati e accettazioni</h2>
<p><strong>ALLEGATI:</strong> Principi generali e Regolamento (versione {{ $acceptances['documents']['regolamento'] ?? '2.0' }}) · Piano Compensi{{ ! empty($planName) ? ' («'.$planName.'»)' : '' }} · Informativa ai sensi dell'Art. 13 GDPR.</p>
@foreach(\App\Domain\Onboarding\IvdAcceptances::items() as $key => $item)
 <p><span class="ck">{{ $mark((bool) ($acc[$key] ?? false)) }}</span> {{ $item['label'] }}</p>
 @if($item['detail'])<p class="det">{{ $item['detail'] }}</p>@endif
@endforeach
</div>

<table class="firme"><tr>
<td>@if(is_file($signature))<img src="{{ $signature }}" style="height:56px"><br>@endif<strong>Consorzio KADEO GROUP</strong><br>Presidente del Consiglio Direttivo</td>
<td><strong>Incaricato</strong><br>{{ $profile->fullName() }}<br><span class="small">firmato elettronicamente con codice OTP</span></td>
</tr></table>

<div class="box"><strong>Firma elettronica</strong><br>
Firmato il {{ $when->format('d/m/Y H:i') }} (ora italiana) con verifica OTP · provider: {{ $contract->signature_provider }} · rif. {{ $contract->signature_ref }}</div>
<p class="small">Copia generata automaticamente dal portale. L'impronta SHA-256 del documento firmato è conservata insieme alle evidenze di firma.</p>
</body></html>
