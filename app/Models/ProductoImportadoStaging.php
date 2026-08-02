<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoImportadoStaging extends Model
{
    protected $table = 'productos_importados_staging';

    protected $fillable = [
        'distribuidora_id',
        'importacion_id',
        'datos_extraidos',
        'campos_dudosos',
        'estado',
        'producto_creado_id',
    ];

    protected $casts = [
        'datos_extraidos' => 'array',
        'campos_dudosos' => 'array',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function importacion()
    {
        return $this->belongsTo(ImportacionCatalogo::class, 'importacion_id');
    }

    public function productoCreado()
    {
        return $this->belongsTo(Producto::class, 'producto_creado_id');
    }
}