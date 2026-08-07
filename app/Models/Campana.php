<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Campana extends Model
{
    use BelongsToTenant;

    protected $table = 'campanas';

    protected $fillable = [
        'distribuidora_id',
        'marca_id',
        'nombre',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    /** @deprecated La campaña ya no se dueña por marca; se mantiene por datos legados */
    public function marca()
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    public function lineas()
    {
        return $this->hasMany(Linea::class, 'campana_id');
    }
}