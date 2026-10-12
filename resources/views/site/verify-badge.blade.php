@extends('site.layout')
@section('title', 'Verifica tesserino · Lamèss')
@section('content')
<article class="legal">
 @if($consultant)
  <h1>Tesserino valido</h1>
  <p><strong>{{ $consultant->name }}</strong> è incaricato alle vendite a domicilio di {{ $company['name'] }}.</p>
  <p>Codice: <strong>{{ $consultant->code }}</strong><br>Valido fino al {{ $validUntil->format('d/m/Y') }}</p>
  <p>Confronta nome e fotografia con quelli del tesserino e con un documento di identità.</p>
 @else
  <h1>Tesserino non valido</h1>
  <p>Il tesserino non risulta valido: è scaduto, l'incarico non è più in essere oppure il codice non è corretto. Non consegnare denaro o dati personali e scrivi a <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>.</p>
 @endif
</article>
@endsection
