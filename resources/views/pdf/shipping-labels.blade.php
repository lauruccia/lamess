<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>Etichette di spedizione</title>
<style>
 @page { margin: {{ $perPage > 1 ? '10mm 8mm' : '8mm' }}; }
 body { font-family: DejaVu Sans, sans-serif; font-size: {{ $perPage > 1 ? '9' : '13' }}pt; color: #000; margin: 0; }
 table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
 table.sheet td { width: 50%; height: {{ $perPage > 1 ? '65' : '120' }}mm; vertical-align: top; padding: 0; }
 .label { border: 1px dashed #666; margin: 1mm; padding: 4mm; height: {{ $perPage > 1 ? '57' : '110' }}mm; overflow: hidden; }
 .from { font-size: 6.5pt; color: #444; border-bottom: 1px solid #999; padding-bottom: 1.5mm; margin-bottom: 2mm; }
 .to { font-size: {{ $perPage > 1 ? '12' : '18' }}pt; font-weight: bold; margin-top: 1mm; }
 .addr { font-size: {{ $perPage > 1 ? '11' : '15' }}pt; margin-top: 1mm; }
 .meta { font-size: 7.5pt; margin-top: 3mm; color: #222; }
 .num { font-weight: bold; }
 .page-break { page-break-after: always; }
</style></head><body>
@foreach(array_chunk($labels, $perPage) as $pi => $page)
 <table class="sheet">
 @foreach(array_chunk($page, $perPage > 1 ? 2 : 1) as $row)
  <tr>
  @foreach($row as $l)
   <td><div class="label">
    <div class="from">Mittente: {{ $l['sender'] }}</div>
    <div>Destinatario</div>
    <div class="to">{{ $l['name'] }}</div>
    <div class="addr">@foreach($l['lines'] as $line){{ $line }}<br>@endforeach</div>
    @if($l['phone'] || $l['email'])<div class="meta">@if($l['phone'])Tel. {{ $l['phone'] }}@endif @if($l['phone'] && $l['email']) · @endif @if($l['email']){{ $l['email'] }}@endif</div>@endif
    <div class="meta"><span class="num">Ordine {{ $l['number'] }}</span> · {{ $l['pieces'] }} {{ $l['pieces'] === 1 ? 'pezzo' : 'pezzi' }}
     @if($perPage === 1)<br>{{ implode(' · ', $l['items']) }}@endif</div>
   </div></td>
  @endforeach
  @if(count($row) < ($perPage > 1 ? 2 : 1))<td></td>@endif
  </tr>
 @endforeach
 </table>
 @if(! $loop->last)<div class="page-break"></div>@endif
@endforeach
</body></html>
