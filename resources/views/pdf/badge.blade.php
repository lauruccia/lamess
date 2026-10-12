<!doctype html>
<html lang="it"><head><meta charset="utf-8"><style>
@page{margin:0}body{margin:0;font-family:DejaVu Sans,sans-serif;color:#13110E}
.card{width:241pt;height:151pt;overflow:hidden;background:#FCF6F3;border:1px solid #BBB4AF;position:relative}
.front{page-break-after:always}
.top{background:#13110E;color:#fff;padding:7pt 10pt;height:12pt;font-size:7.5pt;letter-spacing:.3px;white-space:nowrap;overflow:hidden}.top b{color:#E3AA8D;font-size:10pt;letter-spacing:1px}
.photo{position:absolute;left:10pt;top:33pt;width:58pt;height:74pt;border:1px solid #BBB4AF;background:#fff}
.photo img{width:58pt;height:74pt}
.info{position:absolute;left:78pt;top:33pt;width:155pt;font-size:8pt;line-height:1.3}.info .n{font-size:10pt;font-weight:bold}
.code{color:#894C2D;font-weight:bold;font-size:11pt}
.foot{position:absolute;bottom:4pt;left:10pt;right:10pt;font-size:5.5pt;line-height:1.3;color:#555}
.foot b{color:#13110E}
.qr{position:absolute;left:10pt;top:30pt;width:72pt;height:72pt}
.qrcap{position:absolute;left:10pt;top:105pt;width:72pt;font-size:5.5pt;text-align:center;color:#555}
.co{position:absolute;left:94pt;top:30pt;width:138pt;font-size:6.5pt;line-height:1.3}.co .n{font-size:8pt;font-weight:bold}
.sign{position:absolute;left:94pt;top:112pt;width:138pt;border-top:1px solid #13110E;padding-top:2pt;font-size:5.5pt;color:#555}
</style></head><body>
<div class="card front">
<div class="top"><b>LAMÈSS</b> · Incaricato alle Vendite a Domicilio</div>
<div class="photo">@if($photo)<img src="{{ $photo }}">@endif</div>
<div class="info"><div class="n">{{ $consultant->name }}</div>
<div class="code">{{ $consultant->code }}</div>
<div>Valido fino al {{ $validUntil->format('d/m/Y') }}</div>
@if($sponsorName)<div>Sponsor: {{ $sponsorName }}</div>@endif</div>
<div class="foot"><b>Impresa mandante: {{ $company['name'] }}</b> · P.IVA {{ $company['vat'] }}<br>Tesserino personale — art. 19 D.Lgs. 114/1998. Da esibire su richiesta del cliente.</div>
</div>
<div class="card">
<div class="top"><b>LAMÈSS</b> · Verifica del tesserino</div>
<img class="qr" src="{{ $qr }}">
<div class="qrcap">Inquadra il codice per verificare che il tesserino sia valido</div>
<div class="co"><div class="n">{{ $company['name'] }}</div>
{{ $company['address'] }}<br>P.IVA {{ $company['vat'] }}<br>{{ $company['email'] }}<br>
Tesserino n. {{ $consultant->code }}<br>Emesso il {{ $issuedAt->format('d/m/Y') }}</div>
<div class="sign">Firma del titolare dell'impresa o suo delegato</div>
</div>
</body></html>
