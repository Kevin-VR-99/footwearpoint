<?php

namespace App\Console\Commands;

use App\Services\Actividad\ConsolidarActividad;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * php artisan actividad:limpiar — TG-406 (A43).
 *
 * Consolida el detalle en totales por mes y borra lo vencido: 90 días de
 * detalle, 24 meses de totales. Va como cron diario en Railway, igual que
 * respaldo:ejecutar y vales:avisar.
 *
 * SE PUEDE REPETIR. Correrlo dos veces el mismo día deja la base igual que
 * correrlo una: los totales se actualizan en vez de insertarse otra vez, y el
 * borrado solo quita lo que ya venció. Eso importa porque un cron que se
 * reintenta es lo normal, no la excepción.
 *
 * SI FALLA, AVISA. Queda en el log, se manda un correo y NO se registra en
 * auditoría: el registro de auditoría es justo lo que Métricas mira para
 * saber cuándo fue la última limpieza buena, así que apuntar una fallida haría
 * creer que todo está al día.
 */
class LimpiarActividad extends Command
{
    /** La acción con la que queda en auditoría; Métricas busca esta misma. */
    public const ACCION_AUDITORIA = 'actividad.limpieza';

    protected $signature = 'actividad:limpiar';

    protected $description = 'Consolida la actividad en totales por mes y borra el detalle vencido';

    public function handle(ConsolidarActividad $consolidar, RegistrarAuditoriaAction $auditoria): int
    {
        try {
            $r = $consolidar->ejecutar();
        } catch (Throwable $e) {
            // Sin el mensaje crudo: podría traer datos de la base.
            Log::error('Falló la limpieza de actividad.', ['error' => class_basename($e)]);
            $this->avisarPorCorreo($e);
            $this->error('La limpieza falló ('.class_basename($e).'). Revisa el log.');

            return self::FAILURE;
        }

        $auditoria->ejecutar(
            accion: self::ACCION_AUDITORIA,
            entidadTipo: 'actividad',
            entidadId: null,
            datosNuevos: $r,
        );

        $this->info(
            "Meses consolidados: {$r['meses']}. "
            ."Detalle borrado: {$r['diarias_borradas']}. "
            ."Totales borrados: {$r['mensuales_borradas']}."
        );

        return self::SUCCESS;
    }

    /**
     * El aviso de que falló.
     *
     * Dentro de su propio try: si el correo tampoco sale, eso no puede
     * convertir un fallo en una excepción sin manejar que esconda el
     * original.
     */
    private function avisarPorCorreo(Throwable $e): void
    {
        $para = config('footwearpoint.redes.correo');

        if (! $para) {
            return;
        }

        try {
            Mail::raw(
                "La limpieza diaria del registro de actividad falló.\n\n"
                ."Error: ".class_basename($e)."\n"
                ."Fecha: ".now('America/Mexico_City')->format('d/m/Y H:i')." (CDMX)\n\n"
                ."Mientras no corra, el detalle vencido se queda guardado más de los 90 días "
                ."que dice la política. Revisa el log del servicio en Railway.",
                fn ($m) => $m->to($para)->subject('FootwearPoint: falló la limpieza de actividad')
            );
        } catch (Throwable $correo) {
            Log::error('Tampoco se pudo avisar por correo de la limpieza fallida.', [
                'error' => class_basename($correo),
            ]);
        }
    }
}
