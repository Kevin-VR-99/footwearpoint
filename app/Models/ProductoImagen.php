<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoImagen extends Model
{
    protected $table = 'producto_imagenes';

    // Esta tabla solo tiene created_at, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'producto_campana_id',
        'url',
        'orden',
        'es_principal',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }
}