<?php

namespace Tests\Feature\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use App\Services\Tienda\EnlaceTienda;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-234 (G15) — Desde el marketplace se llega a la tienda pública de cada
 * distribuidora (TG-233): un enlace en cada tarjeta de /marketplace y los
 * campos slug y url_tienda en GET /api/marketplace.
 *
 * Datos del seeder: Calzados Ramírez está activa y visible.
 */
class EnlaceTiendaMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function ramirez(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function otra(string $nombre, string $estado = 'activa', bool $visible = true, array $categorias = []): Distribuidora
    {
        $distribuidora = Distribuidora::create([
            'nombre_comercial'    => $nombre,
            'razon_social'        => "{$nombre} S.A. de C.V.",
            'slug'                => str($nombre)->slug()->toString(),
            'estado'              => $estado,
            'marketplace_visible' => $visible,
            'fecha_solicitud'     => now(),
            'fecha_aprobacion'    => now(),
        ]);

        $distribuidora->categoriasDirectorio()->sync(
            CategoriaDirectorio::whereIn('nombre', $categorias)->pluck('id')->all()
        );

        return $distribuidora;
    }

    public function test_cada_tarjeta_del_marketplace_enlaza_a_su_tienda_y_la_tienda_abre(): void
    {
        $url = route('tienda', 'calzados-ramirez');

        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('href="'.$url.'"', false)
            ->assertSee('aria-label="Ver la tienda de Calzados Ramírez"', false)
            ->assertSeeText('Ver tienda');

        $this->get($url)->assertOk()->assertSee('Calzados Ramírez');
    }

    public function test_cada_distribuidora_enlaza_a_la_suya(): void
    {
        $this->otra('Zapatería Norte');

        Livewire::test('marketplace.index')
            ->assertSeeHtml('href="'.route('tienda', 'calzados-ramirez').'"')
            ->assertSeeHtml('href="'.route('tienda', 'zapateria-norte').'"')
            ->assertSeeHtml('aria-label="Ver la tienda de Zapatería Norte"');
    }

    public function test_al_filtrar_por_categoria_se_conserva_el_enlace(): void
    {
        $this->otra('Zapatería Norte', categorias: ['Infantil']);
        $infantil = CategoriaDirectorio::where('nombre', 'Infantil')->firstOrFail();

        Livewire::test('marketplace.index')
            ->call('filtrar', $infantil->id)
            ->assertSeeHtml('href="'.route('tienda', 'zapateria-norte').'"')
            ->assertDontSeeHtml('href="'.route('tienda', 'calzados-ramirez').'"');
    }

    public function test_solo_las_distribuidoras_activas_tienen_enlace(): void
    {
        $enlace = app(EnlaceTienda::class);

        $this->assertSame(route('tienda', 'calzados-ramirez'), $enlace->url($this->ramirez()));
        $this->assertNull($enlace->url($this->otra('Zapatería Suspendida', 'suspendida')));
        $this->assertNull($enlace->url($this->otra('Zapatería Pendiente', 'pendiente', false)));
        $this->assertNull($enlace->url($this->otra('Zapatería Rechazada', 'rechazada', false)));

        // Las que no están activas tampoco salen en el marketplace ni en la API.
        $this->get(route('marketplace'))
            ->assertDontSee('zapateria-suspendida')
            ->assertDontSee('Zapatería Suspendida');
        $this->getJson('/api/marketplace')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['slug' => 'zapateria-suspendida']);
    }

    public function test_la_api_del_marketplace_trae_slug_y_url_tienda(): void
    {
        $this->otra('Zapatería Norte');

        $this->getJson('/api/marketplace')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre_comercial', 'Calzados Ramírez')
            ->assertJsonPath('data.0.slug', 'calzados-ramirez')
            ->assertJsonPath('data.0.url_tienda', route('tienda', 'calzados-ramirez'))
            ->assertJsonPath('data.1.slug', 'zapateria-norte')
            ->assertJsonPath('data.1.url_tienda', route('tienda', 'zapateria-norte'))
            // Lo que ya traía sigue igual.
            ->assertJsonStructure(['data' => [[
                'id', 'nombre_comercial', 'slug', 'url_tienda', 'logotipo_url', 'descripcion_publica',
                'telefono_publico', 'email_publico', 'direccion_publica', 'horario_publico', 'categorias',
            ]]]);
    }

    public function test_url_tienda_es_absoluta_con_la_direccion_publica_de_la_app(): void
    {
        URL::forceRootUrl('https://footwearpoint-production.up.railway.app');
        URL::forceScheme('https');

        $this->getJson('/api/marketplace')
            ->assertOk()
            ->assertJsonPath('data.0.url_tienda', 'https://footwearpoint-production.up.railway.app/tienda/calzados-ramirez');
    }
}
