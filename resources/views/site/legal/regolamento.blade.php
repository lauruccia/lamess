@php
    $md = (string) file_get_contents(resource_path('legal/regolamento-v2.0.md'));
    $md = preg_replace('/^# .*\R+/u', '', $md, 1);
@endphp
<div class="legal-body">{!! \Illuminate\Support\Str::markdown($md) !!}</div>
