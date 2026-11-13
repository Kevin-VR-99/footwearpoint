<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TG-406 (A43) — Los totales por mes, 24 meses.
 *
 * Se calculan ANTES de borrar el detalle, porque después ya no se pueden
 * reconstruir: los 90 días de actividad_diaria son la única fuente.
 *
 * El canal 'total' no es panel + app. Es la gente DISTINTA entre los dos:
 * quien entra por los dos lados cuenta una vez. Por eso se guarda aparte en
 * vez de sumarlo al leer, que daría de más.
 */
class ActividadMensual extends Model
{
    /** Cuánto se guardan los totales. */
    public const MESES_QUE_SE_GUARDA = 24;

    /** La gente distinta entre todos los canales. */
    public const CANAL_TOTAL = 'total';

    /**
     * Debajo de esto no se enseña el número.
     *
     * No se afirma que esto garantice el anonimato —no lo garantiza—; es solo
     * no publicar cuentas tan chicas que señalen a alguien.
     */
    public const MINIMO_QUE_SE_ENSEÑA = 5;

    protected $table = 'actividad_mensual';

    public $timestamps = false;

    protected $fillable = ['anio_mes', 'canal', 'usuarios_unicos', 'calculado_at'];

    protected $casts = [
        'usuarios_unicos' => 'integer',
        'calculado_at' => 'datetime',
    ];

    /** Los meses que ya pasaron de los 24 y tocan borrar. */
    public function scopeVencidos(Builder $consulta): Builder
    {
        $limite = now(ActividadDiaria::ZONA)->subMonthsNoOverflow(self::MESES_QUE_SE_GUARDA)->format('Y-m');

        return $consulta->where('anio_mes', '<', $limite);
    }

    /**
     * Cómo se enseña una cuenta.
     *
     * Las cuentas chicas salen como «menos de 5» en vez del número. El texto
     * dice lo que hace y no promete lo que no puede: no se afirma que así
     * nadie pueda ser identificado.
     */
    public static function comoSeEnseña(int $cuantos): string
    {
        return $cuantos > 0 && $cuantos < self::MINIMO_QUE_SE_ENSEÑA
            ? 'menos de '.self::MINIMO_QUE_SE_ENSEÑA
            : (string) $cuantos;
    }

    /**
     * El promedio mensual de un rango, que es como se reporta una temporada.
     *
     * Una temporada son seis meses: sumar sus totales contaría varias veces a
     * quien entró en varios meses. El promedio no tiene ese problema y es lo
     * que pide la política.
     */
    public static function promedioMensual(Carbon $desde, Carbon $hasta, string $canal = self::CANAL_TOTAL): ?float
    {
        $meses = static::query()
            ->where('canal', $canal)
            ->whereBetween('anio_mes', [$desde->format('Y-m'), $hasta->format('Y-m')])
            ->pluck('usuarios_unicos');

        return $meses->isEmpty() ? null : round($meses->avg(), 1);
    }
}
