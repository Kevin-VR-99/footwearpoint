<?php

namespace Tests\Feature\Admin;

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\VentaDirecta;
use App\Services\Metricas\ConsultasDeMetricas;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Services\Metricas\SeriesDeMetricas;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-396 [A36] — Carga con skeleton y entrada de gráficas en Métricas.
 *
 * Mientras consulta (primera carga diferida y cambio de filtros) enseña
 * skeleton sin cifras inventadas ni retardos artificiales; al llegar los
 * datos reales quita el skeleton, entran las gráficas y los números avanzan
 * hacia el valor real; con prefers-reduced-motion se apagan brillo, recuento
 * y trazo.
 */
class SkeletonDeMetricasTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdminGeneral(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs($this->adminGeneral());
        Tenant::olvidarCache();
    }

    public function test_mientras_consulta_se_ve_el_skeleton_y_no_cifras_inventadas(): void
    {
        $this->comoAdminGeneral();

        $indicadoresEspia = new class(app(\App\Services\Metricas\PlanMasContratado::class), app(ConsultasDeMetricas::class)) extends IndicadoresDeMetricas
        {
            public int $llamadas = 0;

            public function calcular($desde, $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
            {
                $this->llamadas++;

                return parent::calcular($desde, $hasta, $distribuidoraId, $planId);
            }
        };

        $seriesEspia = new class(app(ConsultasDeMetricas::class)) extends SeriesDeMetricas
        {
            public int $llamadas = 0;

            public function calcular($desde, $hasta, ?int $distribuidoraId = null, ?int $planId = null): array
            {
                $this->llamadas++;

                return parent::calcular($desde, $hasta, $distribuidoraId, $planId);
            }
        };

        $this->instance(IndicadoresDeMetricas::class, $indicadoresEspia);
        $this->instance(SeriesDeMetricas::class, $seriesEspia);

        // Primera carga diferida: el servidor entrega filtros + skeleton sin ejecutar todavía las consultas pesadas.
        $respuesta = $this->get('/admin/metricas')->assertOk();
        $html = $respuesta->getContent();

        $this->assertSame(0, $indicadoresEspia->llamadas, 'Se calcularon los indicadores en la primera petición en vez de diferirlos.');
        $this->assertSame(0, $seriesEspia->llamadas, 'Se calcularon las series en la primera petición en vez de diferirlas.');

        $this->assertStringContainsString('wire:init="cargar"', $html);
        $this->assertStringContainsString('data-metricas-esqueleto="indicadores"', $html);
        $this->assertStringContainsString('data-metricas-esqueleto="series"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('fp-esqueleto', $html);
        $this->assertStringContainsString('data-filtros-metricas', $html);
        $this->assertStringContainsString('data-rango-metricas', $html);

        // Sin cifras ficticias mientras está cargando.
        $this->assertStringNotContainsString('data-indicador="usuarios-activos"', $html);
        $this->assertStringNotContainsString('data-serie="usuarios-activos"', $html);
        $this->assertStringNotContainsString('$0.00 MXN', $html);
    }

    public function test_al_terminar_sale_el_skeleton_y_llegan_los_datos_reales(): void
    {
        $this->comoAdminGeneral();

        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => 1250,
            'fecha_pago' => now(),
            'estado' => 'aplicado',
        ]);

        VentaDirecta::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'sucursal_id' => DB::table('sucursales')->where('distribuidora_id', $d->id)->value('id'),
            'folio' => 'V-'.uniqid(),
            'fecha_venta' => now(),
            'subtotal' => 840,
            'descuento' => 0,
            'total' => 840,
            'estado' => 'completada',
            'registrada_por_staff_id' => DB::table('distribuidora_staff')->where('distribuidora_id', $d->id)->value('id'),
        ]);

        $componente = Livewire::test('admin.metricas');

        // Estado inicial: skeleton activo y sin tarjetas reales.
        $componente
            ->assertSet('cargando', true)
            ->assertSee('data-metricas-esqueleto="indicadores"', false)
            ->assertSee('data-metricas-esqueleto="series"', false)
            ->assertDontSee('data-indicador="ingresos-suscripcion"', false);

        // Segunda petición (wire:init="cargar"): llegan los datos reales y desaparece el skeleton inicial.
        $componente
            ->call('cargar')
            ->assertSet('cargando', false)
            ->assertSee('data-indicador="ingresos-suscripcion"', false)
            ->assertSee('$1,250.00 MXN', false)
            ->assertSee('$840.00 MXN', false)
            ->assertSee('data-serie="ingresos-de-suscripcion"', false)
            ->assertSee('data-serie="ventas-de-las-tiendas"', false)
            ->assertSee('data-cifra-animada', false)
            ->assertSee('data-valor-final="1250"', false)
            ->assertSee('fp-serie-columna', false)
            ->assertSee('fp-aparecer', false)
            // También cubre skeleton al cambiar filtros (wire:loading + wire:target).
            ->assertSee('wire:loading', false)
            ->assertSee('wire:target="dias,distribuidora,plan,limpiarFiltros"', false);

        // El skeleton después de cargar solo vive dentro de los contenedores wire:loading, no activo en pantalla.
        $html = $componente->html();

        // Las cifras de las tarjetas son las reales, no solo las de las gráficas.
        $tarjeta = function (string $desde, string $hasta) use ($html): string {
            $inicio = strpos($html, $desde);
            $this->assertNotFalse($inicio, "No se encontró {$desde}.");
            $fin = strpos($html, $hasta, $inicio);
            $this->assertNotFalse($fin, "No se encontró {$hasta}.");

            return substr($html, $inicio, $fin - $inicio);
        };
        $ingresos = $tarjeta('data-indicador="ingresos-suscripcion"', 'data-indicador="ventas-tienda"');
        $this->assertStringContainsString('$1,250.00 MXN', $ingresos);
        $this->assertStringContainsString('data-valor-final="1250"', $ingresos);
        $ventas = $tarjeta('data-indicador="ventas-tienda"', 'data-indicador="plan-mas-contratado"');
        $this->assertStringContainsString('$840.00 MXN', $ventas);
        $this->assertStringContainsString('data-valor-final="840"', $ventas);

        $this->assertSame(1, substr_count($html, 'data-metricas-esqueleto="indicadores"'));
        $this->assertSame(1, substr_count($html, 'data-metricas-esqueleto="series"'));
        $this->assertMatchesRegularExpression(
            '/<div[^>]*wire:loading\b[^>]*>(?:<!--.*?-->|\s)*<div[^>]*data-metricas-esqueleto="indicadores"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<div[^>]*wire:loading\b[^>]*>(?:<!--.*?-->|\s)*<div[^>]*data-metricas-esqueleto="series"/s',
            $html
        );
    }

    public function test_al_cambiar_filtros_mantiene_rangos_y_actualiza_datos_con_skeleton_de_carga(): void
    {
        $this->comoAdminGeneral();

        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => 300,
            'fecha_pago' => now()->subDays(20),
            'estado' => 'aplicado',
        ]);

        Livewire::test('admin.metricas')
            ->call('cargar')
            ->assertSee('$300.00 MXN', false)
            ->set('dias', '7')
            ->assertDontSee('$300.00 MXN', false)
            ->assertSee('$0.00 MXN', false);
    }

    public function test_los_conteos_no_llevan_moneda_y_el_dinero_si(): void
    {
        $this->comoAdminGeneral();

        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => 450,
            'fecha_pago' => now(),
            'estado' => 'aplicado',
        ]);

        $html = Livewire::test('admin.metricas')->call('cargar')->html();

        $trozo = function (string $desde, string $hasta) use ($html): string {
            $inicio = strpos($html, $desde);
            $this->assertNotFalse($inicio, "No se encontró {$desde}.");
            $fin = strpos($html, $hasta, $inicio);
            $this->assertNotFalse($fin, "No se encontró {$hasta}.");

            return substr($html, $inicio, $fin - $inicio);
        };

        // Indicador de usuarios: ni «$» ni «MXN», tampoco dentro del recuento.
        $usuarios = $trozo('data-indicador="usuarios-activos"', 'data-indicador="ingresos-suscripcion"');
        $this->assertStringContainsString('data-formato="numero"', $usuarios);
        $this->assertStringNotContainsString('$', $usuarios, 'El indicador de usuarios activos lleva signo de pesos.');
        $this->assertStringNotContainsString('MXN', $usuarios, 'El indicador de usuarios activos cuenta personas en pesos.');

        // Plan más contratado: es un nombre, no un importe.
        $plan = $trozo('data-indicador="plan-mas-contratado"', 'data-dinero-separado');
        $this->assertStringNotContainsString('$', $plan, 'El plan más contratado lleva signo de pesos.');
        $this->assertStringNotContainsString('MXN', $plan, 'El plan más contratado lleva moneda.');

        // Gráfica de usuarios: su total animado tampoco lleva moneda.
        $serieUsuarios = $trozo('data-serie="usuarios-activos"', 'data-serie="ingresos-de-suscripcion"');
        $this->assertStringContainsString('data-formato="numero"', $serieUsuarios);
        $this->assertStringNotContainsString('MXN', $serieUsuarios, 'El total de la gráfica de usuarios lleva moneda.');

        // Ingresos y ventas sí van en MXN, en el texto y en el recuento.
        $ingresos = $trozo('data-indicador="ingresos-suscripcion"', 'data-indicador="ventas-tienda"');
        $this->assertStringContainsString('data-formato="dinero"', $ingresos);
        $this->assertStringContainsString('$450.00 MXN', $ingresos);

        $ventas = $trozo('data-indicador="ventas-tienda"', 'data-indicador="plan-mas-contratado"');
        $this->assertStringContainsString('data-formato="dinero"', $ventas);
        $this->assertStringContainsString('$0.00 MXN', $ventas);
    }

    public function test_prefers_reduced_motion_apaga_brillo_recuento_y_trazo(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));
        $bloques = preg_split('/@media\s*\(prefers-reduced-motion:\s*reduce\)/', $css);
        array_shift($bloques);

        $brilloApagado = false;
        $trazoApagado = false;
        $aparicionApagada = false;

        foreach ($bloques as $bloque) {
            $bloque = explode('@media', $bloque)[0];

            if (preg_match('/\.fp-esqueleto[^{]*\{[^}]*animation:\s*none\s*!important/', $bloque)) {
                $brilloApagado = true;
            }
            if (preg_match('/\.fp-serie-columna[^{]*\{[^}]*animation:\s*none\s*!important/', $bloque)) {
                $trazoApagado = true;
            }
            if (preg_match('/\.fp-aparecer[^{]*\{[^}]*animation:\s*none\s*!important/', $bloque)) {
                $aparicionApagada = true;
            }
        }

        $this->assertTrue($brilloApagado, 'Falta apagar el brillo de .fp-esqueleto en prefers-reduced-motion.');
        $this->assertTrue($trazoApagado, 'Falta apagar el trazo de .fp-serie-columna en prefers-reduced-motion.');
        $this->assertTrue($aparicionApagada, 'Falta apagar .fp-aparecer en prefers-reduced-motion.');

        $cifra = (string) file_get_contents(base_path('resources/views/components/ui/cifra.blade.php'));
        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $cifra);
        $this->assertStringContainsString('this.mostrado = this.finalTexto', $cifra);
    }

    public function test_no_hay_delay_forzado(): void
    {
        $archivos = [
            'resources/views/components/admin/⚡metricas.blade.php',
            'resources/views/components/admin/metricas-esqueleto.blade.php',
            'resources/views/components/ui/cifra.blade.php',
            'resources/views/components/ui/serie.blade.php',
        ];

        foreach ($archivos as $ruta) {
            $contenido = (string) file_get_contents(base_path($ruta));

            $this->assertDoesNotMatchRegularExpression('/\b(sleep|usleep|time_nanosleep)\s*\(/i', $contenido, "Delay PHP detectado en {$ruta}");
            $this->assertStringNotContainsString('setTimeout', $contenido, "setTimeout artificial detectado en {$ruta}");
            $this->assertStringNotContainsString('setInterval', $contenido, "setInterval artificial detectado en {$ruta}");
        }
    }
}
