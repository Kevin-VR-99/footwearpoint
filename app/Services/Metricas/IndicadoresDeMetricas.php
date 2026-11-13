<?php

namespace App\Services\Metricas;

use App\Models\ActividadDiaria;
use App\Models\Pago;
use App\Models\VentaDirecta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TG-394 (A34) — Los números de la pantalla de Métricas.
 *
 * LO QUE NO SE MEZCLA, y es el «listo cuando» de la tarjeta: los ingresos de
 * suscripción y las ventas de tienda son dinero de bolsillos distintos.
 *
 *   - Ingresos de suscripción = lo que las distribuidoras le pagan a
 *     FootwearPoint por usar la plataforma.
 *   - Ventas de tienda = lo que las distribuidoras le cobran a SUS clientes.
 *
 * Sumarlos daría un número que no significa nada y que encima haría parecer
 * que FootwearPoint gana mucho más de lo que gana. Por eso van en dos
 * indicadores separados y nunca en un total.
 *
 * CADA DEFINICIÓN SE ENSEÑA EN PANTALLA (lo pide la tarjeta). Las de aquí
 * abajo son las que se muestran, palabra por palabra, para que nadie tenga
 * que leer este archivo para saber qué está viendo.
 */
class IndicadoresDeMetricas
{
    public function __construct(private PlanMasContratado $planMasContratado) {}

    /**
     * Qué quiere decir cada número, tal cual se enseña.
     *
     * @return array<string, string>
     */
    public static function definiciones(): array
    {
        return [
            'usuarios_activos' => 'Personas distintas que entraron al panel o a la app al menos un día del periodo. '
                .'Quien usa los dos cuenta una vez.',
            'ingresos_suscripcion' => 'Lo que las distribuidoras le pagaron a FootwearPoint por la plataforma, '
                .'solo pagos ya aplicados. No incluye lo que ellas venden.',
            'ventas_tienda' => 'Lo que las distribuidoras le cobraron a sus clientes, sin contar las ventas anuladas. '
                .'Este dinero no es de FootwearPoint.',
            'plan_mas_contratado' => 'Distribuidoras distintas con suscripción activa en cada plan. '
                .'No cuenta las suscripciones de prueba.',
        ];
    }

    /**
     * @return array{
     *   usuarios_activos: int, usuarios_desde: string|null, periodo_recortado: bool,
     *   ingresos_suscripcion: float, ventas_tienda: float,
     *   plan_mas_contratado: string, plan_mas_contratado_cuantas: int
     * }
     */
    public function calcular(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
    {
        $plan = $this->planMasContratado->calcular();
        $usuarios = $this->usuariosActivos($desde, $hasta, $distribuidoraId);

        return [
            'usuarios_activos' => $usuarios['cuantos'],
            'usuarios_desde' => $usuarios['desde'],
            'periodo_recortado' => $usuarios['recortado'],
            'ingresos_suscripcion' => $this->ingresosDeSuscripcion($desde, $hasta, $distribuidoraId, $planId),
            'ventas_tienda' => $this->ventasDeTienda($desde, $hasta, $distribuidoraId),
            'plan_mas_contratado' => $plan['nombres'],
            'plan_mas_contratado_cuantas' => $plan['cuantas'],
        ];
    }

    /**
     * Personas distintas que entraron, por cualquiera de los dos canales.
     *
     * OJO con el horizonte: el detalle solo se guarda 90 días (A43). Si el
     * periodo pedido es más largo, el número no puede cubrirlo y se dice en
     * pantalla desde cuándo sí alcanza, en vez de dar una cifra que parezca
     * del año entero cuando es de tres meses.
     *
     * @return array{cuantos: int, desde: string|null, recortado: bool}
     */
    private function usuariosActivos(Carbon $desde, Carbon $hasta, ?int $distribuidoraId): array
    {
        $masViejoQueHay = now(ActividadDiaria::ZONA)
            ->subDays(ActividadDiaria::DIAS_QUE_SE_GUARDA)
            ->startOfDay();

        $recortado = $desde->lessThan($masViejoQueHay);
        $inicio = $recortado ? $masViejoQueHay : $desde;

        $consulta = ActividadDiaria::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $hasta->toDateString()]);

        if ($distribuidoraId !== null) {
            $consulta->whereIn('usuario_id', $this->usuariosDe($distribuidoraId));
        }

        return [
            'cuantos' => (int) $consulta->distinct()->count('usuario_id'),
            'desde' => $recortado ? $inicio->toDateString() : null,
            'recortado' => $recortado,
        ];
    }

    /**
     * Quiénes cuentan como gente de una distribuidora.
     *
     * Su personal, y los revendedores afiliados a ella. Un revendedor puede
     * trabajar con varias distribuidoras y entonces cuenta para todas: usa la
     * plataforma para cada una, así que no sería correcto asignarlo a una
     * sola ni dejarlo fuera.
     */
    private function usuariosDe(int $distribuidoraId): \Illuminate\Database\Query\Builder
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

    /** Lo que FootwearPoint cobró por la plataforma. Solo pagos aplicados. */
    private function ingresosDeSuscripcion(Carbon $desde, Carbon $hasta, ?int $distribuidoraId, ?int $planId): float
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

        return round((float) $consulta->sum('pagos.monto'), 2);
    }

    /**
     * Lo que las distribuidoras le vendieron a sus clientes.
     *
     * El filtro de plan NO se aplica aquí a propósito: el plan es el contrato
     * de la distribuidora con FootwearPoint y no tiene nada que ver con a
     * quién le vende ella. Filtrar ventas por plan daría un número que no
     * responde ninguna pregunta.
     */
    private function ventasDeTienda(Carbon $desde, Carbon $hasta, ?int $distribuidoraId): float
    {
        $consulta = VentaDirecta::withoutGlobalScopes()
            ->whereNull('anulada_at')
            ->whereBetween('fecha_venta', [$desde, $hasta]);

        if ($distribuidoraId !== null) {
            $consulta->where('distribuidora_id', $distribuidoraId);
        }

        return round((float) $consulta->sum('total'), 2);
    }
}
