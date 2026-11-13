<?php

namespace App\Services\Actividad;

use App\Models\ActividadDiaria;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TG-406 (A43) — Deja constancia de que alguien usó la plataforma hoy.
 *
 * Se llama en cada petición con sesión, así que tiene que ser barato y, sobre
 * todo, NO puede tumbar la petición: si el registro falla, la persona tiene
 * que poder seguir trabajando. Por eso todo va dentro de un try y lo peor que
 * pasa es que se pierda una marca.
 */
class RegistrarActividad
{
    public function __construct(private ?int $usuarioId = null) {}

    /**
     * Marca el día de hoy para esa persona y ese canal.
     *
     * Devuelve true si se creó la marca, false si ya estaba o si no se pudo.
     * Repetirlo el mismo día no hace nada: lo garantiza el índice único de la
     * tabla, no una consulta previa, que en dos peticiones a la vez dejaría
     * pasar las dos.
     */
    public function marcar(int $usuarioId, string $canal): bool
    {
        if (! in_array($canal, ActividadDiaria::CANALES, true)) {
            return false;
        }

        $fecha = now(ActividadDiaria::ZONA)->toDateString();

        try {
            // insertOrIgnore y no firstOrCreate: una sola consulta, y el
            // choque contra el indice unico lo resuelve la base en vez de
            // reventar cuando llegan dos peticiones al mismo tiempo.
            return DB::table('actividad_diaria')->insertOrIgnore([
                'usuario_id' => $usuarioId,
                'fecha' => $fecha,
                'canal' => $canal,
            ]) > 0;
        } catch (Throwable $e) {
            // Que no se caiga la peticion por esto. Se pierde una marca.
            report($e);

            return false;
        }
    }
}
