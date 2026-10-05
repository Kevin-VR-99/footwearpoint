<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Variante;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use App\Support\PropietarioActual;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\DisponibilidadVarianteCampana;

class AgregarLineaPedidoAction
{
    public function __construct(
        private CatalogoVisible $catalogo,
        private PrecioEfectivo $precios,
    ) {}

    public function ejecutar(Pedido $pedido, array $datos): Pedido
    {
        if ($pedido->estado !== 'borrador') {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se pueden agregar líneas a pedidos en borrador.'],
            ]);
        }

        // El catálogo es compartido, así que no basta con que el producto
        // exista: tiene que estar en lo que ESTA distribuidora vende, o sea
        // temporada activa, de una línea suya, activo y no oculto (TG-213).
        $pc = $this->catalogo->consulta()
            ->with('producto')
            ->whereKey($datos['producto_campana_id'])
            ->first();

        if (! $pc) {
            throw ValidationException::withMessages([
                'producto_campana_id' => ['Ese producto no está disponible en el catálogo de tu distribuidora.'],
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

        $disponibilidad = DisponibilidadVarianteCampana::query()
            ->where('producto_campana_id', $pc->id)
            ->where('variante_id', $variante->id)
            ->first();

        if (! $disponibilidad) {
            throw ValidationException::withMessages([
                'variante_id' => ['Esta variante no tiene disponibilidad registrada en la campaña.'],
            ]);
        }

        if ($disponibilidad->estado !== 'disponible') {
            throw ValidationException::withMessages([
                'variante_id' => ['La variante no está disponible en catálogo (estado: ' . $disponibilidad->estado . ').'],
            ]);
        }

        $cantidad = (int) $datos['cantidad'];

        // El precio lo decide el servidor según de quién es el pedido (TG-166):
        // el cliente directo paga el menudeo del catálogo y el revendedor el
        // mayoreo de ESTA distribuidora (su descuento general o el precio
        // propio del producto). Lo calcula PrecioEfectivo, que es el único
        // lugar donde se saca un precio (TG-212).
        //
        // Solo el personal puede poner otro precio. Si lo manda la app se
        // ignora: si no, cualquiera podría pedir un par a $1.
        $precioDeLista = $pedido->tipo === 'cliente_directo'
            ? $this->precios->menudeo($pc)
            : $this->precios->mayoreo($pc);

        $precio = PropietarioActual::esDeLaCasa() && isset($datos['precio_unitario'])
            ? round((float) $datos['precio_unitario'], 2)
            : $precioDeLista;

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
            $pedido,
            $pc,
            $variante,
            $cantidad,
            $precio,
            $subtotalLinea,
            $anticipoRequerido,
            $productoNombre,
            $modelo,
            $talla,
            $color
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
