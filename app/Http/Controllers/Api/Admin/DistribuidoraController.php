<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambiarVisibilidadMarketplaceAction;
use App\Services\Distribuidora\CambioEstadoDistribuidora;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Services\Suscripcion\AsignarSuscripcionAction;
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

    public function asignarSuscripcion(AsignarSuscripcionRequest $request, int $id, AsignarSuscripcionAction $asignar)
    {
        // TG-224 (G3): la regla vive en AsignarSuscripcionAction; si la
        // distribuidora o el plan no son válidos lanza OperacionInvalidaException
        // (422 con el mismo mensaje de antes).
        $suscripcion = $asignar->ejecutar(
            Distribuidora::findOrFail($id),
            PlanSuscripcion::findOrFail($request->plan_id),
            (int) $request->input('meses', 1),
            (int) $request->input('lineas_extra_contratadas', 0),
            $request->boolean('renovacion_automatica', true),
        );

        return response()->json([
            'data'    => $suscripcion->load('plan'),
            'message' => 'Suscripción asignada correctamente.',
        ], 201);
    }

    /**
     * Mostrar u ocultar una distribuidora en el marketplace (E2-05). Si se
     * quiere mostrar una que no está activa, la acción lanza
     * OperacionInvalidaException (422 con su mensaje). TG-197.
     */
    public function marketplaceConfig(MarketplaceConfigRequest $request, CambiarVisibilidadMarketplaceAction $visibilidad)
    {
        $distribuidora = $visibilidad->ejecutar(
            Distribuidora::findOrFail($request->distribuidora_id),
            $request->boolean('marketplace_visible'),
        );

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
