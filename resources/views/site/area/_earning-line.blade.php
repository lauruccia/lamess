{{-- Una riga di compenso: titolo e stato, frase che spiega da dove nasce, dettagli, importo. Parametri: $l (riga del CommissionExplainer), $eur, $tone. --}}
<div class="item" style="align-items:flex-start">
 <span class="main">
  <span class="t">{{ $l['title'] }} <span class="st st-{{ $tone($l['status']) }}">{{ $l['status_label'] }}</span>@if($l['state_label']) <span class="st st-info">{{ $l['state_label'] }}</span>@endif</span>
  <span class="m" style="color:var(--ink)">{{ $l['sentence'] }}</span>
  @foreach($l['details'] as $t)<span class="m">• {{ $t }}</span>@endforeach
  @if($l['confirmed_at'])<span class="m">• Confermato il {{ $l['confirmed_at'] }}.</span>@endif
 </span>
 <span class="end"><b>€ {{ $eur($l['amount']) }}</b>@if($l['pct'])<span class="m">{{ $l['pct'] }}@if($l['base'] !== null) su € {{ $eur($l['base']) }}@endif</span>@endif</span>
</div>
