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

    /**
     * TG-226 (G7): el enlace de Checkout Pro de un anticipo vale 24 horas
     * desde que se crea el pago pendiente.
     */
    public const HORAS_VIGENCIA_MERCADO_PAGO = 24;

    /**
     * TG-226 (G7): referencia con la que Mercado Pago identifica este pago
     * (external_reference). Máximo 64 caracteres, solo letras, números y
     * guiones. Lleva la distribuidora para que el aviso de G9 sepa de quién es.
     */
    public function referenciaMercadoPago(): string
    {
        return 'FWP-'.$this->distribuidora_id.'-'.$this->id;
    }

    /** TG-226 (G7): cuándo deja de servir el enlace de pago de Mercado Pago. */
    public function venceMercadoPagoAt(): \Illuminate\Support\Carbon
    {
        return $this->created_at->copy()->addHours(self::HORAS_VIGENCIA_MERCADO_PAGO);
    }

    public function esMercadoPagoPendiente(): bool
    {
        return $this->metodo === 'mercado_pago' && $this->estado === 'pendiente';
    }

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