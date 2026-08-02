<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variante extends Model
{
    protected $table = 'variantes';

    protected $fillable = [
        'distribuidora_id',
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

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

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

    // hasMany StockLocal se agrega en el Bloque 5 (stock).
}