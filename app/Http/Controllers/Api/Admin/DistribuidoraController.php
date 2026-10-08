<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambioEstadoDistribuidora;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
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
        $cambio = $rechazar->ejecutar(
            Distribuidora::findOrFail($id),
            $request->validated('motivo_rechazo'),
        );

        $distribuidora = $cambio->distribuidora;

        return $this->respuestaCambio($cambio, [
            'id'               => $distribuidora->id,
            'nombre_comercial' => $distribuidora->nombre_comercial,
            'estado'           => $distribuidora->estado,
            'motivo_rechazo'   => $distribuidora->motivo_rechazo,
        ], 'Distribuidora rechazada correctamente.');
    }

    public function aprobar(AprobarDistribuidoraAction $aprobar, int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        try {
            $cambio = $aprobar->ejecutar($distribuidora);
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

        return $this->respuestaCambio($cambio, $cambio->distribuidora, 'Distribuidora aprobada correctamente.');
    }

    /**
     * TG-196 (G5) — Suspende una distribuidora activa. Conserva todos sus
     * datos; mientras siga suspendida no puede operar. Si no está activa, la
     * acción lanza OperacionInvalidaException (422 con su mensaje).
     */
    public function suspender(SuspenderDistribuidoraAction $suspender, int $id)
    {
        $cambio = $suspender->ejecutar(Distribuidora::findOrFail($id));

        return $this->respuestaCambio($cambio, $cambio->distribuidora, 'Distribuidora suspendida correctamente.');
    }

    /** TG-196 (G5) — Reactiva una distribuidora suspendida. */
    public function reactivar(ReactivarDistribuidoraAction $reactivar, int $id)
    {
        $cambio = $reactivar->ejecutar(Distribuidora::findOrFail($id));

        return $this->respuestaCambio($cambio, $cambio->distribuidora, 'Distribuidora reactivada correctamente.');
    }

    /**
     * TG-196 (G5) — Respuesta de un cambio de estado. Dice si se avisó a la
     * distribuidora por correo; si no, el cambio igual quedó hecho.
     */
    private function respuestaCambio(CambioEstadoDistribuidora $cambio, mixed $data, string $mensaje)
    {
        if (! $cambio->avisoEnviado) {
            $mensaje = rtrim($mensaje, '.') . ', pero no se pudo enviar el aviso por correo a la distribuidora.';
        }

        return response()->json([
            'data'          => $data,
            'message'       => $mensaje,
            'aviso_enviado' => $cambio->avisoEnviado,
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
