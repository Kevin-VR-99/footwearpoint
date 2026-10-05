<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea del catálogo que esta distribuidora decidió vender (TG-210).
 *
 * El catálogo es compartido; esto es la capa de cada distribuidora: qué parte
 * de ese catálogo ofrece a sus clientes, dentro del límite de su plan.
 */
class DistribuidoraLinea extends Model
{
    use BelongsToTenant;

    protected $table = 'distribuidora_linea';

    protected $fillable = [
        'distribuidora_id',
        'linea_id',
        'es_extra',
        'activa',
        'fecha_activacion',
    ];

    protected $casts = [
        'es_extra' => 'boolean',
        'activa' => 'boolean',
        'fecha_activacion' => 'datetime',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    /**
     * Las que de verdad cuentan: activadas por la distribuidora Y todavía
     * activas en el catálogo maestro. Si el admin general retira una línea,
     * deja de ocupar lugar del plan, pero no se le quita a quien la tenía.
     */
    public function scopeVigentes($consulta)
    {
        return $consulta->where('activa', true)
            ->whereHas('linea', fn ($linea) => $linea->where('activa', true));
    }
}
