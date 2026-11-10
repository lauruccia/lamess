@extends('errors.layout')
@section('title', __('Sessione scaduta'))
@section('code', '419')
@section('heading', 'Sessione scaduta')
@section('message')
{{ __('La pagina è rimasta aperta troppo a lungo. Ricarica la pagina e riprova.') }}
@endsection
