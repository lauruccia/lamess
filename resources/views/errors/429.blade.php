@extends('errors.layout')
@section('title', 'Troppe richieste')
@section('code', '429')
@section('heading', 'Troppe richieste')
@section('message')
Hai fatto troppi tentativi ravvicinati. Attendi qualche minuto e riprova.
@endsection
