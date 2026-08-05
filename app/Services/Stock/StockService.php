<?php

namespace App\Services\Stock;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Support\ContextoOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private ContextoOperativo $contexto)
    {
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

            return $this->registrarMovimiento($stock, 'entrada', $cantidad, $anterior, $posterior, $motivo);
        });
    }

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

            return $this->registrarMovimiento($stock, $tipo, $cantidad, $anterior, $posterior, $motivo);
        });
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
    private function verificarVarianteDelTenant(int $varianteId): void
    {
        $existe = Variante::query()
            ->where('distribuidora_id', $this->contexto->distribuidoraId())
            ->whereKey($varianteId)
            ->exists();

        if (! $existe) {
            throw new OperacionInvalidaException('La variante no existe.', 404);
        }
    }
}