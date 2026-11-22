<?php

namespace App\Services\Metricas;

use App\Models\ActividadDiaria;
use App\Models\Pago;
use App\Models\VentaDirecta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TG-395 (A35) — Qué cuenta y qué no, en un solo lugar.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO. Los indicadores (A34) y las gráficas (A35)
 * miden lo mismo con los mismos filtros; lo único distinto es que uno saca un
 * total y la otra lo parte en el tiempo. Si cada uno armara su propia
 * consulta, bastaría con que alguien cambiara una regla en un lado para que
 * la gráfica y el número de arriba dejaran de coincidir — y una pantalla que
 * se contradice es peor que una pantalla sin gráficas.
 *
 * Así que las reglas viven aquí y los dos las piden prestadas. Son tres:
 *
 *   - El plan filtra los ingresos de suscripción, pero NO las ventas de
 *     tienda. El plan es el contrato de la distribuidora con FootwearPoint;
 *     no tiene nada que ver con a quién le vende ella.
 *   - «Gente de una distribuidora» son su personal y los revendedores
 *     afiliados. Un revendedor con varias distribuidoras cuenta para todas.
 *   - El detalle de actividad solo se guarda 90 días (A43). Más atrás no hay
 *     nada, y eso se dice en vez de rellenarlo con ceros.
 */
class ConsultasDeMetricas
{
    /** El día más viejo del que todavía hay detalle de actividad. */
    public function horizonteDeActividad(): Carbon
    {
        return now(ActividadDiaria::ZONA)
            ->subDays(ActividadDiaria::DIAS_QUE_SE_GUARDA)
            ->startOfDay();
    }

    /**
     * Mueve el inicio al horizonte si el periodo pedido se sale de él.
     *
     * Devuelve también si hubo que moverlo, porque eso cambia lo que se puede
     * decir del número: no es «los usuarios del último año», son «los de los
     * últimos 90 días», y la diferencia importa.
     *
     * @return array{inicio: Carbon, recortado: bool}
     */
    public function recortarAlHorizonte(Carbon $desde): array
    {
        $horizonte = $this->horizonteDeActividad();
        $recortado = $desde->lessThan($horizonte);

        return [
            'inicio' => $recortado ? $horizonte : $desde->copy(),
            'recortado' => $recortado,
        ];
    }

    /**
     * Las entradas al panel y a la app, ya recortadas al horizonte.
     *
     * OJO: cada fila es una persona en un día y un canal. Quien entró por los
     * dos canales el mismo día tiene dos filas, así que para contar personas
     * siempre hace falta distinct.
     */
    public function actividad(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null): Builder
    {
        $inicio = $this->recortarAlHorizonte($desde)['inicio'];

        $consulta = ActividadDiaria::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $hasta->toDateString()]);

        if ($distribuidoraId !== null) {
            $consulta->whereIn('usuario_id', $this->usuariosDe($distribuidoraId));
        }

        return $consulta;
    }

    /** Lo que FootwearPoint cobró por la plataforma. Solo pagos ya aplicados. */
    public function pagosDeSuscripcion(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null, ?int $planId = null): Builder
    {
        $consulta = Pago::withoutGlobalScopes()
            ->where('pagos.tipo', 'suscripcion')
            ->where('pagos.estado', 'aplicado')
            ->whereBetween('pagos.fecha_pago', [$desde, $hasta]);

        if ($distribuidoraId !== null) {
            $consulta->where('pagos.distribuidora_id', $distribuidoraId);
        }

        if ($planId !== null) {
            $consulta->whereIn(
                'pagos.suscripcion_id',
                DB::table('suscripciones')->where('plan_id', $planId)->select('id')
            );
        }

        return $consulta;
    }

    /**
     * Lo que las distribuidoras le vendieron a sus clientes.
     *
     * Sin filtro de plan, y no es un olvido: ver la nota de arriba.
     */
    public function ventasDeTienda(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null): Builder
    {
        $consulta = VentaDirecta::withoutGlobalScopes()
            ->whereNull('anulada_at')
            ->whereBetween('fecha_venta', [$desde, $hasta]);

        if ($distribuidoraId !== null) {
            $consulta->where('distribuidora_id', $distribuidoraId);
        }

        return $consulta;
    }

    /**
     * Quiénes cuentan como gente de una distribuidora.
     *
     * Su personal, y los revendedores afiliados a ella. Un revendedor puede
     * trabajar con varias distribuidoras y entonces cuenta para todas: usa la
     * plataforma para cada una, así que no sería correcto asignarlo a una
     * sola ni dejarlo fuera.
     */
    public function usuariosDe(int $distribuidoraId): \Illuminate\Database\Query\Builder
    {
        $staff = DB::table('distribuidora_staff')
            ->where('distribuidora_id', $distribuidoraId)
            ->select('usuario_id');

        return DB::table('revendedores')
            ->join('revendedor_distribuidora', 'revendedor_distribuidora.revendedor_id', '=', 'revendedores.id')
            ->where('revendedor_distribuidora.distribuidora_id', $distribuidoraId)
            ->select('revendedores.usuario_id')
            ->union($staff);
    }
}
