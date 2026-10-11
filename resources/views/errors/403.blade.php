@extends('errors.layout')

{{-- TG-264: solo se muestran los mensajes propios de la app (App\Support\MensajeProhibido);
     cualquier otro (p. ej. los de Laravel en inglés) cambia por el genérico. --}}
@section('codigo', '403')
@section('titulo', 'Acceso no permitido')
@section('mensaje', \App\Support\MensajeProhibido::para($exception ?? null))
