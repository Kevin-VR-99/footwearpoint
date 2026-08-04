<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistribuidoraController extends Controller
{
    public function index(Request $request)
    {
        $query = Distribuidora::query()->orderByDesc('id');

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $distribuidoras = $query->get([
            'id',
            'nombre_comercial',
            'razon_social',
            'rfc',
            'slug',
            'estado',
            'fecha_solicitud',
            'fecha_aprobacion',
            'marketplace_visible',
        ]);

        return response()->json([
            'data' => $distribuidoras,
        ]);
    }

    public function aprobar(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'pendiente') {
            return response()->json([
                'message' => 'Solo se pueden aprobar distribuidoras en estado pendiente.',
            ], 422);
        }

        $plan = PlanSuscripcion::query()
            ->where('nombre', 'Básico')
            ->orWhere('nombre', 'like', '%ásico%')
            ->first()
            ?? PlanSuscripcion::first();

        if (!$plan) {
            return response()->json([
                'message' => 'No hay planes de suscripción configurados.',
            ], 500);
        }

        DB::transaction(function () use ($distribuidora, $plan) {
            $distribuidora->update([
                'estado'           => 'activa',
                'fecha_aprobacion' => now(),
            ]);

            Sucursal::withoutGlobalScopes()->firstOrCreate(
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

            ConfiguracionDistribuidora::withoutGlobalScopes()->firstOrCreate(
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

            ConfiguracionCiclo::withoutGlobalScopes()->firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'dia_cierre'             => 5,
                    'hora_cierre'            => '18:00:00',
                    'dia_solicitud_fabrica'  => 5,
                    'dias_estimados_llegada' => 5,
                    'activa'                 => true,
                ]
            );

            Suscripcion::withoutGlobalScopes()->create([
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
            ]);
        });

        return response()->json([
            'data'    => $distribuidora->fresh(),
            'message' => 'Distribuidora aprobada correctamente.',
        ]);
    }

    public function suspender(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'activa') {
            return response()->json([
                'message' => 'Solo se pueden suspender distribuidoras activas.',
            ], 422);
        }

        $distribuidora->update(['estado' => 'suspendida']);

        return response()->json([
            'data'    => $distribuidora,
            'message' => 'Distribuidora suspendida correctamente.',
        ]);
    }

    public function reactivar(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'suspendida') {
            return response()->json([
                'message' => 'Solo se pueden reactivar distribuidoras suspendidas.',
            ], 422);
        }

        $distribuidora->update(['estado' => 'activa']);

        return response()->json([
            'data'    => $distribuidora,
            'message' => 'Distribuidora reactivada correctamente.',
        ]);
    }
}
