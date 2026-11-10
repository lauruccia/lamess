@extends('errors.layout')
@section('title', __('Manutenzione in corso'))
@section('code', '503')
@section('heading', 'Manutenzione in corso')
@section('message')
{{ __('Stiamo aggiornando il portale. Torna tra qualche minuto.') }}
@endsection
