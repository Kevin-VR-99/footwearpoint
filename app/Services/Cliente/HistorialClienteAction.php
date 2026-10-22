<?php

namespace App\Services\Cliente;

use App\Models\ClienteDirecto;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\SolicitudCambio;
use App\Models\Vale;
use App\Models\VentaDirecta;
use App\Services\Cobranza\RecordatorioCobroService;
use App\Support\Estados;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Vista 360 de un cliente (directo o mayorista) de la distribuidora actual:
 * pedidos, ventas de mostrador, pagos, vales, saldo por cobrar y cambios.
 * Todo pasa por el scope del tenant: el de otra distribuidora es 404.
 */
class HistorialClienteAction
{
    public const TIPOS = ['cliente_directo', 'revendedor'];

    public function __construct(private RecordatorioCobroService $cobranza)
    {
    }

    public function ejecutar(string $tipo, int $id): array
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ModelNotFoundException;
        }

        $directo = $tipo === 'cliente_directo';
        $columna = $directo ? 'cliente_directo_id' : 'revendedor_distribuidora_id';

        if ($directo) {
            $c = ClienteDirecto::query()->with('usuario')->findOrFail($id);
            $perfil = ['nombre' => $c->nombre, 'telefono' => $c->telefono, 'email' => $c->email ?: $c->usuario?->email,
                'estado' => $c->estado, 'con_app' => $c->usuario_id !== null, 'alta' => $c->created_at, 'tipo_etiqueta' => 'Cliente directo'];
        } else {
            $a = RevendedorDistribuidora::query()->with('revendedor.usuario')->findOrFail($id);
            $perfil = ['nombre' => $a->revendedor?->nombre, 'telefono' => $a->revendedor?->telefono, 'email' => $a->revendedor?->email ?: $a->revendedor?->usuario?->email,
                'estado' => $a->estado, 'con_app' => $a->revendedor?->usuario_id !== null, 'alta' => $a->fecha_alta ?? $a->created_at, 'tipo_etiqueta' => 'Cliente mayorista'];
        }

        $pedidos = Pedido::query()->where($columna, $id)->orderByDesc('id')->limit(100)
            ->get(['id', 'folio', 'estado', 'total', 'fecha_colocacion', 'created_at']);

        $ventas = $directo
            ? VentaDirecta::query()->where('cliente_directo_id', $id)->orderByDesc('id')->limit(100)->get(['id', 'folio', 'estado', 'total', 'fecha_venta'])
            : collect();

        $pagos = Pago::query()
            ->where(function ($q) use ($columna, $id, $directo) {
                $q->whereIn('pedido_id', Pedido::query()->where($columna, $id)->select('id'));
                if ($directo) {
                    $q->orWhereIn('venta_directa_id', VentaDirecta::query()->where('cliente_directo_id', $id)->select('id'));
                }
            })
            ->orderByDesc('id')->limit(200)->get();

        $vales = Vale::query()->where($columna, $id)->orderByDesc('id')->get();
        $cambios = SolicitudCambio::query()->where($columna, $id)->with(['pedidoDetalle', 'ventaDetalle'])->orderByDesc('id')->get();
        $cobro = $this->cobranza->datos($tipo, $id);

        $entradas = $pagos->where('direccion', 'entrada')->where('estado', 'aplicado')->sum('monto');
        $salidas = $pagos->where('direccion', 'salida')->where('estado', 'aplicado')->sum('monto');

        return [
            'tipo'    => $tipo,
            'id'      => $id,
            'perfil'  => $perfil,
            'resumen' => [
                'pedidos'          => $pedidos->count(),
                'ventas'           => $ventas->where('estado', '!=', 'anulada')->count(),
                'comprado'         => round((float) $pedidos->whereNotIn('estado', ['cancelado', 'borrador'])->sum('total') + (float) $ventas->where('estado', '!=', 'anulada')->sum('total'), 2),
                'pagado'           => round((float) $entradas - (float) $salidas, 2),
                'saldo_por_cobrar' => (float) $cobro['saldo'],
                'vales_saldo'      => round((float) $vales->where('estado', 'activo')->sum('saldo_actual'), 2),
                'cambios_abiertos' => $cambios->whereNotIn('estado', ['rechazada', 'cerrada', 'vale_emitido'])->count(),
            ],
            'pendientes' => $cobro['pedidos'],
            'pedidos'    => $pedidos->map(fn ($p) => ['id' => $p->id, 'folio' => $p->folio ?? '#'.$p->id, 'estado' => Estados::etiqueta($p->estado),
                'total' => (float) $p->total, 'fecha' => ($p->fecha_colocacion ?? $p->created_at)?->format('d/m/Y')])->all(),
            'ventas'     => $ventas->map(fn ($v) => ['id' => $v->id, 'folio' => $v->folio, 'estado' => $v->estado, 'total' => (float) $v->total, 'fecha' => $v->fecha_venta?->format('d/m/Y')])->all(),
            'pagos'      => $pagos->map(fn (Pago $p) => ['id' => $p->id, 'folio' => $p->folio, 'tipo' => $p->tipo, 'direccion' => $p->direccion, 'metodo' => $p->metodo,
                'monto' => (float) $p->monto, 'estado' => $p->estado, 'fecha' => ($p->fecha_pago ?? $p->created_at)?->format('d/m/Y')])->all(),
            'vales'      => $vales->map(fn ($v) => ['id' => $v->id, 'folio' => $v->folio, 'original' => (float) $v->monto_original, 'saldo' => (float) $v->saldo_actual,
                'estado' => $v->estado, 'vence' => $v->fecha_vencimiento?->format('d/m/Y')])->all(),
            'cambios'    => $cambios->map(fn ($s) => ['id' => $s->id, 'producto' => ($s->pedidoDetalle ?? $s->ventaDetalle)?->producto_nombre,
                'estado' => SolicitudCambio::etiqueta($s->estado), 'fecha' => $s->fecha_solicitud?->format('d/m/Y'), 'motivo' => $s->motivo])->all(),
        ];
    }
}
