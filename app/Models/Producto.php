<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use BelongsToTenant;

    protected $table = 'productos';

    protected $fillable = [
        'distribuidora_id',
        'marca_id',
        'linea_id',
        'categoria_id',
        'modelo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function marca()
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
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