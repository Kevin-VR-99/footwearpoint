<?php

namespace App\Services\VentaDirecta;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\Color;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;
use App\Support\ContextoOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RegistrarVentaDirectaService
{
    public function __construct(
        private ContextoOperativo $contexto,
        private DescuentoStockVentaDirectaService $descuentoStock,
    ) {
    }

    /**
     * @param array<int, array{variante_id:int, producto_campana_id:int, cantidad:int}> $lineas
     * @param string $metodoPago valor del enum pagos.metodo
     */
    public function registrar(array $lineas, string $metodoPago, ?int $clienteDirectoId = null): ResultadoVentaDirecta
    {
        return DB::transaction(function () use ($lineas, $metodoPago, $clienteDirectoId) {
            $distribuidoraId = $this->contexto->distribuidoraId();
            $sucursalId = (int) $this->contexto->sucursalPrincipal()->id;
            $staffId = (int) $this->contexto->staff()->id;

            if ($clienteDirectoId !== null) {
                $this->verificarClienteDelTenant($clienteDirectoId, $distribuidoraId);
            }

            // 1) Resolver precios y BLOQUEAR todo el stock antes de escribir nada.
            //    Si una sola linea no alcanza, la transaccion muere sin dejar rastro.
            $preparadas = [];
            foreach ($lineas as $linea) {
                $preparadas[] = $this->prepararLinea($linea, $distribuidoraId, $sucursalId);
            }

            // 2) Totales. OJO: estos importes YA INCLUYEN IVA (confirmado por el equipo).
            //    chk_venta_totales exige total = subtotal - descuento, sin IVA en la ecuacion.
            $subtotal = round(array_sum(array_column($preparadas, 'subtotal')), 2);
            $descuento = 0.00; // decision confirmada: no se construyen descuentos este sprint
            $total = round($subtotal - $descuento, 2);

            // pagos.chk_pago_monto exige monto > 0: sin esto, el insert del pago
            // reventaria con un error de SQL en vez de un mensaje entendible.
            if ($total <= 0) {
                throw new OperacionInvalidaException(
                    'El total de la venta debe ser mayor que cero para poder registrar el cobro.',
                    409
                );
            }

            $venta = new VentaDirecta();
            $venta->timestamps = false;
            $venta->forceFill([
                'distribuidora_id' => $distribuidoraId,
                'sucursal_id' => $sucursalId,
                'cliente_directo_id' => $clienteDirectoId,
                'folio' => $this->siguienteFolio(VentaDirecta::query(), 'VD-', $distribuidoraId),
                'fecha_venta' => now(),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'total' => $total,
                'estado' => 'completada', // E7-01: la venta se entrega de inmediato
                'registrada_por_staff_id' => $staffId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            // 3) Detalle + descuento de stock, linea por linea.
            $detalles = [];
            foreach ($preparadas as $linea) {
                $detalle = new VentaDirectaDetalle();
                $detalle->timestamps = false; // la tabla no tiene created_at ni updated_at
                $detalle->forceFill([
                    'distribuidora_id' => $distribuidoraId,
                    'venta_directa_id' => $venta->id,
                    'stock_local_id' => $linea['stock']->id,
                    'producto_campana_id' => $linea['producto_campana_id'],
                    'variante_id' => $linea['variante_id'],
                    'producto_nombre' => $linea['producto_nombre'],
                    'modelo' => $linea['modelo'],
                    'talla' => $linea['talla'],
                    'color' => $linea['color'],
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'subtotal' => $linea['subtotal'],
                ])->save();

                $this->descuentoStock->descontar(
                    $linea['stock'],
                    $linea['cantidad'],
                    $detalle,
                    $staffId
                );

                $detalles[] = $detalle;
            }

            // 4) Cobro de contado, dentro de la misma transaccion.
            $pago = $this->registrarPago($venta, $metodoPago, $staffId, $distribuidoraId);

            return new ResultadoVentaDirecta($venta, $detalles, $pago);
        });
    }

    /**
     * La venta directa es de contado: el pago se registra completo y aplicado.
     * chk_pago_origen exige exactamente uno de pedido_id / venta_directa_id.
     * chk_pago_direccion exige 'entrada' para todo tipo distinto de reembolso.
     */
    private function registrarPago(
        VentaDirecta $venta,
        string $metodoPago,
        int $staffId,
        int $distribuidoraId
    ): Pago {
        $pago = new Pago();
        $pago->timestamps = false; // la tabla no tiene updated_at
        $pago->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'pedido_id' => null,
            'venta_directa_id' => $venta->id,
            'folio' => $this->siguienteFolio(Pago::query(), 'PG-', $distribuidoraId),
            'tipo' => 'venta_directa',
            'direccion' => 'entrada',
            'metodo' => $metodoPago,
            'monto' => (float) $venta->total,
            'fecha_pago' => now(),
            'referencia' => null,          // pendiente: el empleado captura referencia?
            'proveedor_pago' => null,      // solo aplicaria con Mercado Pago real (fuera de alcance)
            'referencia_externa' => null,
            'estado' => 'aplicado',
            'registrado_por_staff_id' => $staffId,
            'created_at' => now(),
        ])->save();

        return $pago;
    }

    /**
     * @param array{variante_id:int, producto_campana_id:int, cantidad:int} $linea
     */
    private function prepararLinea(array $linea, int $distribuidoraId, int $sucursalId): array
    {
        $varianteId = (int) $linea['variante_id'];
        $productoCampanaId = (int) $linea['producto_campana_id'];
        $cantidad = (int) $linea['cantidad'];

        $variante = Variante::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($varianteId)
            ->first();

        if ($variante === null) {
            throw new OperacionInvalidaException('La variante no existe.', 404);
        }

        $productoCampana = ProductoCampana::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($productoCampanaId)
            ->first();

        if ($productoCampana === null) {
            throw new OperacionInvalidaException('La publicacion de catalogo no existe.', 404);
        }

        if ((int) $productoCampana->producto_id !== (int) $variante->producto_id) {
            throw new OperacionInvalidaException(
                'La variante no pertenece al producto de esa publicacion de catalogo.',
                409
            );
        }

        // No se valida el estado de la campana: el stock fisico se puede vender
        // aunque la campana ya este finalizada, con su ultimo precio conocido.
        $producto = Producto::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($variante->producto_id)
            ->first();

        if ($producto === null) {
            throw new OperacionInvalidaException('El producto de la variante no existe.', 404);
        }

        // tallas y colores son catalogos GLOBALES: no llevan distribuidora_id.
        $talla = Talla::query()->whereKey($variante->talla_id)->first();
        $color = Color::query()->whereKey($variante->color_id)->first();

        $stock = $this->descuentoStock->bloquearYValidar(
            $varianteId,
            $cantidad,
            $distribuidoraId,
            $sucursalId
        );

        $precioUnitario = round((float) $productoCampana->precio_minorista_sugerido, 2);

        return [
            'stock' => $stock,
            'cantidad' => $cantidad,
            'variante_id' => $varianteId,
            'producto_campana_id' => $productoCampanaId,
            // Fotografia historica: se copia el texto, no la referencia,
            // para que la venta no cambie si el catalogo se edita despues.
            'producto_nombre' => (string) $producto->nombre,
            'modelo' => (string) $producto->modelo,
            'talla' => $talla !== null ? trim($talla->sistema . ' ' . $talla->valor) : '',
            'color' => (string) ($variante->nombre_color_comercial ?: ($color->nombre ?? '')),
            'precio_unitario' => $precioUnitario,
            'subtotal' => round($cantidad * $precioUnitario, 2),
        ];
    }

    private function verificarClienteDelTenant(int $clienteDirectoId, int $distribuidoraId): void
    {
        $existe = ClienteDirecto::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($clienteDirectoId)
            ->exists();

        if (! $existe) {
            throw new OperacionInvalidaException('El cliente directo no existe.', 404);
        }
    }

    /**
     * DECISION PROVISIONAL confirmada por el lider: ningun archivo define el
     * formato de folio, ni de venta directa ni de pago. Consecutivo por
     * distribuidora y por ano, calculado dentro de la transaccion con bloqueo
     * para que dos cajas simultaneas no repitan folio.
     */
    private function siguienteFolio(Builder $query, string $tipo, int $distribuidoraId): string
    {
        $prefijo = $tipo . now()->format('Y') . '-';

        $ultimo = $query
            ->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo . '%')
            ->orderByDesc('folio')
            ->lockForUpdate()
            ->value('folio');

        $consecutivo = $ultimo !== null
            ? ((int) substr((string) $ultimo, strlen($prefijo))) + 1
            : 1;

        return $prefijo . str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }
}