<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Pago extends Model
{
    use BelongsToTenant;
    protected $table = 'pagos';

    // Esta tabla solo tiene created_at, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'pedido_id',
        'venta_directa_id',
        'suscripcion_id',
        'folio',
        'tipo',
        'direccion',
        'metodo',
        'monto',
        'fecha_pago',
        'referencia',
        'proveedor_pago',
        'referencia_externa',
        // Id de la preferencia de Checkout Pro, con el que se liga el aviso
        // que manda Mercado Pago después (TG-208).
        'preferencia_externa',
        'estado',
        'registrado_por_staff_id',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_pago' => 'datetime',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** Solo en los pagos de tipo 'suscripcion': qué mensualidad se pagó. */
    public function suscripcion()
    {
        return $this->belongsTo(Suscripcion::class, 'suscripcion_id');
    }

    public function ventaDirecta()
    {
        return $this->belongsTo(VentaDirecta::class, 'venta_directa_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'registrado_por_staff_id');
    }
}