<?php

namespace App\Services\Suscripcion;

use App\Models\Suscripcion;
use Illuminate\Support\Carbon;

/**
 * Días de la mensualidad en fecha de calendario (America/Mexico_City).
 *
 * fecha_fin es el último día pagado (día D). Los cinco días siguientes son
 * la gracia. Al sexto día sin pago ya se puede suspender.
 */
class CalendarioVencimiento
{
    public const DIAS_GRACIA = 5;

    /** Suscripción activa o vencida más reciente, la que el comando revisa. */
    public static function suscripcionDe(int $distribuidoraId): ?Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereIn('estado', ['activa', 'vencida'])
            ->whereNotNull('fecha_fin')
            ->latest('id')
            ->first();
    }

    /**
     * Días de calendario desde fecha_fin hasta hoy. Negativo si todavía
     * no vence, 0 el día del vencimiento, positivo después.
     */
    public static function diasDesde(?Carbon $fin, ?Carbon $hoy = null): int
    {
        if ($fin === null) {
            return 0;
        }

        $hoy = ($hoy ?? today())->copy()->startOfDay();

        return (int) $fin->copy()->startOfDay()->diffInDays($hoy, false);
    }

    public static function enGracia(int $dias): bool
    {
        return $dias >= 1 && $dias <= self::DIAS_GRACIA;
    }

    /** Días que faltan para la suspensión, contando el día de hoy. */
    public static function diasAntesDeSuspender(int $dias): int
    {
        return max(0, self::DIAS_GRACIA + 1 - $dias);
    }
}
