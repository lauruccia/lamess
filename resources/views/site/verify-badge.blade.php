@extends('site.layout')
@section('title', __('Verifica tesserino · Lamèss'))
@section('content')
<article class="legal">
 @if($consultant)
  <h1>{{ __('Tesserino valido') }}</h1>
  <p>{!! __('<strong>:p</strong> è incaricato alle vendite a domicilio di :p2.', ['p' => e($consultant->name), 'p2' => e($company['name'])]) !!}</p>
  <p>{!! __('Codice: <strong>:p</strong><br>Valido fino al :p2', ['p' => e($consultant->code), 'p2' => e($validUntil->format('d/m/Y'))]) !!}</p>
  <p>{{ __('Confronta nome e fotografia con quelli del tesserino e con un documento di identità.') }}</p>
 @else
  <h1>{{ __('Tesserino non valido') }}</h1>
  <p>{{ __('Il tesserino non risulta valido: è scaduto, l\'incarico non è più in essere oppure il codice non è corretto. Non consegnare denaro o dati personali e scrivi a') }} <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>.</p>
 @endif
</article>
@endsection
