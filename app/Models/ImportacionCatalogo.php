<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un catálogo de fábrica que se importó con IA (A6 a A9).
 *
 * No usa BelongsToTenant a propósito (TG-208): el catálogo es uno solo para
 * todo el sistema y solo el admin general lo importa, así que estas filas no
 * son de ninguna distribuidora.
 */
class ImportacionCatalogo extends Model
{
    protected $table = 'importaciones_catalogo';

    protected $fillable = [
        'linea_id',
        'campana_id',
        'archivo_url',
        'tipo_archivo',
        'proveedor_ia',
        'modelo_ia',
        'paginas',
        'tokens_entrada',
        'tokens_salida',
        'costo_usd',
        'estado',
        'iniciada_por_usuario_id',
        'revisada_por_usuario_id',
        'mensaje_error',
    ];

    protected $casts = [
        'paginas' => 'integer',
        'tokens_entrada' => 'integer',
        'tokens_salida' => 'integer',
        'costo_usd' => 'decimal:4',
    ];

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    /** La temporada a la que entran los productos importados. */
    public function campana()
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }

    public function iniciadaPor()
    {
        return $this->belongsTo(Usuario::class, 'iniciada_por_usuario_id');
    }

    public function revisadaPor()
    {
        return $this->belongsTo(Usuario::class, 'revisada_por_usuario_id');
    }

    public function productosStaging()
    {
        return $this->hasMany(ProductoImportadoStaging::class, 'importacion_id');
    }
}
