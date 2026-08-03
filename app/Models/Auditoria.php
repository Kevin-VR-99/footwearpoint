<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Auditoria extends Model
{
    use BelongsToTenant;
    protected $table = 'auditorias';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'distribuidora_id',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'datos_previos',
        'datos_nuevos',
        'ip_origen',
    ];

    protected $casts = [
        'datos_previos' => 'array',
        'datos_nuevos' => 'array',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }
}