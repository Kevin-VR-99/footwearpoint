<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto de un producto en una temporada; es del catálogo, la ven todas (TG-209). */
class ProductoImagen extends Model
{
    protected $table = 'producto_imagenes';

    // Esta tabla solo tiene created_at, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
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