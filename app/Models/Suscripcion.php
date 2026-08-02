<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suscripcion extends Model
{
    protected $table = 'suscripciones';

    protected $fillable = [
        'distribuidora_id',
        'plan_id',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'precio_base_contratado',
        'lineas_incluidas_contratadas',
        'precio_linea_extra_contratado',
        'lineas_extra_contratadas',
        'renovacion_automatica',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'renovacion_automatica' => 'boolean',
        'precio_base_contratado' => 'decimal:2',
        'precio_linea_extra_contratado' => 'decimal:2',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function plan()
    {
        return $this->belongsTo(PlanSuscripcion::class, 'plan_id');
    }
}