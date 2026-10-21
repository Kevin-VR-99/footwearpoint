<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class StockLocal extends Model
{
    use BelongsToTenant;
    protected $table = 'stock_local';

    // Esta tabla solo tiene updated_at, no created_at.
    const CREATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'sucursal_id',
        'variante_id',
        'cantidad_disponible',
        'stock_minimo',
    ];

    protected $casts = [
        'alerta_stock_bajo_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Cualquier cambio de existencia o de mínimo (venta, ajuste, entrada,
        // edición del mínimo) pasa por aquí: un solo lugar decide la alerta.
        static::saved(function (StockLocal $stock) {
            if ($stock->wasRecentlyCreated || $stock->wasChanged(['cantidad_disponible', 'stock_minimo'])) {
                app(\App\Services\Stock\AlertaStockBajoService::class)->revisar($stock);
            }
        });
    }

    /** Mínimo que aplica: el propio de la variante o, si es 0, el general. */
    public function minimoEfectivo(?int $general = null): int
    {
        $propio = (int) $this->stock_minimo;

        if ($propio > 0) {
            return $propio;
        }

        return $general ?? (int) (ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora_id)
            ->value('stock_minimo_general') ?? 0);
    }

    public function estaBajo(?int $general = null): bool
    {
        $minimo = $this->minimoEfectivo($general);

        return $minimo > 0 && (int) $this->cantidad_disponible < $minimo;
    }

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoStock::class, 'stock_local_id');
    }
}