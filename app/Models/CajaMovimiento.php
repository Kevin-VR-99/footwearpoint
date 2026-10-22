<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Ingreso o retiro de efectivo de la caja que no es una venta (cambio, gastos, depósito). */
class CajaMovimiento extends Model
{
    use BelongsToTenant;

    protected $table = 'cajas_movimientos';

    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'caja_sesion_id',
        'tipo',
        'monto',
        'motivo',
        'registrado_por_staff_id',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function sesion()
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'registrado_por_staff_id');
    }
}
