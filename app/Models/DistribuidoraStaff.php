<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistribuidoraStaff extends Model
{
    protected $table = 'distribuidora_staff';

    protected $fillable = [
        'distribuidora_id',
        'usuario_id',
        'tipo',
        'estado',
        'fecha_alta',
    ];

    protected $casts = [
        'fecha_alta' => 'datetime',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}