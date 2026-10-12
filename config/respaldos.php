<?php

/*
| TG-235 (G17) — Respaldos programados de la base de datos.
| Ver App\Services\Respaldo\RespaldarBaseDeDatosAction y docs/respaldos.md.
*/

return [
    // Disco (config/filesystems.php) donde se guardan: bucket privado.
    'disco' => 'respaldos',

    // Carpeta dentro del bucket.
    'carpeta' => 'respaldos',

    // Días que se conservan. 0 = no se borra ninguno.
    'retencion_dias' => (int) env('RESPALDO_RETENCION_DIAS', 30),

    // Programa que hace el volcado (debe estar en el PATH del servicio cron).
    'mysqldump' => env('RESPALDO_MYSQLDUMP', 'mysqldump'),

    // Opciones extra, separadas por espacios (p. ej. --set-gtid-purged=OFF).
    'opciones_extra' => env('RESPALDO_MYSQLDUMP_OPCIONES', ''),

    // Segundos máximos para el volcado.
    'timeout' => (int) env('RESPALDO_TIMEOUT', 1800),
];
