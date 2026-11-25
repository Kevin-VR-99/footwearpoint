<?php

namespace Tests\Feature\Catalogo;

use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-386 A26 — El logo de la línea (o su inicial) también en pedidos, venta,
 * la tienda pública y la app.
 *
 * Reusa x-ui.avatar-linea de TG-384 sin tocarlo. Con los datos demo,
 * Calzados Ramírez vende Impuls y Cklass y ninguna tiene logo: aquí se le
 * pone uno a Impuls y Cklass se queda sin él, para ver las dos caras.
 */
class LogoDeLineaEnVistasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const LOGO = 'https://cdn.ejemplo.test/lineas/impuls.png';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function impuls(): Linea
    {
        return Linea::where('nombre', 'Impuls')->firstOrFail();
    }

    private function cklass(): Linea
    {
        return Linea::where('nombre', 'Cklass')->firstOrFail();
    }

    private function darLogoAImpuls(): void
    {
        $this->impuls()->update(['logotipo_url' => self::LOGO]);
        $this->cklass()->update(['logotipo_url' => null]);
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** La pantalla de pedidos con un borrador ya creado: el paso 2 es donde se elige la línea. */
    private function pedidoConBorrador()
    {
        $this->como('admin@calzadosramirez.test');

        $maria = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', 'maria.lopez@revendedor.test')->value('id'))
            ->firstOrFail();
        $ramirez = Distribuidora::where('slug', 'calzados-ramirez')->value('id');

        return Livewire::test('pedidos.create')
            ->set('tipo', 'revendedor')
            ->set('propietario_id', (string) $maria->afiliaciones()->firstOrFail()->id)
            ->set('sucursal_id', (string) Sucursal::where('distribuidora_id', $ramirez)->where('es_principal', true)->value('id'))
            ->call('crearBorrador');
    }

    private function imgDelLogo(string $nombre, string $url = self::LOGO): string
    {
        return '<img src="'.e($url).'" alt="Logotipo de '.e($nombre).'"';
    }

    // ---------------------------------------------------------------
    // Pedidos
    // ---------------------------------------------------------------

    public function test_pedidos_sin_linea_elegida_no_enseña_logo(): void
    {
        $this->darLogoAImpuls();
        $this->pedidoConBorrador()
            ->assertSeeHtml('id="create-linea-id"')
            ->assertDontSeeHtml('data-logo-linea="pedido"');
    }

    public function test_pedidos_enseña_el_logo_de_la_linea_elegida(): void
    {
        $this->darLogoAImpuls();
        $this->pedidoConBorrador()
            ->set('linea_id', (string) $this->impuls()->id)
            ->assertSeeHtml('data-logo-linea="pedido"')
            ->assertSeeHtml($this->imgDelLogo('Impuls'));
    }

    public function test_pedidos_sin_logo_enseña_la_inicial(): void
    {
        $this->darLogoAImpuls();
        $this->pedidoConBorrador()
            ->set('linea_id', (string) $this->cklass()->id)
            ->assertSeeHtml('data-logo-linea="pedido"')
            ->assertSeeHtml('<span aria-hidden="true">C</span>')
            ->assertDontSeeHtml('alt="Logotipo de');
    }

    // ---------------------------------------------------------------
    // Punto de venta
    // ---------------------------------------------------------------

    public function test_venta_cada_renglon_trae_el_logo_de_su_linea(): void
    {
        $this->darLogoAImpuls();
        $this->como('empleado@calzadosramirez.test');

        $disponibles = Livewire::test('punto-venta.index')->instance()->disponibles;
        $this->assertNotEmpty($disponibles);

        foreach ($disponibles as $renglon) {
            $esperado = $renglon['linea'] === 'Impuls' ? self::LOGO : null;
            $this->assertSame($esperado, $renglon['linea_logotipo'], 'Renglón '.$renglon['clave']);
        }
    }

    public function test_venta_enseña_logo_o_inicial_en_la_columna_linea(): void
    {
        $this->darLogoAImpuls();
        $this->como('empleado@calzadosramirez.test');

        $pantalla = Livewire::test('punto-venta.index');
        $lineas = collect($pantalla->instance()->disponibles)->pluck('linea')->unique();

        $pantalla->assertSeeHtml('data-logo-linea="venta"');

        if ($lineas->contains('Impuls')) {
            $pantalla->assertSeeHtml($this->imgDelLogo('Impuls'));
        }
        if ($lineas->contains('Cklass')) {
            $pantalla->assertSeeHtml('<span aria-hidden="true">C</span>');
        }
        $this->assertTrue($lineas->contains('Impuls') || $lineas->contains('Cklass'));
    }

    // ---------------------------------------------------------------
    // Tienda pública
    // ---------------------------------------------------------------

    public function test_tienda_los_chips_de_linea_llevan_logo_o_inicial(): void
    {
        $this->darLogoAImpuls();

        $html = $this->get('/tienda/calzados-ramirez')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-logo-linea="tienda"'));
        $this->assertStringContainsString($this->imgDelLogo('Impuls'), $html);
        $this->assertStringContainsString('<span aria-hidden="true">C</span>', $html);
    }

    public function test_tienda_la_ficha_enseña_el_logo_de_la_linea(): void
    {
        $this->darLogoAImpuls();
        $ramirez = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $impuls = $this->impuls();

        $producto = Tenant::forzar($ramirez->id, fn () => app(CatalogoVisible::class)->consulta()
            ->whereHas('campana', fn ($c) => $c->where('linea_id', $impuls->id))
            ->whereHas('producto', fn ($p) => $p->where('activo', true))
            ->whereHas('disponibilidadPorVariante', fn ($d) => $d->where('estado', 'disponible'))
            ->firstOrFail());

        $this->get('/tienda/calzados-ramirez/productos/'.$producto->id)
            ->assertOk()
            ->assertSee('data-logo-linea="ficha"', false)
            ->assertSee($this->imgDelLogo('Impuls'), false);
    }

    public function test_la_url_del_logo_se_escapa(): void
    {
        $malicioso = 'https://cdn.ejemplo.test/x.png" onerror="alert(1)';
        $this->impuls()->update(['logotipo_url' => $malicioso]);

        $html = $this->get('/tienda/calzados-ramirez')->assertOk()->getContent();

        $this->assertStringNotContainsString('onerror="alert(1)"', $html);
        $this->assertStringContainsString($this->imgDelLogo('Impuls', $malicioso), $html);
    }

    // ---------------------------------------------------------------
    // API de la app
    // ---------------------------------------------------------------

    public function test_la_api_del_catalogo_manda_el_logo_de_la_linea(): void
    {
        $this->darLogoAImpuls();
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $datos = collect($this->getJson('/api/catalogo')->assertOk()->json('data'));
        $lineas = $datos->pluck('producto.linea')->filter();

        $this->assertNotEmpty($lineas);
        foreach ($lineas as $linea) {
            $this->assertArrayHasKey('logotipo_url', $linea);
            $this->assertSame($linea['nombre'] === 'Impuls' ? self::LOGO : null, $linea['logotipo_url']);
        }
        $this->assertTrue($lineas->contains('nombre', 'Impuls'));
    }
}
