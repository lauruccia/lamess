@extends('errors.layout')
@section('title', __('Accesso non consentito'))
@section('code', '403')
@section('heading', 'Accesso non consentito')
@section('message')
{{ __('Non hai i permessi per vedere questa pagina. Se pensi sia un errore, scrivi al Consorzio.') }}
@endsection
