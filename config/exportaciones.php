<?php

/*
| Exportaciones en cola (reportes y listados grandes).
| - disco: PRIVADO. En local y pruebas 'local' (storage/app/private); en
|   Railway EXPORTACIONES_DISK=catalogos (bucket privado de R2 que ya existe),
|   los archivos quedan bajo exportaciones/{distribuidora_id}/.
| - Por debajo de los umbrales la descarga sigue siendo inmediata.
*/
return [
    'disco' => env('EXPORTACIONES_DISK', 'local'),

    // Listado de pedidos: más filas que esto se manda a la cola.
    'umbral_listado' => (int) env('EXPORTACIONES_UMBRAL_LISTADO', 2000),

    // Reporte de la distribuidora: pedidos + ventas directas del periodo.
    'umbral_reporte' => (int) env('EXPORTACIONES_UMBRAL_REPORTE', 1500),

    // Cuánto vive el archivo y cuánto dura cada enlace firmado.
    'horas_vigencia' => (int) env('EXPORTACIONES_HORAS_VIGENCIA', 24),
    'minutos_enlace' => (int) env('EXPORTACIONES_MINUTOS_ENLACE', 30),
];
