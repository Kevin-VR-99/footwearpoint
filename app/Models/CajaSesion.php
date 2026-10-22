<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Una sesión de caja (apertura → corte) de una persona en una sucursal. */
class CajaSesion extends Model
{
    use BelongsToTenant;

    protected $table = 'cajas_sesiones';

    protected $fillable = [
        'distribuidora_id',
        'sucursal_id',
        'abierta_por_staff_id',
        'cerrada_por_staff_id',
        'estado',
        'fondo_inicial',
        'abierta_at',
        'cerrada_at',
        'esperado',
        'contado',
        'diferencia',
        'notas',
    ];

    protected $casts = [
        'fondo_inicial' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'abierta_at' => 'datetime',
        'cerrada_at' => 'datetime',
        'esperado' => 'array',
        'contado' => 'array',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function abiertaPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'abierta_por_staff_id');
    }

    public function cerradaPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'cerrada_por_staff_id');
    }

    public function movimientos()
    {
        return $this->hasMany(CajaMovimiento::class, 'caja_sesion_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'caja_sesion_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'abierta';
    }
}
