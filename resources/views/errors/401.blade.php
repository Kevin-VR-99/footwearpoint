@extends('errors.layout')

@section('codigo', '401')
@section('titulo', 'Inicia sesión')
@section('mensaje', 'Tu sesión no es válida o expiró. Inicia sesión de nuevo para continuar.')
@section('enlace', Route::has('login') ? route('login') : url('/'))
@section('textoEnlace', 'Iniciar sesión')
