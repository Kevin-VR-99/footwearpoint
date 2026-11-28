<?php

namespace App\Services\Distribuidora;

use App\Exceptions\CargoRevendedorExtraException;
use App\Models\RevendedorDistribuidora;
use App\Models\Suscripcion;
use App\Services\Auditoria\RegistrarAuditoriaAction;

/**
 * Cuántos clientes mayoristas (revendedores) incluye el plan de una
 * distribuidora y cuántos se cobran aparte.
 *
 * Mismo modelo que las líneas (CupoLineasDistribuidora + CobroSuscripcion):
 * la suscripción congela cuántos incluye el plan y el precio de cada uno de
 * más. Pasarse se puede, pero solo si la distribuidora acepta el cargo; desde
 * ahí ese revendedor de más se suma a su mensualidad.
 *
 * Solo cuentan los revendedores con la afiliación «activo».
 *
 * Lo que se cobra es el menor de dos números: los extra que aceptó y los que
 * de verdad tiene por encima del límite. Así:
 *   - quien ya tenía más revendedores que el límite cuando se puso no pierde a
 *     ninguno ni paga por ellos (nunca aceptó un cargo), y
 *   - si desactiva revendedores, deja de pagar por ellos en la siguiente
 *     mensualidad.
 *
 * Sin suscripción activa no hay límite que aplicar (la distribuidora tampoco
 * puede operar el panel en ese caso).
 */
class CupoRevendedoresDistribuidora
{
    public const INCLUIDOS_POR_DEFECTO = 25;

    public const PRECIO_EXTRA_POR_DEFECTO = 10.00;

    public function __construct(
        private RegistrarAuditoriaAction $auditoria,
    ) {}

    public function suscripcionActiva(int $distribuidoraId): ?Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activa')
            ->latest('id')
            ->first();
    }

    /** Revendedores con la afiliación activa. */
    public function activos(int $distribuidoraId): int
    {
        return RevendedorDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activo')
            ->count();
    }

    public function incluidos(Suscripcion $suscripcion): int
    {
        return (int) ($suscripcion->revendedores_incluidos_contratados ?? self::INCLUIDOS_POR_DEFECTO);
    }

    public function precioExtra(Suscripcion $suscripcion): float
    {
        return round((float) ($suscripcion->precio_revendedor_extra_contratado ?? self::PRECIO_EXTRA_POR_DEFECTO), 2);
    }

    /** Los que tiene activos por encima de los que incluye su plan. */
    public function porEncimaDelPlan(Suscripcion $suscripcion): int
    {
        return max(0, $this->activos((int) $suscripcion->distribuidora_id) - $this->incluidos($suscripcion));
    }

    /** Los que se suman a la mensualidad. */
    public function extrasQueSeCobran(Suscripcion $suscripcion): int
    {
        return min((int) $suscripcion->revendedores_extra_contratados, $this->porEncimaDelPlan($suscripcion));
    }

    /**
     * Para la pantalla de clientes mayoristas: el conteo y lo que cuesta.
     *
     * @return array{incluidos: int, activos: int, disponibles: int, extras_cobrados: int, sin_cargo: int, precio_extra: float, siguiente_es_extra: bool}|null
     */
    public function resumen(int $distribuidoraId): ?array
    {
        $suscripcion = $this->suscripcionActiva($distribuidoraId);

        if ($suscripcion === null) {
            return null;
        }

        $activos = $this->activos($distribuidoraId);
        $incluidos = $this->incluidos($suscripcion);
        $porEncima = max(0, $activos - $incluidos);
        $cobrados = min((int) $suscripcion->revendedores_extra_contratados, $porEncima);

        return [
            'incluidos' => $incluidos,
            'activos' => $activos,
            'disponibles' => max(0, $incluidos - $activos),
            'extras_cobrados' => $cobrados,
            // Los que ya tenía de más antes del límite: se respetan sin cobrar.
            'sin_cargo' => $porEncima - $cobrados,
            'precio_extra' => $this->precioExtra($suscripcion),
            'siguiente_es_extra' => $activos >= $incluidos,
        ];
    }

    public function elSiguienteSeriaExtra(int $distribuidoraId): bool
    {
        return (bool) ($this->resumen($distribuidoraId)['siguiente_es_extra'] ?? false);
    }

    /**
     * Antes de dar de alta (o reactivar) un revendedor.
     *
     * Si todavía cabe en su plan, no hace nada. Si ya no, y la distribuidora
     * no aceptó el cargo, avisa cuánto cuesta (409). Si lo aceptó, se le suma
     * un revendedor extra a la suscripción.
     */
    public function asegurarLugarParaUnoMas(int $distribuidoraId, bool $aceptaCargoExtra = false): void
    {
        $suscripcion = Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activa')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($suscripcion === null) {
            return;
        }

        $activos = $this->activos($distribuidoraId);
        $incluidos = $this->incluidos($suscripcion);

        if ($activos < $incluidos) {
            return;
        }

        if (! $aceptaCargoExtra) {
            throw new CargoRevendedorExtraException($this->mensajeDeCargoExtra($suscripcion), 409);
        }

        $antes = (int) $suscripcion->revendedores_extra_contratados;
        // Los extra que ya se cobran más este. Si antes tenía aceptados de
        // más (desactivó revendedores), se ajusta a lo que de verdad tiene.
        $ahora = min($antes, max(0, $activos - $incluidos)) + 1;

        $suscripcion->update(['revendedores_extra_contratados' => $ahora]);

        $this->auditoria->ejecutar(
            'revendedor_extra.contratado',
            'suscripcion',
            (int) $suscripcion->id,
            ['revendedores_extra_contratados' => $antes],
            [
                'revendedores_extra_contratados' => $ahora,
                'precio_revendedor_extra' => $this->precioExtra($suscripcion),
            ]
        );
    }

    /**
     * Lo que ve la persona antes de confirmar. Si el plan no cobra los
     * revendedores extra, no se habla de dinero.
     */
    public function mensajeDeCargoExtra(Suscripcion $suscripcion): string
    {
        $incluidos = $this->incluidos($suscripcion);
        $precio = $this->precioExtra($suscripcion);

        $aviso = "Tu plan incluye {$incluidos} cliente(s) mayorista(s) activo(s) y ya los tienes. ";

        if ($precio <= 0) {
            return $aviso.'Este queda por encima de tu plan. Confirma para agregarlo.';
        }

        return $aviso.'Cada revendedor extra cuesta $'.number_format($precio, 2)
            .' MXN más al mes y se suma a tu mensualidad. Confirma para agregarlo.';
    }

    /**
     * Al renovar: los extra se vuelven a contar con los que de verdad tiene
     * activos hoy, igual que las líneas.
     */
    public function extrasParaRenovar(Suscripcion $suscripcion): int
    {
        return $this->extrasQueSeCobran($suscripcion);
    }
}
