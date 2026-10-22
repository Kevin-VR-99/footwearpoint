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
        'caja_sesion_id',
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

    /** TG-263: nombre que ve el usuario para cada tipo de pago (el valor interno no cambia). */
    public const ETIQUETAS_TIPO = [
        'anticipo' => 'Anticipo',
        'saldo_pedido' => 'Saldo',
        'total_revendedor' => 'Total mayorista',
        'venta_directa' => 'Venta directa',
        'reembolso_anticipo' => 'Reembolso de anticipo',
        'reembolso_venta' => 'Reembolso de venta',
        'suscripcion' => 'Suscripción',
    ];

    /** Cobro inmediato de una línea extra. No alarga el periodo ya pagado. */
    public const REFERENCIA_LINEA_EXTRA = 'linea-extra';

    /** TG-263: nombre que ve el usuario para cada método de pago. */
    public const ETIQUETAS_METODO = [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'tarjeta' => 'Tarjeta',
        'mercado_pago' => 'Mercado Pago',
        'otro' => 'Otro',
    ];

    public static function etiquetaTipo(?string $tipo): string
    {
        return self::etiqueta(self::ETIQUETAS_TIPO, $tipo);
    }

    public static function etiquetaMetodo(?string $metodo): string
    {
        return self::etiqueta(self::ETIQUETAS_METODO, $metodo);
    }

    /** Si llega un valor nuevo sin etiqueta: "valor_nuevo" -> "Valor nuevo". */
    private static function etiqueta(array $etiquetas, ?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        return $etiquetas[$valor] ?? ucfirst(str_replace('_', ' ', $valor));
    }


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

    /** Tipos de pago que se cobran o devuelven en el mostrador y cuentan en el corte de caja. */
    public const TIPOS_DE_CAJA = ['venta_directa', 'reembolso_venta', 'anticipo', 'saldo_pedido', 'total_revendedor', 'reembolso_anticipo'];

    protected static function booted(): void
    {
        // Corte de caja: lo que cobra o devuelve en mostrador quien tiene una
        // caja abierta queda en esa caja. Mercado Pago no pasa por la caja.
        static::creating(function (Pago $pago) {
            if ($pago->caja_sesion_id !== null
                || $pago->registrado_por_staff_id === null
                || $pago->metodo === 'mercado_pago'
                || ! in_array($pago->tipo, self::TIPOS_DE_CAJA, true)) {
                return;
            }

            $pago->caja_sesion_id = CajaSesion::withoutGlobalScopes()
                ->where('distribuidora_id', $pago->distribuidora_id)
                ->where('abierta_por_staff_id', $pago->registrado_por_staff_id)
                ->where('estado', 'abierta')
                ->orderByDesc('id')
                ->value('id');
        });
    }

    public function cajaSesion()
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
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