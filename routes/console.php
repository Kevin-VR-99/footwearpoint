<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Auditoría 21-oct-2026: borra los tokens de Sanctum vencidos (los que pasan
| de sanctum.expiration) para que personal_access_tokens no crezca sin fin.
| En Railway no corre schedule:run: las tareas son servicios cron aparte
| (ver docs/railway-produccion.md, "cron footwearpoint-limpiar-tokens").
| Se deja registrado aquí para quien use `php artisan schedule:run` en local.
*/
\Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:30');
