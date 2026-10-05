<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una talla de un producto, del catálogo compartido (TG-209). El SKU es único
 * en todo el sistema.
 */
class Variante extends Model
{
    protected $table = 'variantes';

    protected $fillable = [
        'producto_id',
        'talla_id',
        'color_id',
        'nombre_color_comercial',
        'sku',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function talla()
    {
        return $this->belongsTo(Talla::class, 'talla_id');
    }

    public function color()
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    public function disponibilidadPorCampana()
    {
        return $this->hasMany(DisponibilidadVarianteCampana::class, 'variante_id');
    }

    /** El stock sí es de cada distribuidora y sucursal. */
    public function stockLocal()
    {
        return $this->hasMany(StockLocal::class, 'variante_id');
    }
}
