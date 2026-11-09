@extends('site.layout')
@section('title','Candidatura ricevuta — Lamèss')
@section('width','auth')
@section('side_title','Grazie!')
@section('side_text','Il nostro team valuterà il tuo profilo e riceverai una risposta via email.')
@push('head')
<meta name="robots" content="noindex, nofollow">
@endpush
@section('content')
<h1>Candidatura ricevuta</h1>
<p class="muted">Grazie per esserti candidato. Il nostro team valuterà il tuo profilo e riceverai una risposta via email: controlla anche la cartella dello spam.</p>
<p class="muted"><a href="/">Torna al sito</a></p>
@endsection
