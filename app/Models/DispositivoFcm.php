<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispositivoFcm extends Model
{
    protected $table = 'dispositivos_fcm';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'token',
        'plataforma',
        'ultimo_uso_at',
    ];

    protected $casts = [
        'ultimo_uso_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}