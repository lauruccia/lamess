@extends('errors.layout')
@section('title', __('Troppe richieste'))
@section('code', '429')
@section('heading', 'Troppe richieste')
@section('message')
{{ __('Hai fatto troppi tentativi ravvicinati. Attendi qualche minuto e riprova.') }}
@endsection
