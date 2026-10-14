@foreach($ids as $id)
 @php($c = $nodes[$id])
 @php($s = $stats[$id] ?? null)
 @php($kids = $children[$id] ?? [])
 <li class="{{ $kids ? '' : 'leaf' }}">
  @if($kids)<details><summary>@else<div>@endif
   <span class="node">
    <span class="tog"></span>
    <span><span class="nm">{{ $c->name }}</span> <span class="cd">{{ $c->code }}</span></span>
    @if($c->status === 'suspended')<span class="badge grey">sospeso</span>@elseif($c->status === 'terminated')<span class="badge grey">cessato</span>@elseif($s)<span class="badge {{ $s['active'] ? 'green' : 'grey' }}">{{ $s['active'] ? 'attivo' : 'non attivo' }}</span>@endif
    @if($s)<span class="kv rank-chip" style="--stone: {{ \App\Domain\Career\RankPalette::color($s['rank']) }}">{{ \App\Domain\Career\RankPalette::label($s['rank']) }}</span>@if(\App\Domain\Plan\LegacyPlan::visible())<span class="kv">PVF {{ number_format((float) $s['pvf'], 0, ',', '.') }}</span><span class="kv">PR {{ number_format((float) $s['pr'], 0, ',', '.') }}</span>@endif @endif
    @if($kids)<span class="kv">{{ count($kids) }} {{ count($kids) === 1 ? 'diretto' : 'diretti' }}</span>@endif
   </span>
  @if($kids)</summary><ul>@include('site.area._node', ['ids' => $kids])</ul></details>@else</div>@endif
 </li>
@endforeach
