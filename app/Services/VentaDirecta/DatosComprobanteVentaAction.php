<?php

namespace App\Services\VentaDirecta;

use App\Http\Resources\VentaDirectaResource;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Sucursal;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;

/**
 * Junta todo lo que lleva el comprobante de una venta directa (E7-02 / TG-115).
 *
 * Es el único lugar que arma esos datos: lo usan la página imprimible, el
 * PDF y el correo, para que los tres digan exactamente lo mismo.
 *
 * Criterio de la historia: producto, variante, precio y fecha. Todo sale de
 * lo que se guardó AL VENDER (venta_directa_detalle copia nombre, modelo,
 * talla y color), así que el comprobante no cambia si después se edita el
 * catálogo.
 *
 * Recibe una venta ya encontrada con el TenantScope activo: quien llama es
 * responsable de no pasar la venta de otra distribuidora.
 */
class DatosComprobanteVentaAction
{
    /**
     * @return array{
     *     venta_id: int, folio: string, fecha: \Carbon\CarbonInterface, anulada: bool,
     *     distribuidora: array{nombre_comercial: string, razon_social: ?string, rfc: ?string, email: ?string},
     *     sucursal: array{nombre: ?string, direccion: ?string, telefono: ?string},
     *     cliente: ?array{nombre: string, email: ?string},
     *     atendio: ?string,
     *     lineas: list<array{producto: string, modelo: string, talla: string, color: string, cantidad: int, precio_unitario: float, subtotal: float}>,
     *     subtotal: float, descuento: float, total: float, base_gravable: float, iva: float,
     *     pago: ?array{folio: string, metodo: string, monto: float}
     * }
     */
    public function ejecutar(VentaDirecta $venta): array
    {
        $distribuidora = Distribuidora::query()->findOrFail($venta->distribuidora_id);
        $sucursal = Sucursal::query()->find($venta->sucursal_id);

        $cliente = $venta->cliente_directo_id !== null
            ? ClienteDirecto::query()->find($venta->cliente_directo_id)
            : null;

        $staff = DistribuidoraStaff::query()
            ->with('usuario')
            ->find($venta->registrada_por_staff_id);

        $lineas = VentaDirectaDetalle::query()
            ->where('venta_directa_id', $venta->id)
            ->orderBy('id')
            ->get()
            ->map(fn (VentaDirectaDetalle $linea) => [
                'producto'        => (string) $linea->producto_nombre,
                'modelo'          => (string) $linea->modelo,
                'talla'           => (string) $linea->talla,
                'color'           => (string) $linea->color,
                'cantidad'        => (int) $linea->cantidad,
                'precio_unitario' => (float) $linea->precio_unitario,
                'subtotal'        => (float) $linea->subtotal,
            ])
            ->all();

        // La venta directa es de contado: tiene un pago de entrada.
        $pago = Pago::query()
            ->where('venta_directa_id', $venta->id)
            ->where('direccion', 'entrada')
            ->orderBy('id')
            ->first();

        // Los importes YA incluyen IVA. El desglose es solo para mostrar, con
        // la misma cuenta que VentaDirectaResource.
        $total = (float) $venta->total;
        $baseGravable = round($total / (1 + VentaDirectaResource::TASA_IVA), 2);

        return [
            'venta_id' => (int) $venta->id,
            'folio'    => (string) $venta->folio,
            'fecha'    => $venta->fecha_venta,
            'anulada'  => $venta->estado === 'anulada',

            'distribuidora' => [
                'nombre_comercial' => (string) $distribuidora->nombre_comercial,
                'razon_social'     => $distribuidora->razon_social,
                'rfc'              => $distribuidora->rfc,
                'email'            => $distribuidora->email_publico,
            ],

            // Dirección y teléfono de la sucursal donde se vendió; si no
            // tuviera, los públicos de la distribuidora.
            'sucursal' => [
                'nombre'    => $sucursal?->nombre,
                'direccion' => $sucursal?->direccion ?: $distribuidora->direccion_publica,
                'telefono'  => $sucursal?->telefono ?: $distribuidora->telefono_publico,
            ],

            'cliente' => $cliente !== null
                ? ['nombre' => (string) $cliente->nombre, 'email' => $cliente->email]
                : null,

            'atendio' => $staff?->usuario?->nombre,

            'lineas' => $lineas,

            'subtotal'      => (float) $venta->subtotal,
            'descuento'     => (float) $venta->descuento,
            'total'         => $total,
            'base_gravable' => $baseGravable,
            'iva'           => round($total - $baseGravable, 2),

            'pago' => $pago !== null
                ? ['folio' => (string) $pago->folio, 'metodo' => (string) $pago->metodo, 'monto' => (float) $pago->monto]
                : null,
        ];
    }
}
