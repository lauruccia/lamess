@extends('site.layout')
@section('title', __('Registrazione su invito — Lamèss'))
@section('width','auth')
@section('side_title','Lamèss, su invito')
@section('side_text','Ci si iscrive dal link personale di un incaricato Lamèss.')
@push('head')
<meta name="robots" content="noindex, nofollow">
@endpush
@section('content')
<h1>{{ __('Registrazione solo su invito') }}</h1>
<p class="muted">{{ __('Per creare un account Lamèss serve il link personale di un incaricato (o il suo invito). Se qualcuno te lo ha mandato, aprilo di nuovo da quel messaggio e potrai registrarti.') }}</p>
<p><a class="btn" href="/candidati">{{ __('Candidati') }}</a></p>
<p class="muted">{{ __('Compila i campi: il nostro team valuterà il tuo profilo e riceverai una risposta via email.') }}</p>
<p class="muted">{!! __('Hai già un account? <a href="/accedi">Accedi</a>.') !!}</p>
<p class="muted small">{!! __('Non hai ricevuto nessun invito? Scrivici dalla pagina <a href="/contatti">Contatti</a>.') !!}</p>
@endsection
