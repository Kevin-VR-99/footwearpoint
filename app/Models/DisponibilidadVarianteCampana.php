<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisponibilidadVarianteCampana extends Model
{
    protected $table = 'disponibilidad_variante_campana';

    // Esta tabla solo tiene updated_at, no created_at.
    const CREATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
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