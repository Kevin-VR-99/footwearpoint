<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgregarLineaPedidoAction
{
    public function ejecutar(Pedido $pedido, array $datos): Pedido
    {
        if ($pedido->estado !== 'borrador') {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se pueden agregar líneas a pedidos en borrador.'],
            ]);
        }

        $pc = ProductoCampana::with('producto')
            ->where('id', $datos['producto_campana_id'])
            ->first();

        if (! $pc) {
            throw ValidationException::withMessages([
                'producto_campana_id' => ['El producto de campaña no existe en esta distribuidora.'],
            ]);
        }

        $variante = Variante::with(['talla', 'color', 'producto'])
            ->where('id', $datos['variante_id'])
            ->first();

        if (! $variante) {
            throw ValidationException::withMessages([
                'variante_id' => ['La variante no existe en esta distribuidora.'],
            ]);
        }

        // La variante debe ser del mismo producto que el producto_campana
        if ((int) $variante->producto_id !== (int) $pc->producto_id) {
            throw ValidationException::withMessages([
                'variante_id' => ['La variante no pertenece al producto de esa campaña.'],
            ]);
        }

        $cantidad = (int) $datos['cantidad'];
        $precio = isset($datos['precio_unitario'])
            ? round((float) $datos['precio_unitario'], 2)
            : round((float) $pc->precio_mayorista, 2);

        $subtotalLinea = round($precio * $cantidad, 2);

        $anticipoUnitario = (float) (ConfiguracionDistribuidora::query()->value('anticipo_por_producto') ?? 0);
        $anticipoRequerido = round($anticipoUnitario * $cantidad, 2);

        $productoNombre = $pc->producto?->nombre
            ?? $variante->producto?->nombre
            ?? 'Producto';
        $modelo = $pc->producto?->modelo
            ?? $variante->producto?->modelo
            ?? '';
        $talla = $variante->talla?->valor ?? (string) $variante->talla_id;
        $color = $variante->nombre_color_comercial
            ?? $variante->color?->nombre
            ?? (string) $variante->color_id;

        return DB::transaction(function () use (
            $pedido, $pc, $variante, $cantidad, $precio, $subtotalLinea,
            $anticipoRequerido, $productoNombre, $modelo, $talla, $color
        ) {
            PedidoDetalle::create([
                'distribuidora_id'     => $pedido->distribuidora_id,
                'pedido_id'            => $pedido->id,
                'producto_campana_id'  => $pc->id,
                'variante_id'          => $variante->id,
                'producto_nombre'      => $productoNombre,
                'modelo'               => $modelo,
                'talla'                => $talla,
                'color'                => $color,
                'cantidad'             => $cantidad,
                'precio_unitario'      => $precio,
                'subtotal'             => $subtotalLinea,
                'anticipo_requerido'   => $anticipoRequerido,
                'estado_surtido'       => 'pendiente',
            ]);

            $nuevoSubtotal = (float) $pedido->detalle()->sum('subtotal');
            $pedido->subtotal = $nuevoSubtotal;
            $pedido->total = $nuevoSubtotal;
            $pedido->save();

            return $pedido->fresh([
                'clienteDirecto',
                'revendedorAfiliacion.revendedor',
                'detalle',
            ]);
        });
    }
}