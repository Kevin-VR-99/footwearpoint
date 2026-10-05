<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué tan disponible está cada talla dentro de una temporada.
 *
 * Del catálogo compartido: la define la fábrica y es igual para todas las
 * distribuidoras (D2, TG-209).
 */
class DisponibilidadVarianteCampana extends Model
{
    protected $table = 'disponibilidad_variante_campana';

    // Esta tabla solo tiene updated_at, no created_at.
    const CREATED_AT = null;

    protected $fillable = [
        'producto_campana_id',
        'variante_id',
        'estado',
        'fecha_verificacion',
    ];

    protected $casts = [
        'fecha_verificacion' => 'datetime',
    ];

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }
}