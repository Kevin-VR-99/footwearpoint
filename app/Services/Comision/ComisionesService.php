<?php

namespace App\Services\Comision;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ConfiguracionDistribuidora;
use App\Models\MetaRevendedor;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Reporte\PagosDePedidos;
use App\Support\MensajeError;
use Illuminate\Support\Carbon;

/**
 * Comisiones y metas de los clientes mayoristas.
 *
 * Regla: la comisión se calcula sobre los pedidos del periodo (por fecha de
 * colocación) que ya están PAGADOS por completo (pagos aplicados + vales ≥
 * total). % propio del cliente mayorista o, si no tiene, el de la
 * distribuidora. La meta del mes se compara contra esa misma venta pagada.
 */
class ComisionesService
{
    public function __construct(
        private PagosDePedidos $pagos,
        private PermisoModuloService $permisos,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    public static function periodoValido(?string $periodo): string
    {
        return $periodo !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : now()->format('Y-m');
    }

    public function porcentajeDefault(): float
    {
        return (float) (ConfiguracionDistribuidora::query()->value('comision_porcentaje_default') ?? 0);
    }

    /**
     * Ranking del periodo (mayor venta pagada primero).
     *
     * @return list<array{afiliacion_id:int,nombre:string,porcentaje:float,porcentaje_propio:bool,pedidos_pagados:int,venta_pagada:float,comision:float,meta:?float,avance:?float,lugar:int}>
     */
    public function reporte(string $periodo, ?int $soloAfiliacionId = null): array
    {
        $periodo = self::periodoValido($periodo);
        $inicio = Carbon::createFromFormat('Y-m-d', $periodo.'-01')->startOfDay();
        $fin = $inicio->copy()->endOfMonth();
        $default = $this->porcentajeDefault();

        $afiliaciones = RevendedorDistribuidora::query()->with('revendedor')
            ->when($soloAfiliacionId, fn ($q) => $q->whereKey($soloAfiliacionId))
            ->get();

        $pedidos = Pedido::query()
            ->whereNotNull('revendedor_distribuidora_id')
            ->when($soloAfiliacionId, fn ($q) => $q->where('revendedor_distribuidora_id', $soloAfiliacionId))
            ->whereNotIn('estado', array_merge(['borrador', 'cancelado'], RegistrarPagoPedidoAction::ESTADOS_CERRADOS))
            ->whereBetween('fecha_colocacion', [$inicio, $fin])
            ->get(['id', 'revendedor_distribuidora_id', 'total']);

        $pagado = $this->pagos->pagadoPorPedido($pedidos->pluck('id')->all());
        $pagados = $pedidos->filter(fn ($p) => (float) $p->total > 0 && ($pagado[$p->id] ?? 0) + 0.005 >= (float) $p->total)
            ->groupBy('revendedor_distribuidora_id');

        $metas = MetaRevendedor::query()->where('periodo', $periodo)->pluck('monto_meta', 'revendedor_distribuidora_id');

        $filas = $afiliaciones->map(function (RevendedorDistribuidora $a) use ($pagados, $metas, $default) {
            $suyos = $pagados->get($a->id, collect());
            $venta = round((float) $suyos->sum('total'), 2);
            $propio = $a->comision_porcentaje !== null;
            $pct = $propio ? (float) $a->comision_porcentaje : $default;
            $meta = isset($metas[$a->id]) ? (float) $metas[$a->id] : null;

            return [
                'afiliacion_id'     => (int) $a->id,
                'nombre'            => (string) ($a->revendedor?->nombre ?? 'Cliente mayorista #'.$a->id),
                'estado'            => $a->estado,
                'porcentaje'        => $pct,
                'porcentaje_propio' => $propio,
                'pedidos_pagados'   => $suyos->count(),
                'venta_pagada'      => $venta,
                'comision'          => round($venta * $pct / 100, 2),
                'meta'              => $meta,
                'avance'            => $meta ? round(min(999, $venta * 100 / $meta), 1) : null,
            ];
        })
            ->sortByDesc(fn ($f) => [$f['venta_pagada'], -$f['afiliacion_id']])
            ->values();

        return $filas->map(fn ($f, $i) => $f + ['lugar' => $i + 1])->all();
    }

    public function fijarPorcentajeDefault(float $porcentaje): void
    {
        $this->exigirAdministrar();
        $this->validarPorcentaje($porcentaje);
        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $antes = (float) $config->comision_porcentaje_default;
        $config->update(['comision_porcentaje_default' => round($porcentaje, 2)]);
        $this->auditoria->ejecutar('comision.default_actualizado', 'configuracion_distribuidora', (int) $config->id, ['porcentaje' => $antes], ['porcentaje' => $porcentaje]);
    }

    /** Nulo = vuelve a usar el % de la distribuidora. */
    public function fijarPorcentaje(int $afiliacionId, ?float $porcentaje): void
    {
        $this->exigirAdministrar();
        if ($porcentaje !== null) {
            $this->validarPorcentaje($porcentaje);
        }
        $a = RevendedorDistribuidora::query()->find($afiliacionId) ?? throw new OperacionInvalidaException('No se encontró ese cliente mayorista.', 404);
        $antes = $a->comision_porcentaje;
        $a->update(['comision_porcentaje' => $porcentaje === null ? null : round($porcentaje, 2)]);
        $this->auditoria->ejecutar('comision.porcentaje_actualizado', 'revendedor_distribuidora', (int) $a->id, ['porcentaje' => $antes], ['porcentaje' => $porcentaje]);
    }

    /** Monto nulo o 0 = sin meta ese mes. */
    public function fijarMeta(int $afiliacionId, string $periodo, ?float $monto): void
    {
        $this->exigirAdministrar();
        $periodo = self::periodoValido($periodo);
        if ($monto !== null && ($monto < 0 || $monto > 99999999)) {
            throw new OperacionInvalidaException('La meta no puede ser negativa.', 422);
        }
        RevendedorDistribuidora::query()->find($afiliacionId) ?? throw new OperacionInvalidaException('No se encontró ese cliente mayorista.', 404);

        if (! $monto) {
            MetaRevendedor::query()->where('revendedor_distribuidora_id', $afiliacionId)->where('periodo', $periodo)->delete();
        } else {
            MetaRevendedor::query()->updateOrCreate(
                ['revendedor_distribuidora_id' => $afiliacionId, 'periodo' => $periodo],
                ['monto_meta' => round($monto, 2)],
            );
        }
        $this->auditoria->ejecutar('comision.meta_actualizada', 'revendedor_distribuidora', $afiliacionId, null, ['periodo' => $periodo, 'meta' => $monto]);
    }

    public function exigirVer(): void
    {
        if (! $this->permisos->puede(auth()->user(), PermisoModuloService::COMISIONES_VER)) {
            throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        }
    }

    /** Cambiar % y metas: solo la administración. */
    private function exigirAdministrar(): void
    {
        if (! self::esAdministracion()) {
            throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        }
    }

    /** Personal de tipo administrador de la distribuidora actual. */
    public static function esAdministracion(): bool
    {
        try {
            return app(\App\Support\ContextoOperativo::class)->staff()->tipo === 'administrador';
        } catch (\Throwable) {
            return false;
        }
    }

    private function validarPorcentaje(float $p): void
    {
        if ($p < 0 || $p > 100) {
            throw new OperacionInvalidaException('El porcentaje debe estar entre 0 y 100.', 422);
        }
    }
}
