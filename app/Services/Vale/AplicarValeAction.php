<?php

namespace App\Services\Vale;

use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\VentaDirecta;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AplicarValeAction
{
    /**
     * Aplica saldo de un vale a un pedido o a una venta directa.
     * Body: monto, y uno de pedido_id | venta_directa_id.
     */
    public function ejecutar(Vale $vale, array $datos): Vale
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        if ($vale->estado !== 'activo') {
            throw ValidationException::withMessages([
                'vale' => ['Solo se pueden aplicar vales en estado activo.'],
            ]);
        }

        if ((float) $vale->saldo_actual <= 0) {
            throw ValidationException::withMessages([
                'vale' => ['El vale no tiene saldo disponible.'],
            ]);
        }

        if (! empty($vale->fecha_vencimiento) && $vale->fecha_vencimiento->isPast()) {
            throw ValidationException::withMessages([
                'vale' => ['El vale está vencido.'],
            ]);
        }

        $pedidoId = $datos['pedido_id'] ?? null;
        $ventaId = $datos['venta_directa_id'] ?? null;

        if (($pedidoId && $ventaId) || (! $pedidoId && ! $ventaId)) {
            throw ValidationException::withMessages([
                'destino' => ['Indica exactamente uno: pedido_id o venta_directa_id.'],
            ]);
        }

        $montoSolicitado = round((float) $datos['monto'], 2);
        if ($montoSolicitado <= 0) {
            throw ValidationException::withMessages([
                'monto' => ['El monto a aplicar debe ser mayor a 0.'],
            ]);
        }

        if ($pedidoId) {
            $pedido = Pedido::query()->find($pedidoId);
            if (! $pedido) {
                throw ValidationException::withMessages([
                    'pedido_id' => ['El pedido no existe en esta distribuidora.'],
                ]);
            }
            $this->assertMismoPropietario($vale, $pedido->cliente_directo_id, $pedido->revendedor_distribuidora_id);
            $this->assertPedidoAdmitePago($pedido);
        } else {
            $venta = VentaDirecta::query()->find($ventaId);
            if (! $venta) {
                throw ValidationException::withMessages([
                    'venta_directa_id' => ['La venta directa no existe en esta distribuidora.'],
                ]);
            }
            // Venta directa suele ser a cliente; si el modelo no tiene cliente, solo validamos existencia.
            if (isset($venta->cliente_directo_id) && $venta->cliente_directo_id) {
                $this->assertMismoPropietario($vale, $venta->cliente_directo_id, null);
            }
        }

        // Puede quedar en null a propósito (TG-138): el cliente o revendedor
        // aplica su propio vale desde la app, sin empleado de por medio.
        // De que el vale sea suyo ya se encarga assertMismoPropietario().
        //
        // Emitir un vale es otra cosa y sigue siendo solo de empleados: ver
        // EmitirValeAction, que conserva su abort.
        $staffId = $this->staffIdActual();

        return DB::transaction(function () use ($vale, $montoSolicitado, $pedidoId, $ventaId, $staffId) {
            // Se vuelven a leer bloqueados: si dos personas aplican al mismo
            // tiempo, la segunda ve los saldos ya descontados.
            $vale = Vale::query()->lockForUpdate()->findOrFail($vale->id);
            $monto = min($montoSolicitado, (float) $vale->saldo_actual);

            // En un pedido, el vale cuenta como pago (TG-167): solo se aplica
            // hasta lo que falta por pagar y el resto se queda en el vale.
            if ($pedidoId) {
                $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedidoId);
                $saldoPedido = app(RegistrarPagoPedidoAction::class)->resumen($pedido)['saldo'];

                if ($saldoPedido <= 0) {
                    throw ValidationException::withMessages([
                        'pedido_id' => ['Este pedido ya está pagado.'],
                    ]);
                }

                $monto = min($monto, $saldoPedido);
            }

            $monto = round($monto, 2);
            if ($monto <= 0) {
                throw ValidationException::withMessages([
                    'vale' => ['El vale no tiene saldo disponible.'],
                ]);
            }

            $saldoAnterior = (float) $vale->saldo_actual;
            $saldoPosterior = round($saldoAnterior - $monto, 2);

            $vale->saldo_actual = $saldoPosterior;
            if ($saldoPosterior <= 0) {
                $vale->saldo_actual = 0;
                $vale->estado = 'agotado';
                $saldoPosterior = 0;
            }
            $vale->save();

            ValeMovimiento::create([
                'distribuidora_id'         => $vale->distribuidora_id,
                'vale_id'                  => $vale->id,
                'tipo'                     => 'aplicacion',
                'monto'                    => $monto,
                'saldo_anterior'           => $saldoAnterior,
                'saldo_posterior'          => $saldoPosterior,
                'pedido_id'                => $pedidoId,
                'venta_directa_id'         => $ventaId,
                'registrado_por_staff_id'  => $staffId,
                'observaciones'            => 'Aplicación de vale',
            ]);

            return $vale->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'movimientos']);
        });
    }

    protected function assertMismoPropietario(Vale $vale, ?int $clienteId, ?int $revendedorAfiliacionId): void
    {
        $ok = false;

        if ($vale->cliente_directo_id && $clienteId && (int) $vale->cliente_directo_id === (int) $clienteId) {
            $ok = true;
        }

        if ($vale->revendedor_distribuidora_id && $revendedorAfiliacionId
            && (int) $vale->revendedor_distribuidora_id === (int) $revendedorAfiliacionId) {
            $ok = true;
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'vale' => ['El vale no pertenece al mismo propietario que el destino.'],
            ]);
        }
    }

    /** Mismas reglas que un pago en mostrador (RegistrarPagoPedidoAction). */
    protected function assertPedidoAdmitePago(Pedido $pedido): void
    {
        if ($pedido->estado === 'borrador') {
            throw ValidationException::withMessages([
                'pedido_id' => ['Envía el pedido antes de aplicarle un vale.'],
            ]);
        }

        if (in_array($pedido->estado, RegistrarPagoPedidoAction::ESTADOS_CERRADOS, true)) {
            throw ValidationException::withMessages([
                'pedido_id' => ['Este pedido ya no admite pagos (estado: '.$pedido->estado.').'],
            ]);
        }
    }

    protected function staffIdActual(): ?int
    {
        $usuario = Auth::user();
        if (! $usuario) {
            return null;
        }

        return DistribuidoraStaff::where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->value('id');
    }
}