<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\AsignarSuscripcionRequest;
use App\Http\Requests\Admin\MarketplaceConfigRequest;
use App\Http\Requests\Admin\RechazarDistribuidoraRequest;

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
            'motivo_rechazo',
        ]);

        return response()->json([
            'data' => $distribuidoras,
        ]);
    }

    /**
     * TG-195 (G4) — Los datos de la distribuidora y su administrador, para
     * revisarla antes de aprobarla o rechazarla.
     */
    public function show(DatosSolicitudDistribuidoraAction $datos, int $id)
    {
        return response()->json([
            'data' => $datos->ejecutar(Distribuidora::findOrFail($id)),
        ]);
    }

    /**
     * TG-195 (G4) — Rechaza una distribuidora pendiente con su motivo.
     *
     * Si no está pendiente, la acción lanza OperacionInvalidaException, que
     * responde sola un 422 con su mensaje.
     */
    public function rechazar(RechazarDistribuidoraRequest $request, RechazarDistribuidoraAction $rechazar, int $id)
    {
        $distribuidora = $rechazar->ejecutar(
            Distribuidora::findOrFail($id),
            $request->validated('motivo_rechazo'),
        );

        return response()->json([
            'data' => [
                'id'               => $distribuidora->id,
                'nombre_comercial' => $distribuidora->nombre_comercial,
                'estado'           => $distribuidora->estado,
                'motivo_rechazo'   => $distribuidora->motivo_rechazo,
            ],
            'message' => 'Distribuidora rechazada correctamente.',
        ]);
    }

    public function aprobar(AprobarDistribuidoraAction $aprobar, int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        try {
            $distribuidora = $aprobar->ejecutar($distribuidora);
        } catch (AprobacionDistribuidoraException $e) {
            if ($e->esNoPendiente()) {
                return response()->json([
                    'message' => 'Solo se pueden aprobar distribuidoras en estado pendiente.',
                ], 422);
            }

            return response()->json([
                'message' => 'No hay planes de suscripción configurados.',
            ], 500);
        }

        return response()->json([
            'data'    => $distribuidora,
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

    public function asignarSuscripcion(AsignarSuscripcionRequest $request, int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if (!in_array($distribuidora->estado, ['activa', 'suspendida'])) {
            return response()->json([
                'message' => 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.',
            ], 422);
        }

        $plan = PlanSuscripcion::findOrFail($request->plan_id);

        if (!$plan->activo) {
            return response()->json([
                'message' => 'El plan seleccionado no está activo.',
            ], 422);
        }

        $meses = $request->input('meses', 1);
        $lineasExtra = $request->input('lineas_extra_contratadas', 0);

        // Cerrar suscripción activa anterior (si existe)
        Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'activa')
            ->update([
                'estado'    => 'cancelada',
                'fecha_fin' => now()->toDateString(),
            ]);

        $suscripcion = Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id'              => $distribuidora->id,
            'plan_id'                       => $plan->id,
            'fecha_inicio'                  => now()->toDateString(),
            'fecha_fin'                     => now()->addMonths($meses)->toDateString(),
            'estado'                        => 'activa',
            'precio_base_contratado'        => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas'  => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas'      => $lineasExtra,
            'renovacion_automatica'         => $request->boolean('renovacion_automatica', true),
        ]);

        return response()->json([
            'data'    => $suscripcion->load('plan'),
            'message' => 'Suscripción asignada correctamente.',
        ], 201);
    }

    public function marketplaceConfig(MarketplaceConfigRequest $request)
    {
        $distribuidora = Distribuidora::findOrFail($request->distribuidora_id);

        if ($distribuidora->estado !== 'activa' && $request->boolean('marketplace_visible')) {
            return response()->json([
                'message' => 'Solo distribuidoras activas pueden ser visibles en el marketplace.',
            ], 422);
        }

        $distribuidora->update([
            'marketplace_visible' => $request->boolean('marketplace_visible'),
        ]);

        return response()->json([
            'data' => [
                'id'                   => $distribuidora->id,
                'nombre_comercial'     => $distribuidora->nombre_comercial,
                'estado'               => $distribuidora->estado,
                'marketplace_visible'  => $distribuidora->marketplace_visible,
            ],
            'message' => 'Configuración de marketplace actualizada.',
        ]);
    }
}
