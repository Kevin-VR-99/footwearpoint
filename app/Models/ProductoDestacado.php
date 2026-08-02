<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoDestacado extends Model
{
    protected $table = 'productos_destacados';

    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'producto_campana_id',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }
}