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
        'revendedores_incluidos_contratados',
        'precio_revendedor_extra_contratado',
        'revendedores_extra_contratados',
        'renovacion_automatica',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'renovacion_automatica' => 'boolean',
        'precio_base_contratado' => 'decimal:2',
        'precio_linea_extra_contratado' => 'decimal:2',
        'revendedores_incluidos_contratados' => 'integer',
        'precio_revendedor_extra_contratado' => 'decimal:2',
        'revendedores_extra_contratados' => 'integer',
    ];

    /**
     * Límite de clientes mayoristas: igual que las líneas, cada suscripción
     * congela lo que su plan incluye y el precio de cada revendedor extra.
     * Se llena aquí para no tocar cada lugar donde se crea una suscripción.
     *
     * Los extra que la distribuidora ya había aceptado pasan a la nueva
     * suscripción (por ejemplo, si el admin general le cambia el plan); lo
     * que se cobra sigue topado por los que de verdad tiene de más
     * (CupoRevendedoresDistribuidora::extrasQueSeCobran).
     */
    protected static function booted(): void
    {
        static::creating(function (Suscripcion $suscripcion) {
            $plan = null;

            if ($suscripcion->revendedores_incluidos_contratados === null || $suscripcion->precio_revendedor_extra_contratado === null) {
                $plan = $suscripcion->plan_id ? PlanSuscripcion::query()->find($suscripcion->plan_id) : null;
            }

            if ($suscripcion->revendedores_incluidos_contratados === null) {
                $suscripcion->revendedores_incluidos_contratados = (int) ($plan?->revendedores_incluidos
                    ?? \App\Services\Distribuidora\CupoRevendedoresDistribuidora::INCLUIDOS_POR_DEFECTO);
            }

            if ($suscripcion->precio_revendedor_extra_contratado === null) {
                $suscripcion->precio_revendedor_extra_contratado = (float) ($plan?->precio_revendedor_extra
                    ?? \App\Services\Distribuidora\CupoRevendedoresDistribuidora::PRECIO_EXTRA_POR_DEFECTO);
            }

            if ($suscripcion->revendedores_extra_contratados === null) {
                $anteriores = $suscripcion->distribuidora_id === null ? null : static::withoutGlobalScopes()
                    ->where('distribuidora_id', $suscripcion->distribuidora_id)
                    ->latest('id')
                    ->value('revendedores_extra_contratados');

                $suscripcion->revendedores_extra_contratados = (int) ($anteriores ?? 0);
            }
        });
    }

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
