@extends('errors.layout')

{{-- Nunca se muestra el mensaje de la excepción: el detalle va al log. --}}
@section('codigo', '500')
@section('titulo', 'Algo salió mal')
@section('mensaje', 'Ocurrió un error en el servidor. Ya quedó registrado; intenta de nuevo más tarde.')
