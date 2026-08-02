<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaProducto extends Model
{
    protected $table = 'categorias_producto';

    protected $fillable = [
        'distribuidora_id',
        'nombre',
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
}