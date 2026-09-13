<?php

namespace App\Services\Pedido;

use App\Models\Auditoria;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrarPagoPedidoAction
{
    /**
     * Registra un pago de pedido (anticipo o saldo) en la tabla pagos.
     * El pedido no guarda columnas de anticipo/saldo: se calculan sumando pagos aplicados.
     */
    public function ejecutar(Pedido $pedido, array $datos): Pedido
    {
        $tipo = $datos['tipo']; // anticipo | saldo_pedido
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

        $cerrados = ['rechazado', 'descartado', 'no_surtido', 'vencido_recoleccion'];
        if (in_array($pedido->estado, $cerrados, true)) {
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
                'distribuidora_id'         => $pedido->distribuidora_id,
                'pedido_id'                => $pedido->id,
                'venta_directa_id'         => null,
                'folio'                    => $this->generarFolio((int) $pedido->distribuidora_id),
                'tipo'                     => $tipo,
                'direccion'                => 'entrada',
                'metodo'                   => $metodo,
                'monto'                    => $monto,
                'fecha_pago'               => now(),
                'referencia'               => $datos['referencia'] ?? null,
                'proveedor_pago'           => null,
                'referencia_externa'       => null,
                'estado'                   => 'aplicado',
                'registrado_por_staff_id'  => $staffId,
            ]);

            Auditoria::create([
                'usuario_id'       => Auth::id(),
                'distribuidora_id' => $pedido->distribuidora_id,
                'accion'           => 'pago.'.$tipo,
                'entidad_tipo'     => 'pago',
                'entidad_id'       => $pago->id,
                'datos_previos'    => null,
                'datos_nuevos'     => [
                    'pedido_id' => $pedido->id,
                    'folio'     => $pago->folio,
                    'tipo'      => $tipo,
                    'monto'     => $monto,
                    'metodo'    => $metodo,
                ],
                'ip_origen'        => request()?->ip(),
            ]);

            return $pedido->fresh([
                'clienteDirecto',
                'revendedorAfiliacion.revendedor',
                'detalle',
                'pagos',
            ]);
        });
    }

    /** @return array{pagado: float, saldo: float, anticipo_requerido: float, anticipo_pagado: float, anticipo_pendiente: float} */
    public function resumen(Pedido $pedido): array
    {
        $pedido->loadMissing('detalle', 'pagos');

        $entradas = $pedido->pagos
            ->where('estado', 'aplicado')
            ->where('direccion', 'entrada');

        $pagado = round((float) $entradas->sum('monto'), 2);
        $total = round((float) $pedido->total, 2);
        $saldo = round(max(0, $total - $pagado), 2);

        $anticipoRequerido = round((float) $pedido->detalle->sum('anticipo_requerido'), 2);
        $anticipoPagado = round((float) $entradas->where('tipo', 'anticipo')->sum('monto'), 2);
        $anticipoPendiente = round(max(0, $anticipoRequerido - $anticipoPagado), 2);

        return [
            'pagado'              => $pagado,
            'saldo'               => $saldo,
            'anticipo_requerido'  => $anticipoRequerido,
            'anticipo_pagado'     => $anticipoPagado,
            'anticipo_pendiente'  => $anticipoPendiente,
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