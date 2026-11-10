@php
    $__langs = \App\Domain\I18n\Locales::active();
    $__path = '/'.ltrim(request()->path(), '/');
@endphp
@if(count($__langs) > 1 && \App\Domain\I18n\Locales::isPublicPath($__path))
<link rel="canonical" href="{{ url(\App\Domain\I18n\Locales::url($__path)) }}">
@foreach($__langs as $__l)
<link rel="alternate" hreflang="{{ $__l['code'] }}" href="{{ url(\App\Domain\I18n\Locales::url($__path, $__l['code'])) }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ url($__path) }}">
@endif
