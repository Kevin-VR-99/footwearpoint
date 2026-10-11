<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una marca del catálogo compartido (TG-209): existe una sola vez, con nombre
 * único en todo el sistema.
 */
class Marca extends Model
{
    protected $table = 'marcas';

    protected $fillable = [
        'nombre',
        'logotipo_url',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function lineas()
    {
        return $this->belongsToMany(Linea::class, 'linea_marca', 'marca_id', 'linea_id')
            ->withTimestamps();
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'marca_id');
    }
}
