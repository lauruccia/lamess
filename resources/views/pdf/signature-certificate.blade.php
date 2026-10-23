@php
 $fmt = fn ($d) => $d ? \Carbon\CarbonImmutable::parse($d)->setTimezone('Europe/Rome')->format('d/m/Y H:i:s') : '—';
 $labels = ['created' => 'Creazione', 'viewed' => 'Visualizzazione', 'signed' => 'Firma', 'downloaded' => 'Scarico'];
@endphp
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#13110E;line-height:1.45}
h1{font-size:16px;color:#894C2D;border-bottom:2px solid #C2663A;padding-bottom:6px}h2{font-size:12px;margin:14px 0 4px;color:#894C2D}
table{width:100%;border-collapse:collapse}td,th{padding:3px 6px;vertical-align:top;text-align:left;border-bottom:1px solid #E5DFDA}td.l{width:30%;color:#555}
.hash{font-family:DejaVu Sans Mono,monospace;font-size:8px;word-break:break-all}.small{font-size:8px;color:#666}
</style></head><body>
<h1>Certificato di completamento della firma</h1>
<p class="small">{{ $company['name'] ?? 'Consorzio Kadeo Group' }} · documento: Lettera di incarico (modello {{ $c->template_version }}) · stampato il {{ $fmt(now()) }} (ora italiana)</p>
<h2>Dettagli documento</h2>
<table>
<tr><td class="l">ID richiesta di firma</td><td>{{ $c->id }}</td></tr>
<tr><td class="l">Creata il</td><td>{{ $fmt($e['created_at'] ?? $c->created_at) }}</td></tr>
<tr><td class="l">Firmata il</td><td>{{ $fmt($c->signed_at) }}</td></tr>
<tr><td class="l">Impronta SHA-256 del documento firmato</td><td class="hash">{{ $e['document_sha256'] ?? $c->pdf_hash }}</td></tr>
<tr><td class="l">Codice attività</td><td>{{ $c->consultant?->code ?? '—' }}</td></tr>
</table>
<h2>Firmatario</h2>
<table>
<tr><td class="l">Nome</td><td>{{ $c->user?->profile?->fullName() ?? $c->user?->name }}</td></tr>
<tr><td class="l">Email</td><td>{{ $e['signer_email'] ?? $c->user?->email }}</td></tr>
<tr><td class="l">Verifica email</td><td>{{ ($e['signer_email_verified_at'] ?? $c->user?->email_verified_at) ? 'confermata il '.$fmt($e['signer_email_verified_at'] ?? $c->user?->email_verified_at) : 'non confermata' }}</td></tr>
<tr><td class="l">Metodo di firma</td><td>OTP · provider {{ $e['provider'] ?? $c->signature_provider }} · rif. {{ $e['reference'] ?? $c->signature_ref }}</td></tr>
<tr><td class="l">IP e browser alla firma</td><td>{{ $e['ip'] ?? '—' }} · {{ $e['user_agent'] ?? '—' }}</td></tr>
</table>
@php
 $acc = (array) ($e['acceptances']['items'] ?? []);
 $decl = (array) ($e['declarations'] ?? []);
 $yn = fn ($v) => $v === 'yes' ? 'Sì' : ($v === 'no' ? 'No' : '—');
@endphp
@if($acc !== [])
<h2>Dichiarazioni e accettazioni prima della firma</h2>
<table>
@foreach(\App\Domain\Onboarding\IvdAcceptances::items() as $key => $item)
<tr><td class="l">{{ ($acc[$key] ?? false) ? '☒ Accettato' : '☐ Non accettato' }}</td><td>{{ $item['label'] }}</td></tr>
@endforeach
<tr><td class="l">Art. 12.1</td><td>Reddito netto oltre 5.000 €: {{ $yn($decl['income_over_threshold'] ?? null) }} · altra posizione previdenziale obbligatoria: {{ $yn($decl['other_social_security'] ?? null) }} · pensionato: {{ ! empty($decl['is_pensioner']) ? 'Sì' : 'No' }}</td></tr>
<tr><td class="l">Art. 12.2</td><td>Plurimandatario: {{ $yn($decl['multi_principal'] ?? null) }}{{ ! empty($decl['multi_principal_names']) ? ' — '.$decl['multi_principal_names'] : '' }}</td></tr>
<tr><td class="l">Documenti</td><td>Lettera modello {{ $e['acceptances']['documents']['letter'] ?? $c->template_version }} · Regolamento v{{ $e['acceptances']['documents']['regolamento'] ?? '—' }} · Piano compensi: {{ $e['acceptances']['documents']['piano_compensi'] ?? '—' }} · Liberatoria foto v{{ $e['acceptances']['documents']['photo_release'] ?? '—' }}</td></tr>
</table>
<p class="small">Spunte registrate alle {{ $fmt($e['acceptances']['accepted_at'] ?? $c->signed_at) }} (ora italiana).</p>
@endif
<h2>Registro accessi</h2>
<table>
<tr><th>Evento</th><th>Fase</th><th>Data (ora italiana)</th><th>IP</th></tr>
@forelse($events as $ev)
<tr><td>{{ $labels[$ev->event] ?? $ev->event }}</td><td>{{ $ev->after_signing ? 'Dopo la firma' : 'Prima della firma' }}</td><td>{{ $fmt($ev->occurred_at) }}</td><td>{{ $ev->ip ?? '—' }}</td></tr>
@empty
<tr><td colspan="4">Nessun accesso registrato (firma precedente all'attivazione del registro).</td></tr>
@endforelse
</table>
<p class="small">La geolocalizzazione dell'accesso non viene registrata. Il certificato è generato dal portale a partire dalle evidenze conservate al momento della firma.</p>
</body></html>
