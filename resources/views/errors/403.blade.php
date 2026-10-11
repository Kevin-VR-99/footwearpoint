@extends('errors.layout')

{{-- Los mensajes de 403 están en español: abort(403, '...') del código y los
     que bootstrap/app.php traduce (Spatie y policies). --}}
@section('codigo', '403')
@section('titulo', 'Acceso no permitido')
@section('mensaje', ($exception ?? null)?->getMessage() ?: 'No tienes permiso para ver esta página.')
