@extends('site.layout')
@section('title', $title.' · Lamèss')
@section('content')
<article class="legal">
 <nav class="legal-nav" aria-label="{{ __('Documenti legali') }}">
  @foreach(\App\Http\Controllers\LegalController::PAGES as $slug => $p)
   @if(\App\Domain\Features\Feature::enabled(\App\Http\Controllers\LegalController::FEATURES[$slug]))
   <a href="/{{ $slug }}" class="{{ $slug === $page ? 'on' : '' }}">{{ $p['title'] }}</a>
   @endif
  @endforeach
 </nav>
 <h1>{{ $title }}</h1>
 @include($view)
</article>
@endsection
