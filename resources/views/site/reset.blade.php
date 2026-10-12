@extends('site.layout')
@section('title','Nuova password — Lamèss')
@section('width','auth')
@section('content')
<h1>Scegli una nuova password</h1>
<form method="post" action="/password-reset" class="card">@csrf
<input type="hidden" name="token" value="{{ $token }}">
<label>Email</label><input name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
<label>Nuova password (almeno 10 caratteri)</label><input name="password" type="password" autocomplete="new-password" required>
<label>Ripeti la password</label><input name="password_confirmation" type="password" autocomplete="new-password" required>
@foreach($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
<button>Salva la password</button></form>
@endsection
