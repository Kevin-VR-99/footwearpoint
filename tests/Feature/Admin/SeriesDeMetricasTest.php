<?php

namespace Tests\Feature\Admin;

use App\Models\ActividadDiaria;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\PlanSuscripcion;
use App\Models\Usuario;
use App\Models\VentaDirecta;
use App\Services\Metricas\ConsultasDeMetricas;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Services\Metricas\SeriesDeMetricas;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-395 (A35) — Las gráficas de Métricas, con su tabla.
 *
 * El «listo cuando» de la tarjeta son tres cosas y las tres se prueban aquí:
 * que cada gráfica traiga su alternativa tabular, que los filtros sean
 * coherentes con los indicadores de A34, y los estados vacío y de error.
 *
 * Lo demás que se vigila es lo que una gráfica hace fácil inventar: sumar
 * usuarios que son la misma persona, pintar ceros donde no hay dato, y
 * enseñar como caída un tramo que apenas empezó.
 */
class SeriesDeMetricasTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoAdminGeneral(): void
    {
        $this->actingAs($this->adminGeneral());
        Tenant::olvidarCache();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(int $dias): array
    {
        $fin = now(ActividadDiaria::ZONA)->endOfDay();

        return [$fin->copy()->subDays($dias - 1)->startOfDay(), $fin];
    }

    private function series(int $dias = 30, ?int $distribuidora = null, ?int $plan = null): array
    {
        [$desde, $hasta] = $this->rango($dias);

        return app(SeriesDeMetricas::class)->calcular($desde, $hasta, $distribuidora, $plan);
    }

    private function entro(Usuario $usuario, int $haceDias, string $canal = 'panel'): void
    {
        DB::table('actividad_diaria')->insertOrIgnore([
            'usuario_id' => $usuario->id,
            'fecha' => now(ActividadDiaria::ZONA)->subDays($haceDias)->toDateString(),
            'canal' => $canal,
        ]);
    }

    private function pago(float $monto, int $haceDias, ?Distribuidora $de = null): Pago
    {
        return Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => ($de ?? Distribuidora::withoutGlobalScopes()->firstOrFail())->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => $monto,
            'fecha_pago' => now()->subDays($haceDias),
            'estado' => 'aplicado',
        ]);
    }

    private function venta(float $total, int $haceDias, ?Distribuidora $de = null): VentaDirecta
    {
        $d = $de ?? Distribuidora::withoutGlobalScopes()->firstOrFail();

        return VentaDirecta::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'sucursal_id' => DB::table('sucursales')->where('distribuidora_id', $d->id)->value('id'),
            'folio' => 'V-'.uniqid(),
            'fecha_venta' => now()->subDays($haceDias),
            'subtotal' => $total,
            'descuento' => 0,
            'total' => $total,
            'estado' => 'completada',
            'registrada_por_staff_id' => DB::table('distribuidora_staff')->where('distribuidora_id', $d->id)->value('id'),
        ]);
    }

    /** @param  list<array{valor: float, sin_dato: bool, en_curso: bool}>  $puntos */
    private function suma(array $puntos): float
    {
        return array_sum(array_map(fn ($p) => (float) $p['valor'], $puntos));
    }

    /** El trozo de HTML que va de una marca a otra, para no buscar en toda la página. */
    private function trozo(string $html, string $desde, ?string $hasta = null): string
    {
        $inicio = strpos($html, $desde);
        $this->assertNotFalse($inicio, "No está «{$desde}» en la página.");

        $trozo = substr($html, $inicio);

        if ($hasta !== null && ($fin = strpos($trozo, $hasta)) !== false) {
            $trozo = substr($trozo, 0, $fin);
        }

        return $trozo;
    }

    // --- Lo que la tarjeta pide: gráfica Y alternativa tabular ---------------

    /**
     * Las tres gráficas, cada una con su tabla.
     *
     * Son el mismo componente a propósito: una gráfica no se lee con lector de
     * pantalla, y si la tabla fuera opcional tarde o temprano se publicaría
     * una gráfica sin ella.
     */
    public function test_cada_grafica_trae_su_tabla_equivalente(): void
    {
        $this->comoAdminGeneral();

        $this->get('/admin/metricas')->assertOk();
        $html = Livewire::test('admin.metricas')->call('cargar')->html();

        foreach (['usuarios-activos', 'ingresos-de-suscripcion', 'ventas-de-las-tiendas'] as $cual) {
            $this->assertStringContainsString('data-serie="'.$cual.'"', $html, "Falta la gráfica de {$cual}.");
            $this->assertStringContainsString('data-tabla-serie="'.$cual.'"', $html, "La gráfica de {$cual} se quedó sin tabla.");
        }

        $this->assertStringNotContainsString('data-metricas-pendientes', $html, 'Sigue el recuadro de «las gráficas llegan después».');
    }

    /** La tabla tiene una fila por tramo: es la misma serie, no un resumen. */
    public function test_la_tabla_tiene_una_fila_por_tramo(): void
    {
        $this->comoAdminGeneral();
        $this->pago(400, 3);

        $html = Livewire::test('admin.metricas')->call('cargar')->html();
        $tramos = $this->series()['tramos'];

        $tabla = $this->trozo($html, 'data-tabla-serie="ingresos-de-suscripcion"', '</table>');

        $this->assertSame($tramos, substr_count($tabla, '<th scope="row"'), 'La tabla no tiene una fila por tramo.');
    }

    /** Para quien no ve la gráfica, el resumen tiene que servir solo. */
    public function test_la_grafica_se_anuncia_con_su_pico_y_su_recorrido(): void
    {
        $this->comoAdminGeneral();
        $this->pago(1500, 2);

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertOk()
            ->assertSee('role="img"', false)
            ->assertSee('El tramo más alto es', false)
            ->assertSee('Los valores exactos están en la tabla que sigue', false);
    }

    // --- Las tres cosas que una gráfica hace fácil inventar ------------------

    /**
     * Los usuarios activos NO se suman.
     *
     * Quien entró dos días son dos barras y UNA persona. Si el total saliera
     * de sumar las barras, cualquier usuario fiel inflaría la cifra.
     */
    public function test_los_usuarios_de_cada_tramo_no_se_suman_en_el_total(): void
    {
        $u = Usuario::query()->firstOrFail();
        $this->entro($u, 1);
        $this->entro($u, 3);

        $serie = $this->series(7)['series']['usuarios_activos'];

        $this->assertSame(2.0, $this->suma($serie['puntos']), 'La misma persona debería aparecer en dos tramos.');
        $this->assertSame(1.0, $serie['total'], 'El total sumó las barras: la misma persona contó dos veces.');
    }

    /**
     * Antes del horizonte no hay cero: no hay dato.
     *
     * El detalle de actividad se guarda 90 días (A43). Pintar cero en los
     * meses anteriores diría «no entró nadie», que es una afirmación, y falsa.
     */
    public function test_antes_del_horizonte_los_tramos_quedan_sin_dato(): void
    {
        $puntos = $this->series(365)['series']['usuarios_activos']['puntos'];

        $sinDato = array_filter($puntos, fn ($p) => $p['sin_dato']);

        $this->assertNotEmpty($sinDato, 'Un año de gráfica y ningún tramo marcado: se pintaron ceros donde no hay detalle.');
        $this->assertFalse($puntos[count($puntos) - 1]['sin_dato'], 'El tramo de hoy se marcó sin dato.');

        // Los 90 días que sí se guardan no pueden quedar marcados.
        $this->assertLessThan(count($puntos), count($sinDato));
    }

    /** En 30 días todo cabe en el horizonte: ahí un cero sí es un cero. */
    public function test_dentro_del_horizonte_no_se_marca_nada_sin_dato(): void
    {
        foreach ($this->series(30)['series']['usuarios_activos']['puntos'] as $punto) {
            $this->assertFalse($punto['sin_dato'], 'Se marcó sin dato un tramo que sí está guardado.');
        }
    }

    /**
     * El último tramo va a medias y se dice.
     *
     * El periodo termina hoy, así que la última semana lleva los días que
     * hayan corrido. Sin marcarlo, media semana se lee como un desplome.
     */
    public function test_el_ultimo_tramo_va_marcado_en_curso(): void
    {
        $puntos = $this->series(90)['series']['ventas_tienda']['puntos'];

        $this->assertTrue($puntos[count($puntos) - 1]['en_curso']);

        foreach (array_slice($puntos, 0, -1) as $i => $punto) {
            $this->assertFalse($punto['en_curso'], "El tramo {$i} se marcó en curso y ya terminó.");
        }
    }

    // --- Coherencia con los indicadores de A34 ------------------------------

    /**
     * El total de la gráfica es el mismo número de la tarjeta de arriba.
     *
     * Es el motivo de que las consultas vivan en ConsultasDeMetricas. Una
     * pantalla donde la gráfica y el indicador no cuadran no se arregla
     * explicándola: se deja de usar.
     */
    public function test_el_total_de_cada_serie_es_el_del_indicador(): void
    {
        $this->pago(1234.56, 5);
        $this->venta(789.10, 4);
        $this->entro(Usuario::query()->firstOrFail(), 2);

        [$desde, $hasta] = $this->rango(30);

        $n = app(IndicadoresDeMetricas::class)->calcular($desde, $hasta);
        $s = $this->series()['series'];

        $this->assertSame((float) $n['ingresos_suscripcion'], $s['ingresos_suscripcion']['total']);
        $this->assertSame((float) $n['ventas_tienda'], $s['ventas_tienda']['total']);
        $this->assertSame((float) $n['usuarios_activos'], $s['usuarios_activos']['total']);
    }

    /** La misma regla de A34: el plan no filtra las ventas de tienda. */
    public function test_el_filtro_de_plan_no_toca_la_serie_de_ventas(): void
    {
        $plan = PlanSuscripcion::query()->firstOrFail();
        $this->venta(450, 2);

        $this->assertSame(450.0, $this->series(30, plan: $plan->id)['series']['ventas_tienda']['total']);
        $this->assertSame(450.0, $this->suma($this->series(30, plan: $plan->id)['series']['ventas_tienda']['puntos']));
    }

    public function test_el_filtro_de_distribuidora_separa_las_series(): void
    {
        $distribuidoras = Distribuidora::withoutGlobalScopes()->take(2)->get();

        $this->venta(100, 2, $distribuidoras[0]);
        $this->venta(200, 2, $distribuidoras[1]);

        $suma = fn (?int $id) => $this->suma($this->series(30, $id)['series']['ventas_tienda']['puntos']);

        $this->assertSame(300.0, $suma(null));
        $this->assertSame(100.0, $suma($distribuidoras[0]->id));
        $this->assertSame(200.0, $suma($distribuidoras[1]->id));
    }

    /** Lo de fuera del periodo no se cuela por la puerta de la agrupación. */
    public function test_lo_de_fuera_del_periodo_no_entra_en_ningun_tramo(): void
    {
        $this->pago(999, 100);

        $serie = $this->series(30)['series']['ingresos_suscripcion'];

        $this->assertSame(0.0, $serie['total']);
        $this->assertSame(0.0, $this->suma($serie['puntos']));
    }

    /** Cada pago cae en el tramo de su fecha, no en el primero que pase. */
    public function test_cada_importe_cae_en_el_tramo_de_su_fecha(): void
    {
        $this->pago(100, 0);
        $this->pago(250, 2);

        $puntos = $this->series(7)['series']['ingresos_suscripcion']['puntos'];

        // 7 días, un tramo por día: el de hoy es el último, el de hace dos el
        // antepenúltimo.
        $this->assertSame(100.0, (float) $puntos[6]['valor'], 'El pago de hoy no cayó en el tramo de hoy.');
        $this->assertSame(250.0, (float) $puntos[4]['valor'], 'El pago de hace dos días cayó en otro tramo.');
        $this->assertSame(0.0, (float) $puntos[5]['valor']);
    }

    // --- La agrupación -------------------------------------------------------

    /** Un tramo por día en 30; 365 barras no serían una gráfica. */
    public function test_el_tramo_crece_con_lo_largo_del_periodo(): void
    {
        $this->assertSame('día', $this->series(7)['agrupacion']);
        $this->assertSame('día', $this->series(30)['agrupacion']);
        $this->assertSame('semana', $this->series(90)['agrupacion']);
        $this->assertSame('cuatro semanas', $this->series(365)['agrupacion']);
    }

    /** Los tramos cubren el periodo y no se pasan. */
    public function test_los_tramos_cubren_el_periodo_sin_sobrar(): void
    {
        foreach ([7, 30, 90, 365] as $dias) {
            $s = $this->series($dias);

            $this->assertSame($s['tramos'], count($s['series']['ventas_tienda']['puntos']));
            $this->assertGreaterThanOrEqual($dias, $s['tramos'] * $s['paso'], "{$dias} días: los tramos no alcanzan a cubrirlos.");
            $this->assertLessThan($dias + $s['paso'], $s['tramos'] * $s['paso'], "{$dias} días: sobra más de un tramo.");
        }
    }

    /**
     * Las etiquetas de otro año lo dicen.
     *
     * Un periodo de un año arranca en noviembre pasado. Sin el año, el primer
     * tramo se lee «23 nov» y parece el de la semana que viene: la gráfica
     * empezaba, a la vista, en el futuro.
     */
    public function test_los_tramos_de_otro_anio_llevan_el_anio(): void
    {
        $puntos = $this->series(365)['series']['ventas_tienda']['puntos'];
        $anioPasado = (string) (now(ActividadDiaria::ZONA)->year - 1);

        $this->assertStringContainsString($anioPasado, $puntos[0]['etiqueta'], 'El primer tramo no dice de qué año es.');
        $this->assertStringNotContainsString(
            (string) now(ActividadDiaria::ZONA)->year,
            $puntos[count($puntos) - 1]['etiqueta'],
            'El tramo de hoy lleva el año de más: sobra en todas las etiquetas y estorba.'
        );
    }

    /** En 30 días todo es de este año: el año solo haría ruido. */
    public function test_los_tramos_de_este_anio_no_lo_repiten(): void
    {
        foreach ($this->series(30)['series']['ventas_tienda']['puntos'] as $punto) {
            $this->assertStringNotContainsString((string) now(ActividadDiaria::ZONA)->year, $punto['etiqueta']);
        }
    }

    // --- La tendencia --------------------------------------------------------

    public function test_la_tendencia_compara_con_el_periodo_anterior_del_mismo_largo(): void
    {
        $this->pago(200, 2);   // dentro de los últimos 7
        $this->pago(100, 10);  // en los 7 anteriores

        $t = $this->series(7)['series']['ingresos_suscripcion']['tendencia'];

        $this->assertSame('sube', $t['direccion']);
        $this->assertSame(100.0, $t['anterior']);
        $this->assertSame(100.0, $t['porcentaje'], 'De 100 a 200 es +100%.');
    }

    public function test_cuando_baja_lo_dice(): void
    {
        $this->pago(50, 2);
        $this->pago(200, 10);

        $t = $this->series(7)['series']['ingresos_suscripcion']['tendencia'];

        $this->assertSame('baja', $t['direccion']);
        $this->assertSame(-75.0, $t['porcentaje']);
    }

    /**
     * De cero a algo no es «subió un 400%».
     *
     * Un porcentaje sobre cero es un número inventado, y encima se ve
     * autoritario. Se queda sin porcentaje y la pantalla lo explica.
     */
    public function test_sin_nada_en_el_periodo_anterior_no_se_inventa_porcentaje(): void
    {
        $this->pago(300, 2);

        $t = $this->series(7)['series']['ingresos_suscripcion']['tendencia'];

        $this->assertSame('sube', $t['direccion']);
        $this->assertNull($t['porcentaje'], 'Se calculó un porcentaje contra cero.');
        $this->assertSame(0.0, $t['anterior']);
    }

    /**
     * La tendencia de usuarios se calla si el horizonte no alcanza.
     *
     * Comparar los últimos 90 días contra los 90 anteriores sería compararse
     * contra una tabla vacía: cualquier periodo saldría «subiendo».
     */
    public function test_la_tendencia_de_usuarios_se_calla_cuando_no_hay_con_que_comparar(): void
    {
        $corta = $this->series(7)['series']['usuarios_activos']['tendencia'];
        $larga = $this->series(90)['series']['usuarios_activos']['tendencia'];

        $this->assertNull($corta['sin_comparacion'], 'Siete días sí caben en el horizonte: se puede comparar.');

        $this->assertNotNull($larga['sin_comparacion'], 'Se comparó contra 90 días que ya se borraron.');
        $this->assertStringContainsString((string) ActividadDiaria::DIAS_QUE_SE_GUARDA, $larga['sin_comparacion']);
    }

    /** Y en pantalla se explica, no solo se omite. */
    public function test_la_pantalla_explica_por_que_no_hay_comparacion(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.metricas', ['dias' => '90'])
            ->call('cargar')
            ->assertOk()
            ->assertSee('data-sin-comparacion', false)
            ->assertSee('no alcanza para comparar con el periodo anterior', false);
    }

    /** La flecha no va sola: con el color solo, quien no lo distingue no lee nada (A21). */
    public function test_la_tendencia_dice_la_palabra_y_no_solo_el_color(): void
    {
        $this->comoAdminGeneral();
        $this->pago(200, 2);   // dentro de los últimos 30
        $this->pago(100, 40);  // en los 30 anteriores, que es con lo que compara

        $html = Livewire::test('admin.metricas')->call('cargar')->html();

        $this->assertStringContainsString('data-tendencia="sube"', $html);
        $this->assertMatchesRegularExpression('/data-tendencia="sube"[^>]*>.*?Sube/s', $html, 'La tendencia se quedó en puro color.');
    }

    // --- Vacío, error y reintento -------------------------------------------

    /** Sin movimiento no se pinta una gráfica plana: se dice que no hay nada. */
    public function test_sin_movimiento_sale_el_estado_vacio(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertOk()
            ->assertSee('No se aplicó ningún pago de suscripción en este periodo', false);
    }

    public function test_con_movimiento_ya_no_sale_el_estado_vacio(): void
    {
        $this->comoAdminGeneral();
        $this->pago(500, 3);

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertOk()
            ->assertDontSee('No se aplicó ningún pago de suscripción en este periodo', false);
    }

    /**
     * Si las series truenan, los indicadores siguen en pie.
     *
     * Dejar que la excepción suba tiraría la pantalla entera, incluidos los
     * cuatro indicadores, que no tienen nada que ver con la agrupación.
     */
    public function test_si_las_series_truenan_la_pantalla_lo_dice_y_ofrece_reintentar(): void
    {
        Exceptions::fake();
        $this->comoAdminGeneral();
        $this->instance(SeriesDeMetricas::class, new SeriesQueTruena(app(ConsultasDeMetricas::class), 99));

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertOk()
            ->assertSee('No se pudieron armar las gráficas')
            ->assertSee('Reintentar')
            ->assertSee('data-indicador="usuarios-activos"', false);

        Exceptions::assertReported(\RuntimeException::class);
    }

    /** Y el botón sirve: no es un adorno que solo vuelve a fallar. */
    public function test_reintentar_vuelve_a_pedir_las_series(): void
    {
        Exceptions::fake();
        $this->comoAdminGeneral();
        $this->instance(SeriesDeMetricas::class, new SeriesQueTruena(app(ConsultasDeMetricas::class), 1));

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertSee('No se pudieron armar las gráficas')
            ->call('reintentarSeries')
            ->assertDontSee('No se pudieron armar las gráficas')
            ->assertSee('Cómo se movió en el tiempo');
    }

    // --- MXN, que lo pide la tarjeta ----------------------------------------

    /**
     * El importe lleva moneda en los DOS sitios donde se enseña.
     *
     * Si cada uno lo armara por su cuenta, uno podría perder el «MXN» y la
     * prueba seguiría pasando por el otro. Por eso se mira cada trozo aparte.
     */
    public function test_el_dinero_dice_que_son_pesos_mexicanos(): void
    {
        $this->comoAdminGeneral();
        $this->pago(1500.5, 2);

        $html = Livewire::test('admin.metricas')->call('cargar')->html();

        $this->assertStringContainsString(
            '$1,500.50 MXN',
            $this->trozo($html, 'data-indicador="ingresos-suscripcion"', 'data-indicador="ventas-tienda"'),
            'La tarjeta del indicador perdió la moneda.'
        );

        $this->assertStringContainsString(
            '$1,500.50 MXN',
            $this->trozo($html, 'data-serie="ingresos-de-suscripcion"', 'data-serie="ventas-de-las-tiendas"'),
            'La gráfica perdió la moneda.'
        );

        $this->assertStringContainsString('Los datos se cuentan en hora de Ciudad de M', $html);
    }

    /** Los usuarios no llevan moneda: son personas. */
    public function test_los_usuarios_no_se_enseñan_como_dinero(): void
    {
        $this->entro(Usuario::query()->firstOrFail(), 1);

        $serie = $this->series(7)['series']['usuarios_activos'];

        $this->assertSame(1.0, $serie['total']);
        $this->comoAdminGeneral();

        $tarjeta = $this->trozo(
            Livewire::test('admin.metricas')->call('cargar')->html(),
            'data-serie="usuarios-activos"',
            'data-serie="ingresos-de-suscripcion"'
        );

        $this->assertStringNotContainsString('MXN', $tarjeta, 'La gráfica de usuarios está contando personas en pesos.');
    }

    // --- Sin librerías y sin movimiento de más -------------------------------

    /**
     * La gráfica no carga nada de fuera.
     *
     * El CSP de la casa solo deja scripts propios (script-src 'self'), así que
     * una librería de gráficas por CDN no arrancaría: saldría un hueco en
     * blanco en producción y en local se vería bien. Mejor que no se pueda
     * colar.
     */
    public function test_la_grafica_no_trae_librerias_ni_scripts(): void
    {
        $vista = (string) file_get_contents(base_path('resources/views/components/ui/serie.blade.php'));

        $this->assertStringNotContainsString('<script', $vista, 'Le entró un script a la gráfica; el CSP lo va a bloquear.');

        foreach (['cdn.', 'unpkg', 'jsdelivr', 'chart.js', 'd3.', 'apexcharts'] as $rastro) {
            $this->assertStringNotContainsString($rastro, mb_strtolower($vista), "La gráfica trae {$rastro}.");
        }
    }

    /** Las columnas se apagan con movimiento reducido, como todo lo demás. */
    public function test_las_columnas_respetan_el_movimiento_reducido(): void
    {
        $vista = (string) file_get_contents(base_path('resources/views/components/ui/serie.blade.php'));
        $this->assertStringContainsString('fp-serie-columna', $vista);

        $bloques = preg_split('/@media\s*\(prefers-reduced-motion:\s*reduce\)/', (string) file_get_contents(base_path('resources/css/app.css')));
        array_shift($bloques);

        $apagada = false;

        foreach ($bloques as $bloque) {
            $bloque = explode('@media', $bloque)[0];

            if (preg_match('/\.fp-serie-columna[^{]*\{[^}]*animation:\s*none\s*!important/', $bloque)) {
                $apagada = true;
            }
        }

        $this->assertTrue($apagada, 'Las columnas se siguen animando para quien pidió movimiento reducido.');
    }

    // --- Las consultas, en un solo sitio -------------------------------------

    /**
     * Ni A34 ni A35 arman sus propios filtros.
     *
     * Es lo único que garantiza que la gráfica y el indicador midan lo mismo.
     * Si alguien vuelve a escribir un where aquí, esto lo dice.
     */
    public function test_los_filtros_viven_en_un_solo_sitio(): void
    {
        foreach ([
            'app/Services/Metricas/IndicadoresDeMetricas.php',
            'app/Services/Metricas/SeriesDeMetricas.php',
        ] as $ruta) {
            $codigo = (string) file_get_contents(base_path($ruta));

            $this->assertStringContainsString('ConsultasDeMetricas', $codigo);
            $this->assertStringNotContainsString("where('pagos.estado'", $codigo, "{$ruta} volvió a armar el filtro de pagos por su cuenta.");
            $this->assertStringNotContainsString('anulada_at', $codigo, "{$ruta} volvió a armar el filtro de ventas por su cuenta.");
        }
    }
}

/**
 * Unas series que truenan las primeras $veces llamadas.
 *
 * Con un contador y no con un fallo permanente: así se puede probar que el
 * botón de reintentar sirve de verdad, no solo que existe.
 */
class SeriesQueTruena extends SeriesDeMetricas
{
    private int $fallos = 0;

    public function __construct(ConsultasDeMetricas $consultas, private int $veces)
    {
        parent::__construct($consultas);
    }

    public function calcular(Carbon $desde, Carbon $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
    {
        if ($this->fallos < $this->veces) {
            $this->fallos++;

            throw new \RuntimeException('Series que truenan a propósito, para la prueba.');
        }

        return parent::calcular($desde, $hasta, $distribuidoraId, $planId);
    }
}
