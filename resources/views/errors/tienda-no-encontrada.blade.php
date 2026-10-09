{{-- TG-232 (G13): subdominio sin tienda (no existe, no está activa o el nombre no es válido). --}}
@extends('errors.layout')

@section('codigo', '404')
@section('titulo', 'Tienda no encontrada')
@section('mensaje', 'No encontramos una tienda en esta dirección. Revisa que esté bien escrita o busca la distribuidora en el directorio.')
@section('enlace', app(\App\Services\Tienda\EnlaceTienda::class)->marketplace())
@section('textoEnlace', 'Ver el directorio de distribuidoras')
