<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Suscripcion extends Model
{
    use BelongsToTenant;
    protected $table = 'suscripciones';

    /** TG-220: aprobada, pero todavía no paga su primer mes. */
    public const PENDIENTE_PAGO = 'pendiente_pago';

    protected $fillable = [
        'distribuidora_id',
        'plan_id',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'precio_base_contratado',
        'lineas_incluidas_contratadas',
        'precio_linea_extra_contratado',
        'lineas_extra_contratadas',
        'renovacion_automatica',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'renovacion_automatica' => 'boolean',
        'precio_base_contratado' => 'decimal:2',
        'precio_linea_extra_contratado' => 'decimal:2',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function plan()
    {
        return $this->belongsTo(PlanSuscripcion::class, 'plan_id');
    }

    /**
     * TG-220 — La suscripción que espera el primer pago de la distribuidora,
     * o null. Solo cuenta si es la más reciente que no está cancelada: una
     * distribuidora que ya pagó (o a la que el admin general le asignó un
     * plan) nunca vuelve a quedar bloqueada.
     */
    public static function pendientePrimerPago(int $distribuidoraId): ?self
    {
        $ultima = static::withoutGlobalScopes()
            ->with('plan')
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', '!=', 'cancelada')
            ->latest('id')
            ->first();

        return $ultima?->estado === self::PENDIENTE_PAGO ? $ultima : null;
    }
}
