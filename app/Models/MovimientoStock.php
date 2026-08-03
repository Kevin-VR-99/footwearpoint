<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class MovimientoStock extends Model
{
    use BelongsToTenant;
    protected $table = 'movimientos_stock';

    // Esta tabla solo tiene created_at, no updated_at.
    // Además es un historial inmutable: nunca se edita un registro ya creado.
    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'stock_local_id',
        'tipo',
        'cantidad',
        'existencia_anterior',
        'existencia_posterior',
        'venta_detalle_id',
        'registrado_por_staff_id',
        'motivo',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function stockLocal()
    {
        return $this->belongsTo(StockLocal::class, 'stock_local_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'registrado_por_staff_id');
    }

    public function ventaDetalle()
    {
        return $this->belongsTo(VentaDirectaDetalle::class, 'venta_detalle_id');
    }
}