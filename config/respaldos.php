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

    // TG-235: Railway sirve MySQL con un certificado autofirmado y el cliente
    // MariaDB de la imagen (default-mysql-client) verifica el certificado por
    // omisión, así que mysqldump/mysql fallaban con 'self-signed certificate'.
    // Con false la conexión sigue cifrada (TLS) pero no se valida la cadena del
    // certificado. Pon true si el servidor tiene un certificado de confianza.
    'verificar_certificado_ssl' => filter_var(env('RESPALDO_MYSQL_VERIFICAR_CERTIFICADO', false), FILTER_VALIDATE_BOOL),

    // Segundos máximos para el volcado.
    'timeout' => (int) env('RESPALDO_TIMEOUT', 1800),

    // Simulacro de restauración (php artisan respaldo:verificar).
    'verificacion' => [
        // Base APARTE donde se restaura y que se borra al terminar. Nunca puede
        // ser la base principal (DB_DATABASE): el comando se niega.
        'base' => env('RESPALDO_BASE_VERIFICACION', 'footwearpoint_restaurada'),

        // Cliente de MySQL que carga el volcado.
        'mysql' => env('RESPALDO_MYSQL', 'mysql'),

        // Diferencia de filas por tabla (en %) que solo se reporta como aviso:
        // la base principal siguió cambiando después del respaldo.
        'tolerancia_porcentaje' => (float) env('RESPALDO_TOLERANCIA_PORCENTAJE', 10),
    ],
];
