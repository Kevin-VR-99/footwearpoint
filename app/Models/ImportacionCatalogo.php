<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ImportacionCatalogo extends Model
{
    use BelongsToTenant;
    protected $table = 'importaciones_catalogo';

    protected $fillable = [
        'distribuidora_id',
        'archivo_url',
        'tipo_archivo',
        'proveedor_ia',
        'estado',
        'iniciada_por_staff_id',
        'revisada_por_staff_id',
        'mensaje_error',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function iniciadaPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'iniciada_por_staff_id');
    }

    public function revisadaPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'revisada_por_staff_id');
    }

    public function productosStaging()
    {
        return $this->hasMany(ProductoImportadoStaging::class, 'importacion_id');
    }
}