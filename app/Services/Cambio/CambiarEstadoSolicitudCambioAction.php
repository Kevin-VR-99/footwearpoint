<?php

namespace App\Services\Cambio;

use App\Exceptions\OperacionInvalidaException;
use App\Models\SolicitudCambio;
use App\Models\SolicitudCambioHistorial;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Services\Notificacion\NotificarEstadoCambioAction;
use App\Services\Vale\EmitirValeAction;
use App\Support\ContextoOperativo;
use App\Support\MensajeError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mueve una solicitud de cambio por su flujo (SolicitudCambio::TRANSICIONES),
 * deja el paso en el historial y en la bitácora, y le avisa al cliente
 * (bandeja de la app, push y correo). Al pasar a «vale emitido» emite el vale
 * por el importe de la línea (o el monto indicado, nunca mayor).
 */
class CambiarEstadoSolicitudCambioAction
{
    public function __construct(
        private PermisoModuloService $permisos,
        private RegistrarAuditoriaAction $auditoria,
        private EmitirValeAction $emitirVale,
    ) {
    }

    public function ejecutar(int $solicitudId, string $nuevoEstado, ?string $nota = null, ?float $montoVale = null): SolicitudCambio
    {
        if (! $this->permisos->puede(auth()->user(), PermisoModuloService::CAMBIOS_RESOLVER)) {
            throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        }

        $nota = $nota !== null && trim($nota) !== '' ? mb_substr(trim($nota), 0, 500) : null;

        if ($nuevoEstado === 'rechazada' && $nota === null) {
            throw new OperacionInvalidaException('Escribe el motivo del rechazo: el cliente lo va a ver.', 422);
        }

        $solicitud = DB::transaction(function () use ($solicitudId, $nuevoEstado, $nota, $montoVale) {
            $solicitud = SolicitudCambio::query()->with(['pedidoDetalle', 'ventaDetalle'])->lockForUpdate()->find($solicitudId);

            if ($solicitud === null) {
                throw new OperacionInvalidaException('No se encontró esa solicitud de cambio.', 404);
            }

            $antes = $solicitud->estado;

            if (! in_array($nuevoEstado, $solicitud->siguientesEstados(), true)) {
                throw new OperacionInvalidaException(sprintf(
                    'Una solicitud %s no puede pasar a %s.',
                    mb_strtolower(SolicitudCambio::etiqueta($antes)),
                    mb_strtolower(SolicitudCambio::etiqueta($nuevoEstado)),
                ), 409);
            }

            if ($nuevoEstado === 'vale_emitido') {
                $solicitud->vale_generado_id = $this->emitirVale($solicitud, $montoVale)->id;
            }

            $solicitud->estado = $nuevoEstado;
            if ($nota !== null && in_array($nuevoEstado, ['rechazada', 'cerrada', 'vale_emitido', 'aceptada_fabrica'], true)) {
                $solicitud->resolucion = $nota;
            }
            $solicitud->save();

            SolicitudCambioHistorial::create([
                'distribuidora_id'    => $solicitud->distribuidora_id,
                'solicitud_cambio_id' => $solicitud->id,
                'estado_anterior'     => $antes,
                'estado_nuevo'        => $nuevoEstado,
                'nota'                => $nota,
                'staff_id'            => app(ContextoOperativo::class)->staff()?->id,
            ]);

            $this->auditoria->ejecutar('cambio.estado_cambiado', 'solicitud_cambio', (int) $solicitud->id,
                ['estado' => $antes], array_filter(['estado' => $nuevoEstado, 'nota' => $nota, 'vale_id' => $solicitud->vale_generado_id]));

            return $solicitud;
        });

        DB::afterCommit(function () use ($solicitud) {
            try {
                app(NotificarEstadoCambioAction::class)->ejecutar($solicitud->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'pedidoDetalle', 'ventaDetalle']));
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar el cambio de estado de la solicitud.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);
            }
        });

        return $solicitud->fresh(['historial']);
    }

    private function emitirVale(SolicitudCambio $solicitud, ?float $monto)
    {
        $linea = $solicitud->pedidoDetalle ?? $solicitud->ventaDetalle;
        $tope = round((float) ($linea?->subtotal ?? 0), 2);
        $monto = $monto === null ? $tope : round($monto, 2);

        if ($monto <= 0) {
            throw new OperacionInvalidaException('El vale debe ser mayor a cero.', 422);
        }
        if ($monto > $tope) {
            throw new OperacionInvalidaException(sprintf('El vale no puede ser mayor que lo pagado por la línea ($%s).', number_format($tope, 2)), 422);
        }

        return $this->emitirVale->ejecutar([
            'propietario_tipo' => $solicitud->cliente_directo_id ? 'cliente_directo' : 'revendedor',
            'propietario_id'   => $solicitud->cliente_directo_id ?? $solicitud->revendedor_distribuidora_id,
            'monto_original'   => $monto,
            'motivo'           => 'Cambio de producto #'.$solicitud->id,
        ]);
    }
}
