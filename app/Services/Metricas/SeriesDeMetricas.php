<?php

namespace App\Services\Metricas;

use App\Models\ActividadDiaria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * TG-395 (A35) — Las mismas cifras de Métricas, repartidas en el tiempo.
 *
 * Los indicadores (A34) contestan «cuánto en todo el periodo». Esto contesta
 * «cuándo», que es otra pregunta: un total de treinta mil pesos se lee igual
 * si entró parejo todo el mes o si entró entero en un día.
 *
 * LOS FILTROS NO SE REPITEN AQUÍ. Salen de ConsultasDeMetricas, el mismo sitio
 * del que los saca A34. Si el plan dejara de filtrar los ingresos, o dejara de
 * no filtrar las ventas, cambiaría en los dos a la vez: la gráfica y el número
 * de arriba no se pueden contradecir porque preguntan lo mismo.
 *
 * TRES COSAS QUE ESTA CLASE SE NIEGA A INVENTAR, y son la mitad del trabajo:
 *
 *   1. Los usuarios activos no se suman. Quien entró lunes y martes es UNA
 *      persona en la semana y DOS en la cuenta diaria. Por eso cada tramo se
 *      cuenta por separado con su propio distinct, en vez de sumar los días.
 *   2. Antes del horizonte de 90 días no hay cero: no hay dato. Un cero dice
 *      «nadie entró» y sería mentira; esos tramos salen marcados como sin
 *      dato y la gráfica los deja huecos.
 *   3. El último tramo siempre va a medias, porque el periodo termina hoy.
 *      Una semana con dos días corridos parece un desplome. Va marcado «en
 *      curso» para que no se lea como una caída.
 */
class SeriesDeMetricas
{
    /**
     * Cada cuántos días es un punto de la gráfica, según lo largo del periodo.
     *
     * No es un adorno: 365 barras no son una gráfica, son ruido, y 7 tramos de
     * un mes esconden el día en que pasó todo. El salto a 28 (y no a 30) es
     * para que los tramos largos encajen con los de semana.
     */
    public const PASOS = [
        31 => 1,
        91 => 7,
    ];

    public const PASO_MAXIMO = 28;

    public const NOMBRES = [1 => 'día', 7 => 'semana', 28 => 'cuatro semanas'];

    public function __construct(private ConsultasDeMetricas $consultas) {}

    /**
     * @return array{
     *   paso: int, agrupacion: string, tramos: int,
     *   series: array<string, array{
     *     puntos: list<array{etiqueta: string, valor: float, sin_dato: bool, en_curso: bool}>,
     *     total: float,
     *     tendencia: array{direccion: string, porcentaje: float|null, anterior: float, sin_comparacion: string|null}
     *   }>
     * }
     */
    public function calcular(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
    {
        $dias = $this->diasDelPeriodo($desde, $hasta);
        $paso = $this->paso($dias);
        $tramos = (int) ceil($dias / $paso);

        return [
            'paso' => $paso,
            'agrupacion' => self::NOMBRES[$paso],
            'tramos' => $tramos,
            'series' => [
                'usuarios_activos' => $this->usuariosActivos($desde, $hasta, $paso, $tramos, $distribuidoraId),
                'ingresos_suscripcion' => $this->ingresos($desde, $hasta, $paso, $tramos, $distribuidoraId, $planId),
                'ventas_tienda' => $this->ventas($desde, $hasta, $paso, $tramos, $distribuidoraId),
            ],
        ];
    }

    /**
     * Cuántos días naturales cubre el periodo, contando los dos extremos.
     *
     * Por días y no por horas: $hasta es el final de hoy, así que una resta
     * normal da 6.99 para una semana y el techo le sacaba un tramo de más —
     * un día que no existe en el periodo, marcado además «en curso».
     */
    private function diasDelPeriodo(Carbon $desde, Carbon $hasta): int
    {
        return (int) $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
    }

    private function paso(int $dias): int
    {
        foreach (self::PASOS as $hasta => $paso) {
            if ($dias <= $hasta) {
                return $paso;
            }
        }

        return self::PASO_MAXIMO;
    }

    /**
     * Personas distintas por tramo, cada tramo contado aparte.
     *
     * Los tramos que caen antes del horizonte de 90 días quedan marcados sin
     * dato. El tramo donde cae el horizonte sí lleva número, aunque solo cubra
     * parte de sus días: por eso la pantalla dice además desde cuándo alcanza
     * el detalle.
     */
    private function usuariosActivos(Carbon $desde, Carbon $hasta, int $paso, int $tramos, ?int $distribuidoraId): array
    {
        $recorte = $this->consultas->recortarAlHorizonte($desde);

        $valores = $this->porTramo(
            $this->consultas->actividad($desde, $hasta, $distribuidoraId),
            'fecha',
            'COUNT(DISTINCT usuario_id)',
            $desde,
            $paso,
        );

        $primerTramoConDato = $recorte['recortado']
            ? intdiv((int) $desde->copy()->startOfDay()->diffInDays($recorte['inicio']), $paso)
            : 0;

        // TG-397 (A37): el total se pide UNA vez y la tendencia lo reutiliza.
        // Antes se contaba dos veces la misma gente del mismo periodo.
        $total = (float) $this->consultas
            ->actividad($desde, $hasta, $distribuidoraId)
            ->distinct()
            ->count('usuario_id');

        return [
            'puntos' => $this->puntos($desde, $hasta, $paso, $tramos, $valores, $primerTramoConDato),
            'total' => $total,
            'tendencia' => $this->tendenciaDeUsuarios($total, $desde, $hasta, $distribuidoraId, $recorte['recortado']),
        ];
    }

    /**
     * El total NO se saca sumando las barras.
     *
     * Cada barra viene redondeada a centavos; sumarlas puede dar un centavo
     * de diferencia con el indicador de arriba, y una pantalla donde la
     * gráfica y el número no cuadran por un centavo es una pantalla en la que
     * nadie vuelve a confiar. Se pide el total igual que lo pide A34.
     */
    private function ingresos(Carbon $desde, Carbon $hasta, int $paso, int $tramos, ?int $distribuidoraId, ?int $planId): array
    {
        $anterior = $this->periodoAnterior($desde, $hasta);

        $total = fn (Carbon $a, Carbon $b): float => round((float) $this->consultas
            ->pagosDeSuscripcion($a, $b, $distribuidoraId, $planId)
            ->sum('pagos.monto'), 2);

        $valores = $this->porTramo(
            $this->consultas->pagosDeSuscripcion($desde, $hasta, $distribuidoraId, $planId),
            'pagos.fecha_pago',
            'SUM(pagos.monto)',
            $desde,
            $paso,
        );

        // TG-397 (A37): el total del periodo se pide una sola vez; antes se
        // repetía la misma consulta para la tendencia.
        $actual = $total($desde, $hasta);

        return [
            'puntos' => $this->puntos($desde, $hasta, $paso, $tramos, $valores),
            'total' => $actual,
            'tendencia' => $this->tendencia($actual, $total($anterior['desde'], $anterior['hasta'])),
        ];
    }

    private function ventas(Carbon $desde, Carbon $hasta, int $paso, int $tramos, ?int $distribuidoraId): array
    {
        $anterior = $this->periodoAnterior($desde, $hasta);

        $total = fn (Carbon $a, Carbon $b): float => round((float) $this->consultas
            ->ventasDeTienda($a, $b, $distribuidoraId)
            ->sum('total'), 2);

        $valores = $this->porTramo(
            $this->consultas->ventasDeTienda($desde, $hasta, $distribuidoraId),
            'fecha_venta',
            'SUM(total)',
            $desde,
            $paso,
        );

        // TG-397 (A37): el total del periodo se pide una sola vez; antes se
        // repetía la misma consulta para la tendencia.
        $actual = $total($desde, $hasta);

        return [
            'puntos' => $this->puntos($desde, $hasta, $paso, $tramos, $valores),
            'total' => $actual,
            'tendencia' => $this->tendencia($actual, $total($anterior['desde'], $anterior['hasta'])),
        ];
    }

    /**
     * Agrupa en la base de datos, no en PHP.
     *
     * El número de tramo se calcula con DATEDIFF contra el primer día del
     * periodo, en vez de usar semanas o meses del calendario: así el primer
     * tramo empieza justo donde empieza el periodo, y la misma expresión vale
     * para una columna de fecha y para una de fecha con hora.
     *
     * $columna y $agregado van dentro del SQL. No vienen de ningún lado:
     * ambos son literales escritos en esta clase.
     *
     * @return array<int, float>
     */
    private function porTramo(Builder $consulta, string $columna, string $agregado, Carbon $desde, int $paso): array
    {
        $filas = $consulta
            ->selectRaw(
                "FLOOR(DATEDIFF({$columna}, ?) / ?) as tramo, {$agregado} as total",
                [$desde->toDateString(), $paso]
            )
            ->groupByRaw('tramo')
            ->pluck('total', 'tramo');

        $valores = [];

        foreach ($filas as $tramo => $total) {
            $valores[(int) $tramo] = round((float) $total, 2);
        }

        return $valores;
    }

    /**
     * Rellena los tramos que no salieron en la consulta y les pone etiqueta.
     *
     * Un tramo sin filas es un cero de verdad (no hubo pagos, no hubo ventas)
     * salvo cuando cae antes de $primerTramoConDato, que es donde se acaba el
     * detalle de actividad: ahí no es cero, es que no se sabe.
     *
     * @param  array<int, float>  $valores
     * @return list<array{etiqueta: string, valor: float, sin_dato: bool, en_curso: bool}>
     */
    private function puntos(Carbon $desde, Carbon $hasta, int $paso, int $tramos, array $valores, int $primerTramoConDato = 0): array
    {
        $puntos = [];

        for ($tramo = 0; $tramo < $tramos; $tramo++) {
            $inicio = $desde->copy()->startOfDay()->addDays($tramo * $paso);
            $fin = min($inicio->copy()->addDays($paso - 1), $hasta->copy()->startOfDay());

            $puntos[] = [
                'etiqueta' => $this->etiqueta($inicio, $fin, $paso),
                'valor' => $valores[$tramo] ?? 0.0,
                'sin_dato' => $tramo < $primerTramoConDato,
                'en_curso' => $tramo === $tramos - 1,
            ];
        }

        return $puntos;
    }

    /**
     * «12 nov» para un día; «12–18 nov» para un tramo que cabe en un mes.
     *
     * EL AÑO SE ESCRIBE CUANDO NO ES ESTE. Un periodo de un año arranca en
     * noviembre pasado, y «23 nov» sin año se lee como el de la semana que
     * viene: el primer tramo de la gráfica parecía estar en el futuro.
     *
     * locale('es') por llamada porque APP_LOCALE está en 'en'; es lo que ya
     * hace el resto de la pantalla.
     */
    private function etiqueta(Carbon $inicio, Carbon $fin, int $paso): string
    {
        $esteAnio = now(ActividadDiaria::ZONA)->year;
        $dia = fn (Carbon $f): string => $f->locale('es')->translatedFormat('j M')
            .($f->year === $esteAnio ? '' : ' '.$f->year);

        if ($paso === 1 || $inicio->isSameDay($fin)) {
            return $dia($inicio);
        }

        // isSameMonth mira también el año: noviembre de un año no es el mismo
        // mes que noviembre del anterior, aunque se llamen igual.
        if ($inicio->isSameMonth($fin)) {
            return $inicio->format('j').'–'.$dia($fin);
        }

        return ($inicio->year === $fin->year ? $inicio->locale('es')->translatedFormat('j M') : $dia($inicio))
            .' – '.$dia($fin);
    }

    /** El mismo número de días, pegado antes del periodo que se está viendo. */
    private function periodoAnterior(Carbon $desde, Carbon $hasta): array
    {
        $dias = $this->diasDelPeriodo($desde, $hasta);

        return [
            'desde' => $desde->copy()->subDays($dias)->startOfDay(),
            'hasta' => $desde->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Contra el periodo anterior, y sin porcentaje cuando no había base.
     *
     * Pasar de 0 a 4 no es «subió 400%»: es que antes no había nada. Un
     * porcentaje ahí es un número inventado que encima se ve autoritario.
     */
    private function tendencia(float $actual, float $anterior, ?string $sinComparacion = null): array
    {
        if ($sinComparacion !== null) {
            return ['direccion' => 'igual', 'porcentaje' => null, 'anterior' => 0.0, 'sin_comparacion' => $sinComparacion];
        }

        $direccion = match (true) {
            $actual > $anterior => 'sube',
            $actual < $anterior => 'baja',
            default => 'igual',
        };

        return [
            'direccion' => $direccion,
            'porcentaje' => $anterior > 0.0 ? round((($actual - $anterior) / $anterior) * 100, 1) : null,
            'anterior' => $anterior,
            'sin_comparacion' => null,
        ];
    }

    /**
     * La tendencia de usuarios se calla cuando no hay con qué comparar.
     *
     * El detalle se guarda 90 días. Si el periodo anterior cae fuera, no
     * existe: enseñar una flecha ahí sería compararse contra una tabla vacía y
     * cualquier periodo saldría «subiendo».
     */
    private function tendenciaDeUsuarios(float $actual, Carbon $desde, Carbon $hasta, ?int $distribuidoraId, bool $recortado): array
    {
        $anterior = $this->periodoAnterior($desde, $hasta);
        $horizonte = $this->consultas->horizonteDeActividad();

        if ($recortado || $anterior['desde']->lessThan($horizonte)) {
            return $this->tendencia(0.0, 0.0, 'El detalle de actividad se guarda '
                .ActividadDiaria::DIAS_QUE_SE_GUARDA.' días: no alcanza para comparar con el periodo anterior.');
        }

        return $this->tendencia(
            $actual,
            (float) $this->consultas->actividad($anterior['desde'], $anterior['hasta'], $distribuidoraId)->distinct()->count('usuario_id'),
        );
    }
}
