<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDirectaDetalle extends Model
{
    protected $table = 'venta_directa_detalle';

    // Esta tabla no tiene columnas de timestamps.
    public $timestamps = false;

    protected $fillable = [
        'distribuidora_id',
        'venta_directa_id',
        'stock_local_id',
        'producto_campana_id',
        'variante_id',
        'producto_nombre',
        'modelo',
        'talla',
        'color',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function ventaDirecta()
    {
        return $this->belongsTo(VentaDirecta::class, 'venta_directa_id');
    }

    public function stockLocal()
    {
        return $this->belongsTo(StockLocal::class, 'stock_local_id');
    }

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }
}