@extends('site.layout')
@section('title', $title.' · Lamèss')
@section('content')
<article class="legal">
 <nav class="legal-nav" aria-label="Documenti legali">
  @foreach(\App\Http\Controllers\LegalController::PAGES as $slug => $p)
   <a href="/{{ $slug }}" class="{{ $slug === $page ? 'on' : '' }}">{{ $p['title'] }}</a>
  @endforeach
 </nav>
 <h1>{{ $title }}</h1>
 @include($view)
</article>
@endsection
