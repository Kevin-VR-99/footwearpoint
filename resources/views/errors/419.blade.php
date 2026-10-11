@extends('errors.layout')

@section('codigo', '419')
@section('titulo', 'La página expiró')
@section('mensaje', 'Pasó mucho tiempo sin actividad. Recarga la página e intenta de nuevo.')
@section('enlace', url()->previous())
@section('textoEnlace', 'Volver')
