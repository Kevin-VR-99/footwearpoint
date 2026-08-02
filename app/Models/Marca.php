<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marca extends Model
{
    protected $table = 'marcas';

    protected $fillable = [
        'distribuidora_id',
        'nombre',
        'logotipo_url',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function campanas()
    {
        return $this->hasMany(Campana::class, 'marca_id');
    }
}