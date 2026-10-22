<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Un reembolso pedido a la API de Mercado Pago (ver ReembolsoMercadoPagoService). */
class ReembolsoMercadoPago extends Model
{
    use BelongsToTenant;

    protected $table = 'reembolsos_mercado_pago';

    public const PENDIENTE = 'pendiente';
    public const PROCESANDO = 'procesando';
    public const APROBADO = 'aprobado';
    public const FALLIDO = 'fallido';

    /** estado => [variante de insignia, texto] */
    public const ETIQUETAS = [
        self::PENDIENTE  => ['warning', 'Reembolso en cola'],
        self::PROCESANDO => ['warning', 'Reembolso en proceso'],
        self::APROBADO   => ['success', 'Reembolsado en Mercado Pago'],
        self::FALLIDO    => ['danger', 'Reembolso fallido'],
    ];

    protected $fillable = [
        'distribuidora_id', 'pago_id', 'pago_reembolso_id', 'venta_directa_id', 'pedido_id',
        'mp_pago_id', 'monto', 'parcial', 'estado', 'mp_reembolso_id', 'mp_estado',
        'idempotency_key', 'intentos', 'ultimo_error', 'motivo', 'solicitado_por_staff_id', 'aprobado_at',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'parcial' => 'boolean',
        'aprobado_at' => 'datetime',
    ];

    /** La llave nunca sale en respuestas ni en la pantalla. */
    protected $hidden = ['idempotency_key'];

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function etiqueta(): string
    {
        return self::ETIQUETAS[$this->estado][1] ?? ucfirst((string) $this->estado);
    }

    public function variante(): string
    {
        return self::ETIQUETAS[$this->estado][0] ?? 'neutral';
    }

    public function sePuedeReintentar(): bool
    {
        return $this->estado === self::FALLIDO;
    }

    /** Para la API (sin llave de idempotencia). */
    public function aArreglo(): array
    {
        return [
            'id'              => (int) $this->id,
            'estado'          => $this->estado,
            'estado_etiqueta' => $this->etiqueta(),
            'monto'           => (float) $this->monto,
            'parcial'         => (bool) $this->parcial,
            'mp_reembolso_id' => $this->mp_reembolso_id,
            'intentos'        => (int) $this->intentos,
            'error'           => $this->ultimo_error,
            'aprobado_at'     => $this->aprobado_at?->toIso8601String(),
            'se_puede_reintentar' => $this->sePuedeReintentar(),
        ];
    }
}
