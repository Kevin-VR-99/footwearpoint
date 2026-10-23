<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cada producto que la IA sacó de un catálogo, antes de aprobarlo (A8, A9).
 *
 * Sin BelongsToTenant, igual que ImportacionCatalogo: lo que se importa entra
 * al catálogo compartido, no al de una distribuidora (TG-208).
 */
class ProductoImportadoStaging extends Model
{
    protected $table = 'productos_importados_staging';

    protected $fillable = [
        'importacion_id',
        'datos_extraidos',
        'campos_dudosos',
        'estado',
        'producto_creado_id',
        'producto_campana_creado_id',
        // TG-282 (E5-05): el recorte que la IA propone y si alguien ya lo
        // miró. Nunca se publica solo.
        'foto_propuesta_url',
        'foto_estado',
        // La foto que este recorte dejó en la galería de una publicación que
        // ya tenía foto (segundo color del mismo modelo). Evita duplicarla.
        'producto_imagen_galeria_id',
    ];

    protected $casts = [
        'datos_extraidos' => 'array',
        'campos_dudosos' => 'array',
    ];

    public function importacion()
    {
        return $this->belongsTo(ImportacionCatalogo::class, 'importacion_id');
    }

    public function productoCreado()
    {
        return $this->belongsTo(Producto::class, 'producto_creado_id');
    }

    /** El producto dentro de la temporada que se creó al aprobar esta fila. */
    public function productoCampanaCreado()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_creado_id');
    }
}
