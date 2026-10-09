<?php

namespace App\Services\Suscripcion;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * E2-04: el admin general le asigna (o cambia) el plan a una distribuidora.
 *
 * TG-224 (G3): antes esta lógica vivía copiada en el controlador de la API
 * y en el componente admin.distribuidoras-index. Ahora los dos usan esta
 * acción, y la consulta corre dentro de Tenant::forzar con la distribuidora
 * destino en lugar de quitar el scope de tenant.
 *
 * Cierra la suscripción activa anterior (si la hay) y crea la nueva con los
 * precios del plan congelados al momento de contratar, todo en una sola
 * transacción.
 */
class AsignarSuscripcionAction
{
    public const ESTADOS_PERMITIDOS = ['activa', 'suspendida'];

    public const MENSAJE_ESTADO = 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.';

    public const MENSAJE_PLAN_INACTIVO = 'El plan seleccionado no está activo.';

    public function ejecutar(
        Distribuidora $distribuidora,
        PlanSuscripcion $plan,
        int $meses = 1,
        int $lineasExtra = 0,
        bool $renovacionAutomatica = true,
    ): Suscripcion {
        if (! in_array($distribuidora->estado, self::ESTADOS_PERMITIDOS, true)) {
            throw new OperacionInvalidaException(self::MENSAJE_ESTADO, 422);
        }

        if (! $plan->activo) {
            throw new OperacionInvalidaException(self::MENSAJE_PLAN_INACTIVO, 422);
        }

        return Tenant::forzar((int) $distribuidora->id, fn () => DB::transaction(function () use ($distribuidora, $plan, $meses, $lineasExtra, $renovacionAutomatica) {
            Suscripcion::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->where('estado', 'activa')
                ->update([
                    'estado'    => 'cancelada',
                    'fecha_fin' => now()->toDateString(),
                ]);

            return Suscripcion::create([
                'distribuidora_id'              => $distribuidora->id,
                'plan_id'                       => $plan->id,
                'fecha_inicio'                  => now()->toDateString(),
                'fecha_fin'                     => now()->addMonths($meses)->toDateString(),
                'estado'                        => 'activa',
                'precio_base_contratado'        => $plan->precio_base_mensual,
                'lineas_incluidas_contratadas'  => $plan->lineas_incluidas,
                'precio_linea_extra_contratado' => $plan->precio_linea_extra,
                'lineas_extra_contratadas'      => $lineasExtra,
                'renovacion_automatica'         => $renovacionAutomatica,
            ]);
        }));
    }
}
