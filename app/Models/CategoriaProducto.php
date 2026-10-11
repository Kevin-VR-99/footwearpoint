<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Categoría de calzado del catálogo compartido (TG-209). */
class CategoriaProducto extends Model
{
    protected $table = 'categorias_producto';

    protected $fillable = [
        'nombre',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
