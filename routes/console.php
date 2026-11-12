<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Auditoría 21-oct-2026: la limpieza de tokens de Sanctum vencidos
| (`php artisan sanctum:prune-expired --hours=24`) NO se programa aquí: en
| Railway cada comando corre como su propio servicio cron
| (ver docs/railway-produccion.md, "footwearpoint-limpiar-tokens").
|
| El vencimiento de la mensualidad (`php artisan suscripciones:vencer`)
| tampoco se programa aquí. Por omisión solo avisa. Cómo activar la
| suspensión al sexto día: docs/vencimiento-suscripcion.md.
*/
