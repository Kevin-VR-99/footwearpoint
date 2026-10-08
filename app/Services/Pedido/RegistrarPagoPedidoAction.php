<?php

namespace App\Services\Pedido;

use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\FolioPago;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrarPagoPedidoAction
{
    /** Estados en los que un pedido ya no recibe pagos ni vales. */
    public const ESTADOS_CERRADOS = ['rechazado', 'descartado', 'no_surtido', 'vencido_recoleccion'];

    public function __construct(
        protected RegistrarAuditoriaAction $auditoria
    ) {}

    public function ejecutar(Pedido $pedido, array $datos): Pedido
    {
        $tipo = $datos['tipo'];
        $metodo = $datos['metodo'];
        $monto = round((float) $datos['monto'], 2);

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => ['El monto debe ser mayor a cero.'],
            ]);
        }

        if ($pedido->estado === 'borrador') {
            throw ValidationException::withMessages([
                'pedido' => ['Envía el pedido antes de registrar un pago.'],
            ]);
        }

        if (in_array($pedido->estado, self::ESTADOS_CERRADOS, true)) {
            throw ValidationException::withMessages([
                'pedido' => ['Este pedido ya no admite pagos (estado: '.$pedido->estado.').'],
            ]);
        }

        if ($tipo === 'anticipo' && $pedido->tipo !== 'cliente_directo') {
            throw ValidationException::withMessages([
                'tipo' => ['El anticipo de E8-02 aplica a pedidos de cliente directo. El revendedor usa cobro de saldo o total mayorista.'],
            ]);
        }

        $staffId = $this->staffIdActual();
        if ($staffId === null) {
            throw ValidationException::withMessages([
                'pedido' => ['Solo el personal de la distribuidora puede registrar un pago en mostrador.'],
            ]);
        }

        $resumen = $this->resumen($pedido);

        if ($monto - $resumen['saldo'] > 0.009) {
            throw ValidationException::withMessages([
                'monto' => ['El monto supera el saldo pendiente ($'.number_format($resumen['saldo'], 2).').'],
            ]);
        }

        if ($tipo === 'anticipo' && $resumen['anticipo_pendiente'] <= 0) {
            throw ValidationException::withMessages([
                'tipo' => ['Este pedido ya cubrió el anticipo requerido.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $tipo, $metodo, $monto, $datos, $staffId) {
            $pago = Pago::create([
                'distribuidora_id'        => $pedido->distribuidora_id,
                'pedido_id'               => $pedido->id,
                'venta_directa_id'        => null,
                'folio'                   => $this->generarFolio((int) $pedido->distribuidora_id),
                'tipo'                    => $tipo,
                'direccion'               => 'entrada',
                'metodo'                  => $metodo,
                'monto'                   => $monto,
                'fecha_pago'              => now(),
                'referencia'              => $datos['referencia'] ?? null,
                'proveedor_pago'          => null,
                'referencia_externa'      => null,
                'estado'                  => 'aplicado',
                'registrado_por_staff_id' => $staffId,
            ]);

            $this->auditoria->ejecutar(
                'pago.'.$tipo,
                'pago',
                $pago->id,
                null,
                [
                    'pedido_id' => $pedido->id,
                    'folio'     => $pago->folio,
                    'tipo'      => $tipo,
                    'monto'     => $monto,
                    'metodo'    => $metodo,
                ]
            );

            return $pedido->fresh([
                'clienteDirecto',
                'revendedorAfiliacion.revendedor',
                'detalle',
                'pagos',
            ]);
        });
    }

    public function resumen(Pedido $pedido): array
    {
        $pedido->loadMissing('detalle', 'pagos', 'aplicacionesVale');

        $entradas = $pedido->pagos
            ->where('estado', 'aplicado')
            ->where('direccion', 'entrada');

        // Lo aplicado con vales cuenta como pago (TG-167). Antes el vale
        // perdía su saldo y el pedido seguía debiendo lo mismo.
        $conVales = round((float) $pedido->aplicacionesVale->sum('monto'), 2);

        $pagado = round((float) $entradas->sum('monto') + $conVales, 2);
        $total = round((float) $pedido->total, 2);
        $saldo = round(max(0, $total - $pagado), 2);

        // El dinero que entra cubre PRIMERO el anticipo, sin importar cómo se
        // marcó el pago (TG-275). Antes solo contaban los pagos de tipo
        // "anticipo" y los vales, así que un cobro marcado como saldo en
        // mostrador dejaba el pedido pagado pero sin poder ir a fábrica (K8).
        //
        // Los vales siguen contando, igual que antes (TG-167): son dinero que
        // la distribuidora ya tiene del cliente y van dentro de lo pagado.
        $anticipoRequerido = round((float) $pedido->detalle->sum('anticipo_requerido'), 2);
        $anticipoPagado = round(min($anticipoRequerido, $pagado), 2);
        $anticipoPendiente = round(max(0, $anticipoRequerido - $anticipoPagado), 2);

        return [
            'pagado'             => $pagado,
            'pagado_con_vales'   => $conVales,
            'saldo'              => $saldo,
            'anticipo_requerido' => $anticipoRequerido,
            'anticipo_pagado'    => $anticipoPagado,
            'anticipo_pendiente' => $anticipoPendiente,
        ];
    }

    /** TG-226: la numeración vive en App\Support\FolioPago (la comparte el anticipo con Mercado Pago). */
    protected function generarFolio(int $distribuidoraId): string
    {
        return FolioPago::siguiente($distribuidoraId);
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