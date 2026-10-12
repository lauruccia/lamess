<style>
    .c360 { font-size:.9rem; line-height:1.4; }
    .c360 h4 { margin:1.1rem 0 .4rem; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; opacity:.7; }
    .c360 dl { display:grid; grid-template-columns:minmax(9rem,14rem) 1fr; gap:.25rem 1rem; margin:0; }
    .c360 dt { opacity:.7; } .c360 dd { margin:0; font-weight:600; word-break:break-word; }
    .c360 .scroll { overflow-x:auto; }
    .c360 table { width:100%; border-collapse:collapse; font-size:.84rem; }
    .c360 th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65; padding:.3rem .5rem; border-bottom:1px solid rgba(187,180,175,.6); white-space:nowrap; }
    .c360 td { padding:.35rem .5rem; border-bottom:1px solid rgba(187,180,175,.25); vertical-align:top; }
    .c360 .empty { opacity:.65; font-style:italic; }
    .c360 a.more { display:inline-block; margin-top:.35rem; font-size:.82rem; font-weight:600; color:#894C2D; text-decoration:underline; }
</style>
<div class="c360">
    @if(! empty($t['facts']))
        <dl>@foreach($t['facts'] as [$k, $v])<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach</dl>
    @endif
    @foreach($t['tables'] as $tb)
        <h4>{{ $tb['title'] }}</h4>
        @if(empty($tb['rows']))
            <p class="empty">{{ $tb['empty'] }}</p>
        @else
            <div class="scroll"><table>
                <thead><tr>@foreach($tb['columns'] as $col)<th scope="col">{{ $col }}</th>@endforeach</tr></thead>
                <tbody>@foreach($tb['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
            </table></div>
        @endif
        @if($tb['more'])<a class="more" href="{{ $tb['more']['url'] }}">{{ $tb['more']['label'] }} →</a>@endif
    @endforeach
</div>
