<?php

namespace Tests\Feature\Admin;

use App\Models\ActividadDiaria;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Services\Metricas\CacheDeMetricas;
use App\Services\Metricas\ConsultasDeMetricas;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Services\Metricas\PendientesDeMetricas;
use App\Services\Metricas\PlanMasContratado;
use App\Services\Metricas\SeriesDeMetricas;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-397 [A37] — Rendimiento y métricas pendientes (fase 2).
 *
 * La tarjeta pide consultas agregadas, índices y caché, y dejar documentado
 * como fase 2 lo que todavía no se puede medir (salud del servidor, presencia
 * en línea). El «listo cuando» es: lista de pendientes documentada y sin
 * secretos en la pantalla. Cada parte tiene aquí su prueba.
 */
class RendimientoDeMetricasTest extends TestCase
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

    private function pago(float $monto, int $haceDias = 1): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => $monto,
            'fecha_pago' => now(ActividadDiaria::ZONA)->subDays($haceDias),
            'estado' => 'aplicado',
        ]);
    }

    /** Cuenta las veces que se calculan los indicadores y las series. */
    private function espias(): array
    {
        $indicadores = new class(app(PlanMasContratado::class), app(ConsultasDeMetricas::class)) extends IndicadoresDeMetricas
        {
            public int $llamadas = 0;

            public function calcular($desde, $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
            {
                $this->llamadas++;

                return parent::calcular($desde, $hasta, $distribuidoraId, $planId);
            }
        };

        $series = new class(app(ConsultasDeMetricas::class)) extends SeriesDeMetricas
        {
            public int $llamadas = 0;

            public function calcular($desde, $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
            {
                $this->llamadas++;

                return parent::calcular($desde, $hasta, $distribuidoraId, $planId);
            }
        };

        $this->instance(IndicadoresDeMetricas::class, $indicadores);
        $this->instance(SeriesDeMetricas::class, $series);

        return [$indicadores, $series];
    }

    // --- Consultas agregadas -------------------------------------------------

    /**
     * Las series ya no piden dos veces el mismo total.
     *
     * Por gráfica: una consulta agrupada por tramo, una del total y una del
     * periodo anterior. Tres gráficas, nueve consultas. Antes eran doce
     * porque el total se volvía a pedir para la tendencia.
     */
    public function test_las_series_no_repiten_consultas(): void
    {
        [$desde, $hasta] = $this->rango(30);
        $servicio = app(SeriesDeMetricas::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resultado = $servicio->calcular($desde, $hasta);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(9, $consultas, "Las series hicieron {$consultas} consultas; se está repitiendo alguna.");
        $this->assertNull($resultado['series']['usuarios_activos']['tendencia']['sin_comparacion']);
    }

    /** Reutilizar el total no cambia lo que se enseña: sigue cuadrando con el indicador. */
    public function test_el_total_reutilizado_sigue_cuadrando_con_el_indicador_y_la_tendencia(): void
    {
        $this->pago(300, 2);
        $this->pago(100, 40);

        [$desde, $hasta] = $this->rango(30);
        $series = app(SeriesDeMetricas::class)->calcular($desde, $hasta);
        $indicadores = app(IndicadoresDeMetricas::class)->calcular($desde, $hasta);

        $ingresos = $series['series']['ingresos_suscripcion'];
        $this->assertSame($indicadores['ingresos_suscripcion'], $ingresos['total']);
        $this->assertSame('sube', $ingresos['tendencia']['direccion']);
        $this->assertSame(200.0, $ingresos['tendencia']['porcentaje']);
    }

    // --- Índices -------------------------------------------------------------

    public function test_las_consultas_de_metricas_tienen_sus_indices(): void
    {
        $esperados = [
            'pagos' => ['ix_pagos_tipo_estado_fecha', ['tipo', 'estado', 'fecha_pago']],
            'ventas_directas' => ['ix_ventas_directas_fecha', ['fecha_venta']],
            'suscripciones' => ['ix_suscripciones_estado_plan', ['estado', 'plan_id', 'distribuidora_id']],
            'actividad_diaria' => ['ix_actividad_diaria_fecha_usuario', ['fecha', 'usuario_id']],
        ];

        foreach ($esperados as $tabla => [$nombre, $columnas]) {
            $indice = collect(Schema::getIndexes($tabla))->firstWhere('name', $nombre);

            $this->assertNotNull($indice, "Falta el índice {$nombre} en {$tabla}.");
            $this->assertSame($columnas, $indice['columns'], "El índice {$nombre} no tiene las columnas en el orden esperado.");
        }
    }

    // --- Caché ---------------------------------------------------------------

    public function test_la_misma_vista_se_sirve_de_lo_guardado(): void
    {
        $this->comoAdminGeneral();
        [$indicadores, $series] = $this->espias();

        Livewire::test('admin.metricas')->call('cargar')->assertOk();
        Livewire::test('admin.metricas')->call('cargar')->assertOk();

        $this->assertSame(1, $indicadores->llamadas, 'Los indicadores se recalcularon para la misma vista.');
        $this->assertSame(1, $series->llamadas, 'Las series se recalcularon para la misma vista.');
    }

    public function test_otro_filtro_se_calcula_aparte(): void
    {
        $this->comoAdminGeneral();
        [$indicadores, $series] = $this->espias();

        Livewire::test('admin.metricas')->call('cargar')->set('dias', '7')->assertOk();

        $this->assertSame(2, $indicadores->llamadas, 'Un periodo distinto se sirvió con lo guardado de otro.');
        $this->assertSame(2, $series->llamadas);
    }

    public function test_actualizar_ahora_recalcula_la_vista(): void
    {
        $this->comoAdminGeneral();
        $this->pago(100);

        Livewire::test('admin.metricas')->call('cargar')->assertSee('$100.00 MXN', false);

        $this->pago(50);

        // Sin actualizar se sigue viendo lo guardado, y la pantalla lo dice.
        $componente = Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertSee('$100.00 MXN', false)
            ->assertDontSee('$150.00 MXN', false)
            ->assertSee('data-ultimo-calculo', false);

        $componente->call('actualizar')
            ->assertSee('$150.00 MXN', false);
    }

    public function test_la_pantalla_dice_a_que_hora_se_calcularon_las_cifras(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 14:07:00', 'America/Mexico_City'));
        $this->comoAdminGeneral();

        $componente = Livewire::test('admin.metricas');

        // Mientras carga no hay hora que decir.
        $componente->assertDontSee('data-ultimo-calculo', false);

        $componente->call('cargar')
            ->assertSee('Cifras calculadas a las 14:07 (hora de Ciudad de México)', false)
            ->assertSee('Se guardan 5 minutos', false)
            ->assertSee('Actualizar ahora');
    }

    public function test_un_error_no_se_queda_guardado(): void
    {
        $cache = app(CacheDeMetricas::class);
        [$desde, $hasta] = $this->rango(30);

        try {
            $cache->recordar('series', $desde, $hasta, null, null, fn () => throw new \RuntimeException('falló la consulta'));
            $this->fail('El error del cálculo se tragó en vez de salir.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falló la consulta', $e->getMessage());
        }

        $this->assertFalse(Cache::has($cache->llave('series', $desde, $hasta, null, null)), 'Se guardó un cálculo que falló.');

        $resultado = $cache->recordar('series', $desde, $hasta, null, null, fn () => ['ok' => true]);
        $this->assertSame(['ok' => true], $resultado['datos'], 'Después de un error no se volvió a calcular.');
    }

    public function test_si_la_cache_falla_se_calcula_directo(): void
    {
        Exceptions::fake();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache caída'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache caída'));

        [$desde, $hasta] = $this->rango(30);

        $resultado = app(CacheDeMetricas::class)->recordar('indicadores', $desde, $hasta, null, null, fn () => ['usuarios' => 3]);

        $this->assertSame(['usuarios' => 3], $resultado['datos']);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'cache caída');
    }

    public function test_la_llave_cambia_con_el_dia_y_con_los_filtros(): void
    {
        $cache = app(CacheDeMetricas::class);
        [$desde, $hasta] = $this->rango(30);

        $base = $cache->llave('indicadores', $desde, $hasta, null, null);

        $this->assertNotSame($base, $cache->llave('indicadores', $desde->copy()->addDay(), $hasta->copy()->addDay(), null, null));
        $this->assertNotSame($base, $cache->llave('indicadores', $desde, $hasta, 1, null));
        $this->assertNotSame($base, $cache->llave('indicadores', $desde, $hasta, null, 1));
        $this->assertNotSame($base, $cache->llave('series', $desde, $hasta, null, null));
    }

    // --- Pendientes de fase 2 ------------------------------------------------

    public function test_sin_el_interruptor_no_se_ve_la_fase_2(): void
    {
        $this->comoAdminGeneral();

        $this->assertFalse(config('footwearpoint.metricas_fase2_visible'));

        $this->get('/admin/metricas')
            ->assertOk()
            ->assertDontSee('Lo que todavía no se mide (fase 2)')
            ->assertDontSee('data-pendientes-metricas', false)
            ->assertDontSee('Salud del servidor')
            ->assertDontSee('Quién está en línea ahora')
            ->assertDontSee('Suscripciones por vencer')
            ->assertDontSee('Volumen de pedidos')
            ->assertDontSee('Distribuidoras activas a lo largo del tiempo');
    }

    public function test_la_pantalla_enseña_lo_que_todavia_no_se_mide(): void
    {
        config(['footwearpoint.metricas_fase2_visible' => true]);

        $this->comoAdminGeneral();

        $respuesta = $this->get('/admin/metricas')
            ->assertOk()
            ->assertSee('Lo que todavía no se mide (fase 2)')
            ->assertSee('data-pendientes-metricas', false);

        $claves = array_column(PendientesDeMetricas::lista(), 'clave');
        $this->assertContains('salud-servidor', $claves);
        $this->assertContains('presencia', $claves);

        foreach (PendientesDeMetricas::lista() as $pendiente) {
            $respuesta->assertSee('data-pendiente="'.$pendiente['clave'].'"', false);
            $respuesta->assertSee($pendiente['titulo']);
            $respuesta->assertSee($pendiente['motivo']);
            $respuesta->assertSee($pendiente['falta']);
        }
    }

    /** La lista de la pantalla y la del documento son la misma. */
    public function test_la_lista_de_pendientes_esta_documentada(): void
    {
        $doc = file_get_contents(base_path('docs/metricas-fase2.md'));
        $this->assertNotFalse($doc);

        $seccion = substr($doc, (int) strpos($doc, '## Lo que todavía no se mide (fase 2)'));
        $seccion = substr($seccion, 0, (int) strpos($seccion, '## Sin secretos en la pantalla'));

        $titulos = array_column(PendientesDeMetricas::lista(), 'titulo');

        foreach ($titulos as $titulo) {
            $this->assertStringContainsString("### {$titulo}\n", $seccion, "El documento no trae «{$titulo}».");
        }

        $this->assertSame(count($titulos), substr_count($seccion, "\n### "), 'El documento y la pantalla no tienen los mismos pendientes.');
    }

    public function test_la_pantalla_no_enseña_secretos(): void
    {
        $secretos = [
            // Con el formato válido de una llave de 32 bytes, para que el
            // cifrado de la sesión no truene antes de llegar a la pantalla.
            'app.key' => 'base64:'.base64_encode('SECRETO-A37-0123456789abcdef0123'),
            'services.mercadopago.access_token' => 'SECRETO-MP-ACCESS-A37',
            'services.mercadopago.client_secret' => 'SECRETO-MP-CLIENT-A37',
            'services.mercadopago.webhook_secret' => 'SECRETO-MP-WEBHOOK-A37',
            'services.firebase.credenciales_base64' => 'SECRETO-FIREBASE-A37',
            'services.resend.key' => 'SECRETO-RESEND-A37',
            'services.ses.secret' => 'SECRETO-SES-A37',
            'mail.mailers.smtp.password' => 'SECRETO-SMTP-A37',
        ];
        config($secretos);

        $this->comoAdminGeneral();
        $this->pago(100);

        $paginas = [
            'primera carga' => $this->get('/admin/metricas')->assertOk()->getContent(),
            'con cifras' => Livewire::test('admin.metricas')->call('cargar')->html(),
        ];

        foreach ($paginas as $cual => $html) {
            foreach ($secretos as $clave => $valor) {
                $this->assertStringNotContainsString($valor, $html, "La pantalla ({$cual}) enseña {$clave}.");
            }

            $this->assertStringNotContainsString('SECRETO-A37-0123456789abcdef0123', $html, "La pantalla ({$cual}) enseña la llave de la app.");

            foreach (['APP_KEY', 'MP_ACCESS_TOKEN', 'MP_CLIENT_SECRET', 'MP_WEBHOOK_SECRET', 'DB_PASSWORD', 'SENTRY_LARAVEL_DSN', 'FIREBASE_CREDENTIALS'] as $variable) {
                $this->assertStringNotContainsString($variable, $html, "La pantalla ({$cual}) nombra la variable {$variable}.");
            }
        }
    }
}
