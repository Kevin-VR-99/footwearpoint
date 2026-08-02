<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionCicloDiaRecepcion extends Model
{
    protected $table = 'configuracion_ciclo_dias_recepcion';

    // Esta tabla no tiene columna "id": su llave es la combinación de
    // configuracion_ciclo_id + dia_semana. Por eso se desactiva el
    // autoincremento normal de Eloquent.
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'configuracion_ciclo_id',
        'dia_semana',
    ];

    public function configuracionCiclo()
    {
        return $this->belongsTo(ConfiguracionCiclo::class, 'configuracion_ciclo_id');
    }
}