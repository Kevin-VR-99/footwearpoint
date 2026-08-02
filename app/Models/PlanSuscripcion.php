<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanSuscripcion extends Model
{
    protected $table = 'planes_suscripcion';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_base_mensual',
        'lineas_incluidas',
        'precio_linea_extra',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'precio_base_mensual' => 'decimal:2',
        'precio_linea_extra' => 'decimal:2',
    ];

    public function suscripciones()
    {
        return $this->hasMany(Suscripcion::class, 'plan_id');
    }
}