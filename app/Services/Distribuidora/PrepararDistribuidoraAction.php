<?php

namespace App\Services\Distribuidora;

use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Support\Tenant;

/**
 * TG-194 (G2) — Lo que una distribuidora necesita para operar: sucursal
 * principal, configuración, ciclo de compra y su suscripción inicial.
 *
 * Antes este código estaba copiado en el alta del panel, la aprobación del
 * panel y la aprobación de la API. Ahora lo usan CrearDistribuidoraAction y
 * AprobarDistribuidoraAction.
 *
 * Todo corre con Tenant::forzar(): el filtro por distribuidora apunta a la
 * distribuidora que se está preparando, sin tener que quitarlo.
 */
class PrepararDistribuidoraAction
{
    /** El plan Básico; si no existe, el primero que haya. */
    public function planPorDefecto(): ?PlanSuscripcion
    {
        return PlanSuscripcion::where('nombre', 'Básico')->first()
            ?? PlanSuscripcion::where('nombre', 'like', '%ásico%')->first()
            ?? PlanSuscripcion::first();
    }

    /** Sucursal principal, configuración y ciclo. Si ya existen, no se tocan. */
    public function datosIniciales(Distribuidora $distribuidora): void
    {
        Tenant::forzar($distribuidora->id, function () use ($distribuidora) {
            Sucursal::firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'es_principal'     => true,
                ],
                [
                    'nombre'    => 'Sucursal Principal',
                    'direccion' => $distribuidora->direccion_publica ?? 'Sin dirección',
                    'telefono'  => $distribuidora->telefono_publico,
                    'activa'    => true,
                ]
            );

            ConfiguracionDistribuidora::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'anticipo_por_producto'    => 100.00,
                    'dias_solicitud_cambio'    => 12,
                    'dias_gestion_devolucion'  => 20,
                    'dias_vigencia_vale'       => 90,
                    'dias_maximos_recoleccion' => 5,
                    'moneda'                   => 'MXN',
                    'zona_horaria'             => 'America/Mexico_City',
                ]
            );

            ConfiguracionCiclo::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'dia_cierre'             => 5,
                    'hora_cierre'            => '18:00:00',
                    'dia_solicitud_fabrica'  => 5,
                    'dias_estimados_llegada' => 5,
                    'activa'                 => true,
                ]
            );
        });
    }

    /** Un mes del plan, con los precios del plan congelados en la suscripción. */
    public function suscripcionInicial(Distribuidora $distribuidora, PlanSuscripcion $plan): Suscripcion
    {
        return Tenant::forzar($distribuidora->id, fn () => Suscripcion::create([
            'distribuidora_id'              => $distribuidora->id,
            'plan_id'                       => $plan->id,
            'fecha_inicio'                  => now()->toDateString(),
            'fecha_fin'                     => now()->addMonth()->toDateString(),
            'estado'                        => 'activa',
            'precio_base_contratado'        => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas'  => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas'      => 0,
            'renovacion_automatica'         => true,
        ]));
    }
}
