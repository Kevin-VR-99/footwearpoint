<?php

namespace App\Services\Stock;

use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\StockLocal;

/**
 * Alerta de stock bajo por variante (talla y color).
 *
 * Una variante está baja cuando su existencia es MENOR que su mínimo (el
 * propio de la variante o, si es 0, el general de la distribuidora). La
 * primera vez que baja se avisa en la campana a los administradores de la
 * distribuidora y se marca alerta_stock_bajo_at; mientras siga baja no se
 * vuelve a avisar. Al reponerse (existencia >= mínimo) la marca se limpia y
 * la próxima bajada avisa de nuevo.
 */
class AlertaStockBajoService
{
    public const TIPO = 'stock_bajo';

    public function revisar(StockLocal $stock): void
    {
        $bajo = $stock->estaBajo();
        $yaAvisado = $stock->alerta_stock_bajo_at !== null;

        if ($bajo && ! $yaAvisado) {
            $this->avisar($stock);
            $this->marcar($stock, now());
        } elseif (! $bajo && $yaAvisado) {
            $this->marcar($stock, null);
        }
    }

    private function avisar(StockLocal $stock): void
    {
        $variante = $stock->variante()->withoutGlobalScopes()->with(['producto' => fn ($q) => $q->withoutGlobalScopes()])->first();
        $descripcion = trim(implode(' · ', array_filter([
            $variante?->producto?->nombre,
            $variante?->sku ? 'SKU '.$variante->sku : null,
        ])));
        $minimo = $stock->minimoEfectivo();

        $titulo = 'Stock bajo: '.($variante?->sku ?? 'variante '.$stock->variante_id);
        $mensaje = sprintf(
            'Quedan %d pieza%s de %s (mínimo %d). Conviene reponer.',
            (int) $stock->cantidad_disponible,
            (int) $stock->cantidad_disponible === 1 ? '' : 's',
            $descripcion !== '' ? $descripcion : 'esta variante',
            $minimo,
        );

        DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $stock->distribuidora_id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id')
            ->unique()
            ->each(fn ($usuarioId) => Notificacion::withoutGlobalScopes()->create([
                'usuario_id'       => (int) $usuarioId,
                'distribuidora_id' => (int) $stock->distribuidora_id,
                'tipo'             => self::TIPO,
                'titulo'           => $titulo,
                'mensaje'          => $mensaje,
                'leida_at'         => null,
                'entidad_tipo'     => 'stock_local',
                'entidad_id'       => (int) $stock->id,
            ]));
    }

    /** Guarda la marca sin volver a disparar el evento saved. */
    private function marcar(StockLocal $stock, $cuando): void
    {
        StockLocal::withoutGlobalScopes()->whereKey($stock->id)->update(['alerta_stock_bajo_at' => $cuando]);
        $stock->setAttribute('alerta_stock_bajo_at', $cuando);
        $stock->syncOriginalAttribute('alerta_stock_bajo_at');
    }
}
