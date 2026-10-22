<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Un paso de la línea de tiempo de una solicitud de cambio. */
class SolicitudCambioHistorial extends Model
{
    use BelongsToTenant;

    protected $table = 'solicitudes_cambio_historial';

    protected $fillable = [
        'distribuidora_id', 'solicitud_cambio_id', 'estado_anterior', 'estado_nuevo', 'nota', 'staff_id',
    ];

    public function solicitud()
    {
        return $this->belongsTo(SolicitudCambio::class, 'solicitud_cambio_id');
    }

    public function staff()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'staff_id');
    }
}
