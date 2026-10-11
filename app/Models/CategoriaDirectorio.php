<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TG-197 (G16) — Categoría general del directorio público (por ejemplo
 * "Dama" o "Infantil").
 *
 * Es de la plataforma, no de una distribuidora: no lleva distribuidora_id ni
 * TenantScope. Solo el admin general las crea, edita y asigna. No se borran:
 * se desactivan, y una inactiva deja de verse en el marketplace sin perder a
 * qué distribuidoras estaba asignada.
 *
 * No confundir con CategoriaProducto, que clasifica el calzado del catálogo.
 */
class CategoriaDirectorio extends Model
{
    protected $table = 'categorias_directorio';

    public const LARGO_MAXIMO_NOMBRE = 120;

    protected $fillable = [
        'nombre',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('categorias_directorio.activa', true);
    }

    public function distribuidoras()
    {
        return $this->belongsToMany(
            Distribuidora::class,
            'distribuidora_categoria_directorio',
            'categoria_directorio_id',
            'distribuidora_id'
        );
    }
}
