@extends('site.layout')
@section('title','Registrazione su invito — Lamèss')
@section('width','auth')
@section('side_title','Lamèss, su invito')
@section('side_text','Ci si iscrive dal link personale di un incaricato Lamèss.')
@push('head')
<meta name="robots" content="noindex, nofollow">
@endpush
@section('content')
<h1>Registrazione solo su invito</h1>
<p class="muted">Per creare un account Lamèss serve il link personale di un incaricato (o il suo invito). Se qualcuno te lo ha mandato, aprilo di nuovo da quel messaggio e potrai registrarti.</p>
<p class="muted">Hai già un account? <a href="/accedi">Accedi</a>.</p>
<p class="muted small">Non hai ricevuto nessun invito? Scrivici dalla pagina <a href="/contatti">Contatti</a>.</p>
@endsection
