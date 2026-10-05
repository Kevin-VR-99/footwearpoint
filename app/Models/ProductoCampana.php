<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un producto dentro de una temporada, con su código y su precio de catálogo.
 *
 * precio_publico es el precio de MENUDEO y es igual para todas las
 * distribuidoras (D8). El mayoreo no vive aquí: lo pone cada distribuidora con
 * su descuento general o con un precio propio (ofertas_distribuidora).
 *
 * activo deja que el admin general retire un producto de la temporada sin
 * borrarlo (TG-209).
 */
class ProductoCampana extends Model
{
    protected $table = 'producto_campana';

    protected $fillable = [
        'producto_id',
        'campana_id',
        'codigo_catalogo',
        'activo',
        'precio_publico',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'precio_publico' => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function campana()
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }

    public function imagenes()
    {
        return $this->hasMany(ProductoImagen::class, 'producto_campana_id');
    }

    public function disponibilidadPorVariante()
    {
        return $this->hasMany(DisponibilidadVarianteCampana::class, 'producto_campana_id');
    }
}
