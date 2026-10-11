<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un producto del catálogo compartido: un modelo en un color, como viene en
 * los catálogos de fábrica (D9). Sus variantes son las tallas.
 *
 * Ya no se amarra a una línea (D5): el mismo modelo puede salir en los
 * catálogos de dos líneas, cada uno con su propio código. La línea se sabe por
 * la temporada en la que está publicado (TG-209).
 */
class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'marca_id',
        'categoria_id',
        'modelo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function marca()
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    public function publicacionesCampana()
    {
        return $this->hasMany(ProductoCampana::class, 'producto_id');
    }

    public function variantes()
    {
        return $this->hasMany(Variante::class, 'producto_id');
    }
}
