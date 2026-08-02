<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = [
        'distribuidora_id',
        'nombre',
        'direccion',
        'telefono',
        'es_principal',
        'activa',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'activa' => 'boolean',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }
}