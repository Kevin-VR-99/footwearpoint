<?php

namespace Tests\Feature\Catalogo;

use App\Models\Distribuidora;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Tienda\TiendaPublica;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-385 [A25] — Galería en catálogo maestro y productos de la distribuidora.
 *
 * Selector de imágenes cuando hay más de una (sin exigir 3 vistas),
 * con portada completa (4/3, object-contain), teclado, ARIA y
 * prefers-reduced-motion.
 */
class GaleriaDeProductoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Distribuidora $distribuidora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoAdminGeneral(): Usuario
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        return $admin;
    }

    private function comoDistribuidora(string $email = 'admin@calzadosramirez.test'): Usuario
    {
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidora->id);

        return $usuario;
    }

    private function publicacionVisible(): ProductoCampana
    {
        $this->comoDistribuidora();

        return app(CatalogoVisible::class)
            ->consulta()
            ->with('producto')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function limpiarFotos(ProductoCampana $publicacion): void
    {
        ProductoImagen::query()
            ->where('producto_campana_id', $publicacion->id)
            ->delete();
    }

    private function crearFoto(
        ProductoCampana $publicacion,
        string $nombre,
        int $orden,
        bool $principal = false
    ): ProductoImagen {
        return ProductoImagen::create([
            'producto_campana_id' => $publicacion->id,
            'url'                 => "https://ejemplo.test/{$nombre}.jpg",
            'url_miniatura'       => "https://ejemplo.test/{$nombre}-min.webp",
            'url_mediana'         => "https://ejemplo.test/{$nombre}-med.webp",
            'orden'               => $orden,
            'es_principal'        => $principal,
        ]);
    }

    public function test_con_dos_o_mas_fotos_se_puede_cambiar_de_una_a_otra_en_el_maestro_y_en_el_detalle_de_la_distribuidora(): void
    {
        $pc = $this->publicacionVisible();
        $this->limpiarFotos($pc);

        $principal = $this->crearFoto($pc, 'frente', orden: 2, principal: true);
        $lateral = $this->crearFoto($pc, 'perfil', orden: 1, principal: false);

        // 1. Catálogo maestro (admin general)
        $this->comoAdminGeneral();

        $maestro = Livewire::test('admin.catalogo-maestro')
            ->call('abrirFotos', $pc->id);

        $maestro
            ->assertSee('data-galeria-producto', false)
            ->assertSee('data-galeria-miniaturas', false)
            ->assertSee('data-galeria-miniatura="0"', false)
            ->assertSee('data-galeria-miniatura="1"', false)
            ->assertSee('elegir(0)', false)
            ->assertSee('elegir(1)', false)
            ->assertSee('actual().portada', false)
            ->assertSee('aspect-[4/3]', false)
            ->assertSee('object-contain', false)
            ->assertSee('max-w-5xl', false)
            ->assertSee($principal->urlMediana(), false)
            ->assertSee($lateral->urlMiniatura(), false)
            ->assertSee($lateral->urlMediana(), false)
            ->assertDontSee('object-cover', false);

        // 2. Detalle del producto en la distribuidora
        $this->comoDistribuidora();

        $detalle = Livewire::test('catalogo.producto-detalle', ['producto' => $pc->producto_id]);

        $detalle
            ->assertSee('data-galeria-producto', false)
            ->assertSee('data-galeria-miniaturas', false)
            ->assertSee('data-galeria-miniatura="0"', false)
            ->assertSee('data-galeria-miniatura="1"', false)
            ->assertSee('elegir(0)', false)
            ->assertSee('elegir(1)', false)
            ->assertSee('actual().portada', false)
            ->assertSee('aspect-[4/3]', false)
            ->assertSee('object-contain', false)
            ->assertSee($principal->urlMediana(), false)
            ->assertSee($lateral->urlMiniatura(), false)
            ->assertSee($lateral->urlMediana(), false)
            ->assertDontSee('object-cover', false);
    }

    public function test_en_la_tienda_publica_pulsar_miniatura_cambia_la_portada(): void
    {
        $this->comoDistribuidora();
        $visible = app(TiendaPublica::class)->catalogo($this->distribuidora)->first();
        $this->assertNotNull($visible);

        $pc = ProductoCampana::findOrFail($visible['id']);
        $this->limpiarFotos($pc);

        $principal = $this->crearFoto($pc, 'tienda-uno', orden: 1, principal: true);
        $segunda = $this->crearFoto($pc, 'tienda-dos', orden: 2, principal: false);

        $this->app['auth']->forgetGuards();

        $this->get(route('tienda.producto', [$this->distribuidora->slug, $pc->id]))
            ->assertOk()
            ->assertSee('data-galeria-producto', false)
            ->assertSee('data-galeria-miniaturas', false)
            ->assertSee('data-galeria-miniatura="0"', false)
            ->assertSee('data-galeria-miniatura="1"', false)
            ->assertSee('actual().portada', false)
            ->assertSee($principal->urlMediana(), false)
            ->assertSee($segunda->urlMiniatura(), false)
            ->assertSee($segunda->urlMediana(), false);

        Livewire::test('tienda.producto', [
            'slug' => $this->distribuidora->slug,
            'productoCampana' => (string) $pc->id,
        ])
            ->assertSee('aspect-[4/3]', false)
            ->assertSee('object-contain', false)
            ->assertDontSee('object-cover', false);
    }

    public function test_con_una_foto_no_aparece_el_selector(): void
    {
        $pc = $this->publicacionVisible();
        $this->limpiarFotos($pc);
        $unica = $this->crearFoto($pc, 'unica', orden: 1, principal: true);

        $componente = Blade::render('<x-ui.galeria :imagenes="$imagenes" alt="Tenis único" />', [
            'imagenes' => [$unica],
        ]);

        $this->assertStringContainsString('data-galeria-unica', $componente);
        $this->assertStringContainsString($unica->urlMediana(), $componente);
        $this->assertStringNotContainsString('data-galeria-miniaturas', $componente);
        $this->assertStringNotContainsString('data-galeria-miniatura', $componente);

        $this->comoAdminGeneral();
        Livewire::test('admin.catalogo-maestro')
            ->call('abrirFotos', $pc->id)
            ->assertSee('data-galeria-unica', false)
            ->assertSee($unica->urlMediana(), false)
            ->assertDontSee('data-galeria-miniaturas', false)
            ->assertDontSee('data-galeria-miniatura', false);

        $this->comoDistribuidora();
        Livewire::test('catalogo.producto-detalle', ['producto' => $pc->producto_id])
            ->assertSee('data-galeria-unica', false)
            ->assertSee($unica->urlMediana(), false)
            ->assertDontSee('data-galeria-miniaturas', false)
            ->assertDontSee('data-galeria-miniatura', false);
    }

    public function test_sin_fotos_no_se_rompe(): void
    {
        $pc = $this->publicacionVisible();
        $this->limpiarFotos($pc);

        $componente = Blade::render('<x-ui.galeria :imagenes="[]" alt="Sin fotos" />');

        $this->assertStringContainsString('data-galeria-vacia', $componente);
        $this->assertStringContainsString('data-sin-imagen-stock', $componente);
        $this->assertStringNotContainsString('data-galeria-miniaturas', $componente);

        $this->comoAdminGeneral();
        Livewire::test('admin.catalogo-maestro')
            ->call('abrirFotos', $pc->id)
            ->assertOk()
            ->assertSee('Todavía no tiene ninguna foto.')
            ->assertDontSee('data-galeria-miniaturas', false);

        $this->comoDistribuidora();
        Livewire::test('catalogo.producto-detalle', ['producto' => $pc->producto_id])
            ->assertOk()
            ->assertSee('data-galeria-vacia', false)
            ->assertSee('data-sin-imagen-stock', false)
            ->assertDontSee('data-galeria-miniaturas', false);
    }

    public function test_el_orden_respeta_principal_luego_orden_luego_id(): void
    {
        $pc = $this->publicacionVisible();
        $this->limpiarFotos($pc);

        $ordenDosMenorId = $this->crearFoto($pc, 'orden-dos-a', orden: 2, principal: false);
        $ordenUno = $this->crearFoto($pc, 'orden-uno', orden: 1, principal: false);
        $principal = $this->crearFoto($pc, 'principal-nueve', orden: 9, principal: true);
        $ordenDosMayorId = $this->crearFoto($pc, 'orden-dos-b', orden: 2, principal: false);

        $servicio = app(ImagenDeProducto::class);

        $todas = $servicio->todasPorProductoCampana([$pc->id]);
        $this->assertArrayHasKey($pc->id, $todas);
        $this->assertSame(
            [$principal->id, $ordenUno->id, $ordenDosMenorId->id, $ordenDosMayorId->id],
            array_column($todas[$pc->id], 'id'),
        );

        // porProductoCampana sigue devolviendo una sola URL (la principal).
        $unaPorLista = $servicio->porProductoCampana([$pc->id]);
        $this->assertSame($principal->urlMiniatura(), $unaPorLista[$pc->id] ?? null);

        // En el HTML del detalle, la portada inicial y el primer botón corresponden a la principal,
        // y las miniaturas aparecen exactamente en el orden principal -> orden -> id.
        $this->comoDistribuidora();
        $html = Livewire::test('catalogo.producto-detalle', ['producto' => $pc->producto_id])->html();

        $posPrincipal = strpos($html, $principal->urlMiniatura());
        $posOrdenUno = strpos($html, $ordenUno->urlMiniatura());
        $posOrdenDosA = strpos($html, $ordenDosMenorId->urlMiniatura());
        $posOrdenDosB = strpos($html, $ordenDosMayorId->urlMiniatura());

        $this->assertIsInt($posPrincipal);
        $this->assertIsInt($posOrdenUno);
        $this->assertIsInt($posOrdenDosA);
        $this->assertIsInt($posOrdenDosB);
        $this->assertTrue($posPrincipal < $posOrdenUno);
        $this->assertTrue($posOrdenUno < $posOrdenDosA);
        $this->assertTrue($posOrdenDosA < $posOrdenDosB);
    }

    public function test_no_se_exige_un_minimo_de_tres_fotos(): void
    {
        $pc = $this->publicacionVisible();
        $this->limpiarFotos($pc);

        $this->crearFoto($pc, 'vista-a', orden: 1, principal: true);
        $this->crearFoto($pc, 'vista-b', orden: 2, principal: false);

        $html = Blade::render('<x-ui.galeria :imagenes="$imagenes" alt="Zapato dos vistas" />', [
            'imagenes' => [
                'https://ejemplo.test/vista-a.jpg',
                'https://ejemplo.test/vista-b.jpg',
            ],
        ]);

        $this->assertStringContainsString('data-galeria-producto', $html);
        $this->assertStringContainsString('data-galeria-miniaturas', $html);
        $this->assertSame(2, substr_count($html, 'data-galeria-miniatura='));

        $this->comoDistribuidora();
        $detalle = Livewire::test('catalogo.producto-detalle', ['producto' => $pc->producto_id])->html();
        $this->assertSame(2, substr_count($detalle, 'data-galeria-miniatura='));
    }

    public function test_galeria_tiene_accesibilidad_teclado_distincion_activa_y_reduced_motion(): void
    {
        $html = Blade::render('<x-ui.galeria :imagenes="$imagenes" alt="Bota urbana" />', [
            'imagenes' => [
                'https://ejemplo.test/uno.jpg',
                'https://ejemplo.test/dos.jpg',
            ],
        ]);

        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Galería de Bota urbana"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('keydown.arrow-right', $html);
        $this->assertStringContainsString('keydown.arrow-left', $html);
        $this->assertStringContainsString('keydown.home', $html);
        $this->assertStringContainsString('keydown.end', $html);
        $this->assertStringContainsString('motion-reduce:transition-none', $html);
        $this->assertStringContainsString('ring-fp-primary', $html);
        $this->assertStringContainsString('object-contain', $html);
        $this->assertStringNotContainsString('object-cover', $html);
    }
}
