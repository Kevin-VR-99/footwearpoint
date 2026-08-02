<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionCiclo extends Model
{
    protected $table = 'configuraciones_ciclo';

    protected $fillable = [
        'distribuidora_id',
        'dia_cierre',
        'hora_cierre',
        'dia_solicitud_fabrica',
        'dias_estimados_llegada',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function diasRecepcion()
    {
        return $this->hasMany(ConfiguracionCicloDiaRecepcion::class, 'configuracion_ciclo_id');
    }
}