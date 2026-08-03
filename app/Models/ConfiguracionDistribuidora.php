<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ConfiguracionDistribuidora extends Model
{
    use BelongsToTenant;
    protected $table = 'configuraciones_distribuidora';

    protected $fillable = [
        'distribuidora_id',
        'anticipo_por_producto',
        'dias_solicitud_cambio',
        'dias_gestion_devolucion',
        'dias_vigencia_vale',
        'dias_maximos_recoleccion',
        'moneda',
        'zona_horaria',
        'mercado_pago_account_id',
    ];

    protected $casts = [
        'anticipo_por_producto' => 'decimal:2',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }
}