<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispositivoFcm extends Model
{
    protected $table = 'dispositivos_fcm';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        // Sesión de Sanctum con la que se registró (TG-144). Al borrarse esa
        // sesión, la base borra este registro sola (ON DELETE CASCADE).
        'personal_access_token_id',
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