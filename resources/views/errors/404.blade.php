@extends('errors.layout')
@section('title', __('Pagina non trovata'))
@section('code', '404')
@section('heading', 'Pagina non trovata')
@section('message')
{{ __('La pagina che cerchi non esiste o è stata spostata. Controlla l\'indirizzo oppure riparti dalla pagina iniziale.') }}
@endsection
