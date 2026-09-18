<?php

namespace App\Services\Pedido;

use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
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

        // El vale también cubre el anticipo (decisión del equipo en TG-167):
        // es dinero que la distribuidora ya tiene del cliente. Solo cuenta
        // hasta lo que faltaba de anticipo.
        $anticipoRequerido = round((float) $pedido->detalle->sum('anticipo_requerido'), 2);
        $anticipoEnPagos = (float) $entradas->where('tipo', 'anticipo')->sum('monto');
        $anticipoConVales = min($conVales, max(0, $anticipoRequerido - $anticipoEnPagos));
        $anticipoPagado = round($anticipoEnPagos + $anticipoConVales, 2);
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

    protected function generarFolio(int $distribuidoraId): string
    {
        $prefijo = 'PAG-'.now()->format('Ymd').'-';

        $ultimo = Pago::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo.'%')
            ->orderByDesc('id')
            ->value('folio');

        $secuencia = 1;
        if ($ultimo && preg_match('/-(\d+)$/', $ultimo, $m)) {
            $secuencia = (int) $m[1] + 1;
        }

        return $prefijo.str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
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