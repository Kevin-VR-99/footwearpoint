<?php

namespace Tests\Feature\Publico;

use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\PlanSuscripcion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-391 (A31) — Marketplace completo e inicio público con planes.
 *
 * Marketplace: filtro por línea, orden, vista de tarjetas o lista y las
 * líneas que vende cada distribuidora, sin perder el buscador de siempre.
 * Inicio: encabezado con el "desde" y atajos, planes con botón a contratar
 * y comparativa; montos de la base y flujo de alta intacto.
 */
class MarketplaceEInicioConPlanesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // Cada prueba arma su propio directorio: lo del seeder se esconde
        // para que los conteos y el orden no dependan de los datos demo.
        Distribuidora::query()->update(['marketplace_visible' => false]);
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    private function distribuidora(string $nombre, bool $visible = true, string $estado = 'activa'): Distribuidora
    {
        return Distribuidora::create([
            'nombre_comercial'    => $nombre,
            'razon_social'        => "{$nombre} S.A. de C.V.",
            'slug'                => str($nombre)->slug()->toString(),
            'estado'              => $estado,
            'marketplace_visible' => $visible,
            'fecha_solicitud'     => now(),
            'fecha_aprobacion'    => now(),
        ]);
    }

    private function linea(string $nombre, bool $activa = true): Linea
    {
        return Linea::create(['nombre' => $nombre, 'activa' => $activa]);
    }

    private function vende(Distribuidora $distribuidora, Linea $linea, bool $activa = true): void
    {
        DistribuidoraLinea::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'linea_id'         => $linea->id,
            'es_extra'         => false,
            'activa'           => $activa,
            'fecha_activacion' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Marketplace: filtro por línea
    // ------------------------------------------------------------------

    public function test_los_filtros_son_las_lineas_que_venden_las_distribuidoras_visibles(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        $oculta = $this->distribuidora('Zapatería Oculta', visible: false);

        $this->vende($norte, $alfa = $this->linea('Prueba Alfa'));
        $this->vende($oculta, $this->linea('Prueba Escondida'));
        $this->vende($norte, $this->linea('Prueba Retirada', activa: false));
        $this->vende($norte, $this->linea('Prueba Desactivada'), activa: false);

        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('Filtrar por línea')
            ->assertSee('data-filtro-linea="'.$alfa->id.'"', false)
            ->assertSee('Prueba Alfa')
            ->assertDontSee('Prueba Escondida')
            ->assertDontSee('Prueba Retirada')
            ->assertDontSee('Prueba Desactivada');
    }

    public function test_filtrar_por_linea_deja_solo_a_quien_la_vende(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        $sur = $this->distribuidora('Zapatería Sur');
        $this->vende($norte, $alfa = $this->linea('Prueba Alfa'));
        $this->vende($sur, $beta = $this->linea('Prueba Beta'));

        Livewire::test('marketplace.index')
            ->assertSee('Zapatería Norte')
            ->assertSee('Zapatería Sur')
            ->call('filtrarLinea', $alfa->id)
            ->assertSet('linea', (string) $alfa->id)
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Zapatería Sur')
            ->assertSee('venden Prueba Alfa')
            ->call('filtrarLinea')
            ->assertSet('linea', '')
            ->assertSee('Zapatería Sur');

        // El filtro vive en la dirección: un enlace compartido abre igual.
        $this->get(route('marketplace', ['linea' => $beta->id]))
            ->assertOk()
            ->assertSee('Zapatería Sur')
            ->assertDontSee('Zapatería Norte');
    }

    public function test_el_filtro_por_linea_se_combina_con_el_buscador(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        $centro = $this->distribuidora('Calzado Centro');
        $alfa = $this->linea('Prueba Alfa');
        $this->vende($norte, $alfa);
        $this->vende($centro, $alfa);

        Livewire::withQueryParams(['linea' => (string) $alfa->id, 'q' => 'norte'])
            ->test('marketplace.index')
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Calzado Centro')
            ->assertSee('Limpiar filtros')
            ->call('limpiarFiltros')
            ->assertSet('q', '')
            ->assertSet('linea', '')
            ->assertSee('Calzado Centro');
    }

    public function test_una_linea_que_no_existe_o_no_es_numero_se_ignora(): void
    {
        $this->vende($this->distribuidora('Zapatería Norte'), $this->linea('Prueba Alfa'));

        foreach (['999999', 'abc', '1 OR 1=1'] as $raro) {
            $this->get(route('marketplace', ['linea' => $raro]))
                ->assertOk()
                ->assertSee('Zapatería Norte')
                ->assertDontSee('Limpiar filtros');
        }
    }

    public function test_sin_resultados_ofrece_volver_a_ver_todas(): void
    {
        $this->vende($this->distribuidora('Zapatería Norte'), $this->linea('Prueba Alfa'));

        Livewire::test('marketplace.index')
            ->set('q', 'no-existe-xyz')
            ->assertSee('Ninguna distribuidora coincide con esa búsqueda.')
            ->assertSee('Ver todas las distribuidoras');
    }

    /**
     * Con sesión de una distribuidora el directorio no cambia: las líneas
     * no pasan por el scope de tenant (si pasaran, solo vería las suyas).
     */
    public function test_con_sesion_de_una_distribuidora_ve_las_lineas_de_todas(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        $this->vende($norte, $alfa = $this->linea('Prueba Alfa'));

        $this->actingAs(Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail());

        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('data-filtro-linea="'.$alfa->id.'"', false)
            ->assertSee('Zapatería Norte');

        $this->get(route('marketplace', ['linea' => $alfa->id]))
            ->assertOk()
            ->assertSee('Zapatería Norte');
    }

    // ------------------------------------------------------------------
    // Marketplace: presentación
    // ------------------------------------------------------------------

    public function test_cada_tarjeta_muestra_sus_lineas_con_enlace_a_la_tienda_filtrada(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        $this->vende($norte, $alfa = $this->linea('Prueba Alfa'));

        $html = $this->get(route('marketplace'))->assertOk()->getContent();

        $this->assertStringContainsString('Líneas que vende', $html);
        $this->assertStringContainsString('data-lineas-distribuidora', $html);
        $this->assertStringContainsString(e(route('tienda', $norte->slug).'?linea='.$alfa->id), $html);
    }

    public function test_mas_de_cinco_lineas_se_resumen_con_mas(): void
    {
        $norte = $this->distribuidora('Zapatería Norte');
        foreach (range(1, 7) as $i) {
            $this->vende($norte, $this->linea("Prueba {$i}"));
        }

        $this->get(route('marketplace'))->assertOk()->assertSee('+2 más');
    }

    public function test_se_puede_ver_como_lista_y_una_vista_rara_vuelve_a_tarjetas(): void
    {
        $this->vende($this->distribuidora('Zapatería Norte'), $this->linea('Prueba Alfa'));

        Livewire::test('marketplace.index')
            ->assertDontSee('data-vista-lista', false)
            ->call('cambiarVista', 'lista')
            ->assertSet('vista', 'lista')
            ->assertSee('data-vista-lista', false)
            ->assertSee('Zapatería Norte')
            ->assertSee('Ver tienda')
            ->call('cambiarVista', 'otra-cosa')
            ->assertSet('vista', 'tarjetas');

        $this->get(route('marketplace', ['vista' => 'lista']))
            ->assertOk()
            ->assertSee('data-vista-lista', false)
            ->assertSee('aria-pressed="true" data-vista="lista"', false);

        $this->get(route('marketplace', ['vista' => '<script>']))
            ->assertOk()
            ->assertDontSee('data-vista-lista', false);
    }

    public function test_ordenar_por_mas_lineas_primero(): void
    {
        $amplia = $this->distribuidora('Zapatería Amplia');
        $breve = $this->distribuidora('Abarrotes del Calzado');
        $this->vende($breve, $this->linea('Prueba Uno'));
        foreach (['Prueba Dos', 'Prueba Tres', 'Prueba Cuatro'] as $nombre) {
            $this->vende($amplia, $this->linea($nombre));
        }

        $porNombre = Livewire::test('marketplace.index')->html();
        $this->assertLessThan(strpos($porNombre, 'Zapatería Amplia'), strpos($porNombre, 'Abarrotes del Calzado'));

        $porLineas = Livewire::withQueryParams(['orden' => 'lineas'])->test('marketplace.index')->html();
        $this->assertLessThan(strpos($porLineas, 'Abarrotes del Calzado'), strpos($porLineas, 'Zapatería Amplia'));

        // Un orden que no existe cae en el de siempre.
        $raro = Livewire::withQueryParams(['orden' => 'precio'])->test('marketplace.index')->html();
        $this->assertLessThan(strpos($raro, 'Zapatería Amplia'), strpos($raro, 'Abarrotes del Calzado'));
    }

    public function test_el_encabezado_publico_lleva_atajos_al_marketplace_y_a_los_planes(): void
    {
        $html = $this->get(route('marketplace'))->assertOk()->getContent();

        $this->assertStringContainsString('data-nav-publica', $html);
        $this->assertStringContainsString('href="'.route('inicio').'#planes"', $html);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('marketplace'), '/').'"\s+aria-current="page"/', $html);
    }

    // ------------------------------------------------------------------
    // Inicio: encabezado, planes y comparativa
    // ------------------------------------------------------------------

    public function test_el_encabezado_dice_desde_cuanto_con_el_plan_mas_barato_de_la_base(): void
    {
        $masBarato = PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->firstOrFail();

        $this->get('/')
            ->assertOk()
            ->assertSee('data-plan-desde', false)
            ->assertSee('Planes desde')
            ->assertSee('$'.number_format((float) $masBarato->precio_base_mensual, 2).' MXN al mes')
            ->assertSee('href="#comparar-planes"', false)
            ->assertSee('href="#como-funciona"', false);

        // Si cambia en el panel, cambia aquí.
        $masBarato->update(['precio_base_mensual' => 111.00]);
        $this->get('/')->assertOk()->assertSee('$111.00 MXN al mes');
    }

    public function test_cada_plan_tiene_su_boton_a_contratar_con_el_plan_marcado(): void
    {
        $planes = PlanSuscripcion::where('activo', true)->get();
        $this->assertNotEmpty($planes);

        $respuesta = $this->get('/')->assertOk();

        foreach ($planes as $plan) {
            $respuesta->assertSee('data-contratar-plan="'.$plan->id.'"', false)
                ->assertSee('Contratar '.$plan->nombre)
                ->assertSee(e(route('alta.solicitar', ['plan' => $plan->id])), false);
        }
    }

    public function test_la_comparativa_muestra_los_montos_de_la_base(): void
    {
        $planes = PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->get();
        $this->assertGreaterThan(1, $planes->count(), 'El seeder tendría que dejar más de un plan');

        $html = $this->get('/')->assertOk()->getContent();
        $tabla = substr($html, strpos($html, 'data-comparativa-planes'));
        $tabla = substr($tabla, 0, strpos($tabla, '</table>'));

        $this->assertStringContainsString('Compara los planes', $tabla);
        foreach ($planes as $plan) {
            $this->assertStringContainsString($plan->nombre, $tabla);
            $this->assertStringContainsString('$'.number_format((float) $plan->precio_base_mensual, 2), $tabla);
            $this->assertStringContainsString('$'.number_format((float) $plan->precio_linea_extra, 2), $tabla);
        }
    }

    public function test_con_un_solo_plan_no_sale_la_comparativa(): void
    {
        $primero = PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->firstOrFail();
        PlanSuscripcion::where('id', '!=', $primero->id)->update(['activo' => false]);

        $this->get('/')->assertOk()
            ->assertSee($primero->nombre)
            ->assertDontSee('data-comparativa-planes', false);
    }

    public function test_con_sesion_no_ofrece_contratar(): void
    {
        $this->actingAs(Usuario::where('email', DemoDistribuidoraSeeder::EMPLEADO)->firstOrFail());

        $this->get('/')->assertOk()
            ->assertSee('Compara los planes')
            ->assertDontSee('data-contratar-plan', false);
    }

    // ------------------------------------------------------------------
    // Solicitar mi alta: solo preselecciona
    // ------------------------------------------------------------------

    public function test_el_alta_llega_con_el_plan_marcado(): void
    {
        $plan = PlanSuscripcion::where('activo', true)->orderByDesc('precio_base_mensual')->firstOrFail();

        Livewire::withQueryParams(['plan' => (string) $plan->id])
            ->test('publico.solicitar-alta')
            ->assertSet('plan_id', (string) $plan->id);
    }

    public function test_un_plan_inactivo_o_raro_no_se_marca(): void
    {
        $plan = PlanSuscripcion::where('activo', true)->firstOrFail();
        $plan->update(['activo' => false]);

        foreach ([(string) $plan->id, '999999', 'abc', '-1'] as $raro) {
            Livewire::withQueryParams(['plan' => $raro])
                ->test('publico.solicitar-alta')
                ->assertSet('plan_id', '');
        }
    }
}
