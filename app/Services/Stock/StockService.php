<?php

namespace App\Services\Stock;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\ContextoOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(
        private ContextoOperativo $contexto,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * E6-02 - Consulta de existencias por variante.
     */
    public function consultar(?int $varianteId = null): Builder
    {
        $query = StockLocal::query()
            ->where('distribuidora_id', $this->contexto->distribuidoraId())
            ->where('sucursal_id', $this->contexto->sucursalPrincipal()->id)
            ->with('variante');

        if ($varianteId !== null) {
            $this->verificarVarianteDelTenant($varianteId);
            $query->where('variante_id', $varianteId);
        }

        return $query->orderBy('variante_id');
    }

    /**
     * E6-01 - Entrada de stock. Crea la fila de stock_local si es la primera
     * entrada de esa variante en la sucursal.
     */
    public function registrarEntrada(int $varianteId, int $cantidad, ?string $motivo = null): MovimientoStock
    {
        return DB::transaction(function () use ($varianteId, $cantidad, $motivo) {
            $stock = $this->stockBloqueado($varianteId, permitirCreacion: true);

            $anterior = (int) $stock->cantidad_disponible;
            $posterior = $anterior + $cantidad;

            $this->guardarExistencia($stock, $posterior);

            $movimiento = $this->registrarMovimiento($stock, 'entrada', $cantidad, $anterior, $posterior, $motivo);
            $this->auditarMovimiento('stock.entrada', $stock, $movimiento);

            return $movimiento;
        });
    }

    /**
     * Entrada por modelo (o importación): varias variantes en UNA transacción.
     * Un movimiento por variante, mismo motivo. Si una falla, no entra ninguna.
     *
     * @param  array<int, int>  $cantidades  variante_id => cantidad (> 0)
     * @param  array<int, string|null>  $motivos  motivo propio por variante (opcional)
     * @return list<MovimientoStock>
     */
    public function registrarEntradas(array $cantidades, ?string $motivo = null, array $motivos = []): array
    {
        $cantidades = array_filter(
            array_map('intval', $cantidades),
            fn (int $cantidad) => $cantidad !== 0,
        );

        if ($cantidades === []) {
            throw new OperacionInvalidaException('Escribe la cantidad de al menos una talla.', 422);
        }

        foreach ($cantidades as $cantidad) {
            if ($cantidad < 1) {
                throw new OperacionInvalidaException('Las cantidades deben ser mayores que cero.', 422);
            }
        }

        // Orden fijo de bloqueo: dos entradas simultáneas no se esperan en cruz.
        ksort($cantidades);

        return DB::transaction(function () use ($cantidades, $motivo, $motivos) {
            $movimientos = [];

            foreach ($cantidades as $varianteId => $cantidad) {
                $stock = $this->stockBloqueado((int) $varianteId, permitirCreacion: true);
                $anterior = (int) $stock->cantidad_disponible;
                $posterior = $anterior + $cantidad;

                $this->guardarExistencia($stock, $posterior);

                $motivoVariante = ($motivos[$varianteId] ?? null) ?: $motivo;
                $movimiento = $this->registrarMovimiento($stock, 'entrada', $cantidad, $anterior, $posterior, $motivoVariante);
                $this->auditarMovimiento('stock.entrada', $stock, $movimiento);
                $movimiento->setRelation('stockLocal', $stock);
                $movimientos[] = $movimiento;
            }

            return $movimientos;
        });
    }

    /**
     * Movimientos recientes de una existencia de esta distribuidora y sucursal.
     */
    public function movimientosDe(int $stockLocalId, int $limite = 15, int $pagina = 1)
    {
        $stock = $this->consultar()->whereKey($stockLocalId)->firstOrFail();

        return MovimientoStock::query()
            ->where('distribuidora_id', $stock->distribuidora_id)
            ->where('stock_local_id', $stock->id)
            ->with(['registradoPor.usuario:id,nombre', 'ventaDetalle.ventaDirecta:id,folio'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($limite, ['*'], 'movimientos', $pagina);
    }

    /**
     * Piezas por variante en pedidos abiertos (aún no entregados ni cerrados).
     * Ojo: los pedidos se surten de fábrica y NUNCA reservan ni descuentan
     * stock_local (ver DescuentoStockVentaDirectaService). Es informativo.
     *
     * @param  iterable<int>  $varianteIds
     * @return array<int, int> variante_id => piezas
     */
    public function enPedidosAbiertos(iterable $varianteIds): array
    {
        $ids = collect($varianteIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('pedido_detalle as d')
            ->join('pedidos as p', function ($join) {
                $join->on('p.id', '=', 'd.pedido_id')->on('p.distribuidora_id', '=', 'd.distribuidora_id');
            })
            ->where('d.distribuidora_id', $this->contexto->distribuidoraId())
            ->whereIn('d.variante_id', $ids)
            ->whereIn('p.estado', self::ESTADOS_PEDIDO_ABIERTO)
            ->where('d.estado_surtido', '!=', 'no_surtido')
            ->groupBy('d.variante_id')
            ->selectRaw('d.variante_id, SUM(d.cantidad) as piezas')
            ->pluck('piezas', 'd.variante_id')
            ->map(fn ($piezas) => (int) $piezas)
            ->all();
    }

    /** Estados en los que un pedido sigue vivo y todavía no se entrega. */
    public const ESTADOS_PEDIDO_ABIERTO = [
        'colocado', 'en_revision', 'confirmado', 'parcialmente_disponible', 'incluido_en_ciclo',
        'solicitado_fabrica', 'en_transito', 'recibido_distribuidora', 'listo_entrega',
    ];

    /**
     * E6-02 - Ajuste manual con motivo obligatorio (merma, correccion).
     */
    public function registrarAjuste(int $varianteId, string $tipo, int $cantidad, string $motivo): MovimientoStock
    {
        if (! in_array($tipo, ['ajuste_positivo', 'ajuste_negativo'], true)) {
            throw new OperacionInvalidaException('Tipo de ajuste no valido.', 422);
        }

        return DB::transaction(function () use ($varianteId, $tipo, $cantidad, $motivo) {
            $esPositivo = $tipo === 'ajuste_positivo';

            $stock = $this->stockBloqueado($varianteId, permitirCreacion: $esPositivo);

            $anterior = (int) $stock->cantidad_disponible;
            $posterior = $esPositivo ? $anterior + $cantidad : $anterior - $cantidad;

            if ($posterior < 0) {
                throw new OperacionInvalidaException(
                    "El ajuste dejaria la existencia en negativo. Existencia actual: {$anterior}.",
                    409,
                    ['cantidad' => ['No puedes descontar mas piezas de las que hay registradas.']]
                );
            }

            $this->guardarExistencia($stock, $posterior);

            $movimiento = $this->registrarMovimiento($stock, $tipo, $cantidad, $anterior, $posterior, $motivo);
            $this->auditarMovimiento('stock.ajuste', $stock, $movimiento);

            return $movimiento;
        });
    }

    /**
     * TG-267 (U7): la entrada o el ajuste también quedan en la auditoría, con
     * la existencia antes y después. El descuento por venta de mostrador no
     * pasa por aquí: lo cubre el registro de la venta.
     */
    private function auditarMovimiento(string $accion, StockLocal $stock, MovimientoStock $movimiento): void
    {
        $this->auditoria->ejecutar(
            $accion,
            'stock_local',
            (int) $stock->id,
            ['cantidad_disponible' => (int) $movimiento->existencia_anterior],
            [
                'cantidad_disponible' => (int) $movimiento->existencia_posterior,
                'variante_id'         => (int) $stock->variante_id,
                'tipo'                => $movimiento->tipo,
                'cantidad'            => (int) $movimiento->cantidad,
                'motivo'              => $movimiento->motivo,
                'movimiento_id'       => (int) $movimiento->id,
            ],
        );
    }

    /**
     * Bloquea la fila de existencia para evitar que dos operaciones
     * simultaneas lean la misma existencia_anterior.
     */
    private function stockBloqueado(int $varianteId, bool $permitirCreacion): StockLocal
    {
        $this->verificarVarianteDelTenant($varianteId);

        $distribuidoraId = $this->contexto->distribuidoraId();
        $sucursalId = (int) $this->contexto->sucursalPrincipal()->id;

        $stock = StockLocal::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('sucursal_id', $sucursalId)
            ->where('variante_id', $varianteId)
            ->lockForUpdate()
            ->first();

        if ($stock !== null) {
            return $stock;
        }

        if (! $permitirCreacion) {
            throw new OperacionInvalidaException(
                'Esta variante todavia no tiene existencia registrada en la sucursal.',
                409
            );
        }

        $stock = new StockLocal();
        $stock->timestamps = false;
        $stock->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'sucursal_id' => $sucursalId,
            'variante_id' => $varianteId,
            'cantidad_disponible' => 0,
            'stock_minimo' => 0,
            'updated_at' => now(),
        ])->save();

        return $stock;
    }

    private function guardarExistencia(StockLocal $stock, int $existencia): void
    {
        $stock->timestamps = false;
        $stock->cantidad_disponible = $existencia;
        $stock->updated_at = now();
        $stock->save();
    }

    private function registrarMovimiento(
        StockLocal $stock,
        string $tipo,
        int $cantidad,
        int $anterior,
        int $posterior,
        ?string $motivo
    ): MovimientoStock {
        $movimiento = new MovimientoStock();
        $movimiento->timestamps = false;
        $movimiento->forceFill([
            'distribuidora_id' => $stock->distribuidora_id,
            'stock_local_id' => $stock->id,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'existencia_anterior' => $anterior,
            'existencia_posterior' => $posterior,
            'venta_detalle_id' => null, // solo lo llena la venta directa (E7)
            'registrado_por_staff_id' => $this->contexto->staff()->id,
            'motivo' => $motivo,
            'created_at' => now(),
        ])->save();

        return $movimiento;
    }

    /**
     * Si la variante es de otra distribuidora, el Global Scope la deja fuera
     * y esto responde 404: nunca se expone que existe.
     */
    /**
     * Desde TG-213 las variantes son del catálogo compartido, así que aquí
     * solo se comprueba que exista. Lo que sigue siendo de cada distribuidora
     * es su stock, y eso lo filtra cada consulta por distribuidora y sucursal.
     */
    private function verificarVarianteDelTenant(int $varianteId): void
    {
        $existe = Variante::query()->whereKey($varianteId)->exists();

        if (! $existe) {
            throw new OperacionInvalidaException('La variante no existe.', 404);
        }
    }
}