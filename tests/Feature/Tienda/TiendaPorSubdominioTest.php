<?php

namespace Tests\Feature\Tienda;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Services\Distribuidora\CambiarSubdominioAction;
use App\Services\Tienda\DominioTienda;
use App\Services\Tienda\EnlaceTienda;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-232 (G12/G13, E3-02) — La tienda de cada distribuidora en
 * {subdominio}.{FOOTWEARPOINT_DOMINIO}.
 *
 * El dominio se fija ANTES de arrancar la app (las rutas con dominio solo se
 * registran si está configurado).
 */
class TiendaPorSubdominioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const BASE = 'footwearpoint.test';

    private const PRINCIPAL = 'http://panel.ejemplo.test';

    private const RAMIREZ = 'calzados-ramirez';

    private const BOUTIQUE = 'boutique-del-calzado';

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        $this->fijarEntorno('FOOTWEARPOINT_DOMINIO', self::BASE);

        parent::setUp();

        // APP_URL va por config: el .env ya cargado en otra prueba ganaría al entorno.
        config(['app.url' => self::PRINCIPAL]);

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->fijarEntorno('FOOTWEARPOINT_DOMINIO', null);
    }

    private function fijarEntorno(string $nombre, ?string $valor): void
    {
        if ($valor === null) {
            putenv($nombre);
            unset($_ENV[$nombre], $_SERVER[$nombre]);

            return;
        }

        putenv("{$nombre}={$valor}");
        $_ENV[$nombre] = $valor;
        $_SERVER[$nombre] = $valor;
    }

    private function url(string $etiqueta, string $ruta = '/'): string
    {
        return 'http://'.$etiqueta.'.'.self::BASE.$ruta;
    }

    private function distribuidora(string $slug = self::RAMIREZ): Distribuidora
    {
        return Distribuidora::where('slug', $slug)->firstOrFail();
    }

    /** Un producto que la tienda sí muestra (el primero de su página). */
    private function productoDeLaTienda(string $etiqueta): int
    {
        $html = $this->get($this->url($etiqueta))->assertOk()->getContent();
        $patron = '#'.preg_quote($this->url($etiqueta, '/productos/'), '#').'(\d+)#';
        $this->assertSame(1, preg_match($patron, $html, $m), 'La tienda no enlaza sus productos en su subdominio');

        return (int) $m[1];
    }

    private function comoAdmin(string $email = self::ADMIN): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidora()->id);
    }

    // ---------------------------------------------------------------
    // 1. Se reconoce la distribuidora por la dirección
    // ---------------------------------------------------------------

    public function test_el_subdominio_abre_la_tienda_de_esa_distribuidora_y_no_otra(): void
    {
        // Sin subdominio propio, se encuentra por su slug.
        $this->get($this->url(self::RAMIREZ))
            ->assertOk()
            ->assertSee('Calzados Ramírez')
            ->assertSee('Escolar HGN 468')
            ->assertDontSee('Zapato charol tacón 7 cm');

        $this->get($this->url(self::BOUTIQUE))
            ->assertOk()
            ->assertSee('Zapato charol tacón 7 cm')
            ->assertDontSee('Escolar HGN 468');
    }

    public function test_primero_cuenta_el_subdominio_propio_y_luego_el_slug(): void
    {
        $this->distribuidora()->forceFill(['subdominio' => 'ramirez'])->save();

        $this->get($this->url('ramirez'))->assertOk()->assertSee('Calzados Ramírez');
        $this->get($this->url('RAMIREZ'))->assertOk()->assertSee('Calzados Ramírez');
        // El slug sigue sirviendo de respaldo.
        $this->get($this->url(self::RAMIREZ))->assertOk()->assertSee('Calzados Ramírez');
    }

    public function test_el_producto_se_abre_en_el_subdominio_y_el_de_otra_tienda_es_404(): void
    {
        $productoId = $this->productoDeLaTienda(self::RAMIREZ);

        $this->get($this->url(self::RAMIREZ, '/productos/'.$productoId))
            ->assertOk()
            ->assertSee($this->url(self::RAMIREZ, '/'), false);

        // Ramírez ocultó el JQ7143; Boutique sí lo vende.
        $oculto = ProductoCampana::query()
            ->whereRelation('producto', 'modelo', 'JQ7143')
            ->whereRelation('campana', 'estado', 'activa')
            ->firstOrFail();

        $this->get($this->url(self::RAMIREZ, '/productos/'.$oculto->id))->assertNotFound();
        $this->get($this->url(self::BOUTIQUE, '/productos/'.$oculto->id))->assertOk();
    }

    // ---------------------------------------------------------------
    // 2. Sin tienda: 404 amigable con enlace al directorio
    // ---------------------------------------------------------------

    public function test_subdominio_desconocido_inactivo_reservado_o_invalido_da_404_amigable(): void
    {
        $this->distribuidora(self::BOUTIQUE)->forceFill(['estado' => 'suspendida'])->save();

        foreach (['no-existe', self::BOUTIQUE, 'www', 'admin', 'api', 'a', '-raro-', 'con_guion_bajo'] as $etiqueta) {
            $this->get($this->url($etiqueta))
                ->assertNotFound()
                ->assertSee('Tienda no encontrada')
                ->assertSee(self::PRINCIPAL.'/marketplace', false)
                ->assertDontSee('Boutique del Calzado');
        }
    }

    // ---------------------------------------------------------------
    // 3. El panel, el login y la API no viven en un subdominio
    // ---------------------------------------------------------------

    public function test_el_panel_y_el_login_en_un_subdominio_van_al_dominio_principal(): void
    {
        $this->get($this->url(self::RAMIREZ, '/login'))->assertRedirect(self::PRINCIPAL.'/login');
        $this->get($this->url(self::RAMIREZ, '/dashboard?x=1'))->assertRedirect(self::PRINCIPAL.'/dashboard?x=1');
        $this->get($this->url(self::RAMIREZ, '/marketplace'))->assertRedirect(self::PRINCIPAL.'/marketplace');

        $this->getJson($this->url(self::RAMIREZ, '/api/marketplace'))->assertNotFound();

        // En el dominio principal todo sigue igual.
        $this->get(self::PRINCIPAL.'/login')->assertOk();
        $this->getJson(self::PRINCIPAL.'/api/marketplace')->assertOk();
    }

    public function test_una_sesion_del_panel_no_se_usa_en_el_subdominio(): void
    {
        $this->comoAdmin();

        $this->get($this->url(self::RAMIREZ, '/dashboard'))->assertRedirect(self::PRINCIPAL.'/dashboard');
    }

    // ---------------------------------------------------------------
    // 4. Livewire trabaja en el mismo host del subdominio
    // ---------------------------------------------------------------

    public function test_livewire_usa_el_host_del_subdominio_y_sus_filtros_funcionan_ahi(): void
    {
        $origen = $this->url(self::RAMIREZ, '');
        $html = $this->get($this->url(self::RAMIREZ))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#data-update-uri="([^"]+)"#', $html, $m), 'No está el script de Livewire');
        $this->assertStringStartsWith($origen.'/livewire', $m[1]);
        $this->assertStringNotContainsString(self::PRINCIPAL, $m[1]);
        $this->assertSame(1, preg_match('#<script src="([^"]+)"[^>]*data-update-uri#', $html, $s));
        $this->assertStringStartsWith($origen.'/', html_entity_decode($s[1]));

        // Una actualización real (buscar) en ese mismo host.
        $this->assertSame(1, preg_match('#wire:snapshot="([^"]+)"#', $html, $snap));
        $snapshot = html_entity_decode($snap[1], ENT_QUOTES);

        $respuesta = $this->withHeaders(['X-Livewire' => '1'])->postJson($m[1], [
            'components' => [[
                'snapshot' => $snapshot,
                'updates'  => ['busqueda' => 'Escolar'],
                'calls'    => [],
            ]],
        ]);

        $respuesta->assertOk();
        $nuevo = (string) $respuesta->json('components.0.effects.html');
        $this->assertStringContainsString('Escolar HGN 468', $nuevo);
        $this->assertStringNotContainsString('Nike Reax 8 NS SL', $nuevo);
        $this->assertStringContainsString($origen.'/productos/', $nuevo);
        $this->assertStringNotContainsString(self::PRINCIPAL.'/tienda/', $nuevo);

        // La siguiente petición al dominio principal no hereda el host de la tienda.
        $this->get(self::PRINCIPAL.'/login')
            ->assertOk()
            ->assertSee(self::PRINCIPAL.'/brand/logo-full-160.png', false)
            ->assertDontSee(self::BASE.'/brand', false);
    }

    // ---------------------------------------------------------------
    // 5. Enlaces y direcciones viejas
    // ---------------------------------------------------------------

    public function test_enlace_tienda_usa_el_subdominio(): void
    {
        $enlaces = app(EnlaceTienda::class);
        $ramirez = $this->distribuidora();

        $this->assertSame($this->url(self::RAMIREZ), $enlaces->url($ramirez));
        $this->assertSame($this->url(self::RAMIREZ, '/productos/5'), $enlaces->producto($ramirez, 5));

        $ramirez->forceFill(['subdominio' => 'ramirez'])->save();
        $this->assertSame($this->url('ramirez'), $enlaces->url($ramirez));

        // Un subdominio guardado que no sirve para DNS: se usa el slug.
        $ramirez->forceFill(['subdominio' => 'Mal_Nombre'])->save();
        $this->assertSame($this->url(self::RAMIREZ), $enlaces->url($ramirez));

        $ramirez->forceFill(['estado' => 'suspendida'])->save();
        $this->assertNull($enlaces->url($ramirez));
        $this->assertSame(self::PRINCIPAL.'/marketplace', $enlaces->marketplace());

        $this->getJson(self::PRINCIPAL.'/api/marketplace')
            ->assertOk()
            ->assertJsonMissing(['url_tienda' => self::PRINCIPAL.'/tienda/'.self::RAMIREZ]);
    }

    public function test_la_api_del_marketplace_da_la_direccion_con_subdominio(): void
    {
        $this->distribuidora()->forceFill(['marketplace_visible' => true])->save();

        $this->getJson(self::PRINCIPAL.'/api/marketplace')
            ->assertOk()
            ->assertJsonFragment(['slug' => self::RAMIREZ, 'url_tienda' => $this->url(self::RAMIREZ)]);
    }

    public function test_las_direcciones_viejas_redirigen_al_subdominio_con_sus_filtros(): void
    {
        $productoId = $this->productoDeLaTienda(self::RAMIREZ);

        $this->get(self::PRINCIPAL.'/tienda/'.self::RAMIREZ.'?q=nike')
            ->assertStatus(301)
            ->assertRedirect($this->url(self::RAMIREZ).'?q=nike');

        $this->get(self::PRINCIPAL.'/tienda/'.self::RAMIREZ.'/productos/'.$productoId)
            ->assertStatus(301)
            ->assertRedirect($this->url(self::RAMIREZ, '/productos/'.$productoId));

        // Una que no existe sigue dando el 404 de siempre.
        $this->get(self::PRINCIPAL.'/tienda/no-existe')->assertNotFound();
    }

    public function test_hosts_de_confianza(): void
    {
        $hosts = DominioTienda::hostsDeConfianza();

        $coincide = fn (string $host) => collect($hosts)->contains(fn ($p) => preg_match('{'.$p.'}i', $host) === 1);

        $this->assertTrue($coincide('footwearpoint.test'));
        $this->assertTrue($coincide('calzados-ramirez.footwearpoint.test'));
        $this->assertTrue($coincide('panel.ejemplo.test'));
        $this->assertTrue($coincide('healthcheck.railway.app'));
        $this->assertFalse($coincide('evil.com'));
        $this->assertFalse($coincide('footwearpoint.test.evil.com'));

        config(['app.dominio_base' => null]);
        $this->assertSame([], DominioTienda::hostsDeConfianza());
    }

    // ---------------------------------------------------------------
    // 6. El subdominio se elige en el perfil (E3-02)
    // ---------------------------------------------------------------

    public function test_el_admin_cambia_su_subdominio_y_queda_en_la_bitacora(): void
    {
        $this->comoAdmin();

        Livewire::test('distribuidora.perfil')
            ->assertSet('urlTienda', $this->url(self::RAMIREZ))
            ->set('subdominio', '  Ramirez-Calzado ')
            ->call('guardarSubdominio')
            ->assertHasNoErrors()
            ->assertSet('subdominio', 'ramirez-calzado')
            ->assertSet('urlTienda', $this->url('ramirez-calzado'));

        $this->assertSame('ramirez-calzado', $this->distribuidora()->subdominio);
        $auditoria = Auditoria::where('accion', 'distribuidora.subdominio_actualizado')->sole();
        $this->assertEquals(['subdominio' => 'ramirez-calzado'], $auditoria->datos_nuevos);

        $this->get($this->url('ramirez-calzado'))->assertOk()->assertSee('Calzados Ramírez');
    }

    public function test_el_subdominio_se_valida(): void
    {
        $this->distribuidora(self::BOUTIQUE)->forceFill(['subdominio' => 'boutique'])->save();
        $this->comoAdmin();

        $casos = [
            'ab'               => 'De 3 a 63',
            'con_guion_bajo'   => 'De 3 a 63',
            '-inicio'          => 'De 3 a 63',
            str_repeat('a', 64) => 'De 3 a 63',
            'www'              => 'reservado',
            'admin'            => 'reservado',
            'boutique'         => 'ya lo usa otra distribuidora',
            self::BOUTIQUE     => 'ya lo usa otra distribuidora',
            ''                 => 'Escribe el subdominio',
        ];

        foreach ($casos as $valor => $mensaje) {
            $componente = Livewire::test('distribuidora.perfil')
                ->set('subdominio', (string) $valor)
                ->call('guardarSubdominio')
                ->assertHasErrors('subdominio');

            $this->assertStringContainsString(
                mb_strtolower($mensaje),
                mb_strtolower((string) $componente->errors()->first('subdominio')),
                "Con «{$valor}»"
            );
        }

        $this->assertNull($this->distribuidora()->subdominio);
        $this->assertSame(0, Auditoria::where('accion', 'distribuidora.subdominio_actualizado')->count());
    }

    public function test_el_empleado_no_puede_cambiar_el_subdominio(): void
    {
        $this->comoAdmin(self::EMPLEADO);

        Livewire::test('distribuidora.perfil')
            ->set('subdominio', 'otro-nombre')
            ->call('guardarSubdominio')
            ->assertForbidden();

        $this->assertNull($this->distribuidora()->subdominio);
    }

    public function test_la_accion_permite_conservar_el_propio_slug(): void
    {
        $ramirez = $this->distribuidora();

        app(CambiarSubdominioAction::class)->ejecutar($ramirez, self::RAMIREZ);

        $this->assertSame(self::RAMIREZ, $ramirez->fresh()->subdominio);

        $this->expectException(ValidationException::class);
        app(CambiarSubdominioAction::class)->ejecutar($this->distribuidora(self::BOUTIQUE), self::RAMIREZ);
    }
}
