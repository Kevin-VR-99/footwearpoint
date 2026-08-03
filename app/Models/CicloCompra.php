<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class CicloCompra extends Model
{
    use BelongsToTenant;
    protected $table = 'ciclos_compra';

    protected $fillable = [
        'distribuidora_id',
        'configuracion_ciclo_id',
        'nombre',
        'fecha_apertura',
        'fecha_cierre',
        'fecha_solicitud_fabrica',
        'fecha_estimada_llegada',
        'fecha_recepcion',
        'estado',
    ];

    protected $casts = [
        'fecha_apertura' => 'datetime',
        'fecha_cierre' => 'datetime',
        'fecha_solicitud_fabrica' => 'datetime',
        'fecha_estimada_llegada' => 'date',
        'fecha_recepcion' => 'datetime',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function configuracionCiclo()
    {
        return $this->belongsTo(ConfiguracionCiclo::class, 'configuracion_ciclo_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'ciclo_compra_id');
    }
}