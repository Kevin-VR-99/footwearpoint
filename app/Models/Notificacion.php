<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'distribuidora_id',
        'tipo',
        'titulo',
        'mensaje',
        'leida_at',
        'entidad_tipo',
        'entidad_id',
    ];

    protected $casts = [
        'leida_at' => 'datetime',
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