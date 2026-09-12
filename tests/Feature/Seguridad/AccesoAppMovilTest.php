<?php

namespace Tests\Feature\Seguridad;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-134 — Primeras rutas abiertas a la app móvil: catálogo y notificaciones.
 *
 * Hasta este punto, todas las rutas de la API estaban limitadas a
 * admin_distribuidora|empleado. Estas pruebas cubren que revendedor y cliente
 * directo ya entren, que cada quien vea solo lo suyo, y que abrir estas dos
 * puertas NO les abrió las demás.
 */
class AccesoAppMovilTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function crearDistribuidoraB(): Distribuidora
    {
        $distribuidoraB = Distribuidora::create([
            'nombre_comercial' => 'Zapatería Rival (prueba)',
            'razon_social'     => 'Zapatería Rival S.A. de C.V.',
            'rfc'              => 'ZRI010101' . strtoupper(substr(uniqid(), -3)),
            'slug'             => 'zapateria-rival-' . uniqid(),
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);

        // Sus propios roles, igual que los crea DemoDistribuidoraSeeder.
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($distribuidoraB->id);

        foreach (['admin_distribuidora', 'empleado', 'revendedor', 'cliente_directo'] as $rol) {
            \Spatie\Permission\Models\Role::firstOrCreate([
                'name'       => $rol,
                'guard_name' => 'web',
                'team_id'    => $distribuidoraB->id,
            ]);
        }

        $registrar->forgetCachedPermissions();

        return $distribuidoraB;
    }

    /**
     * Una publicación completa y visible en el catálogo: hace falta que el
     * producto-campaña esté publicado Y que su campaña esté activa.
     */
    private function crearPublicacion(int $distribuidoraId, string $sufijo): ProductoCampana
    {
        return Tenant::forzar($distribuidoraId, function () use ($sufijo) {
            $marca = Marca::create(['nombre' => 'Marca ' . $sufijo, 'activa' => true]);
            $categoria = CategoriaProducto::create(['nombre' => 'Categoria ' . $sufijo, 'activa' => true]);

            $producto = Producto::create([
                'marca_id'     => $marca->id,
                'categoria_id' => $categoria->id,
                'modelo'       => 'MOD-' . $sufijo,
                'nombre'       => 'Producto ' . $sufijo,
                'activo'       => true,
            ]);

            $campana = Campana::create([
                'marca_id' => $marca->id,
                'nombre'   => 'Campana ' . $sufijo,
            ]);
            $campana->update(['estado' => 'activa']);

            $publicacion = ProductoCampana::create([
                'producto_id'               => $producto->id,
                'campana_id'                => $campana->id,
                'codigo_catalogo'           => 'CAT-' . $sufijo,
                'precio_mayorista'          => 500,
                'precio_minorista_sugerido' => 800,
            ]);
            $publicacion->update(['publicado' => true]);

            return $publicacion;
        });
    }

    private function asignarRol(Usuario $usuario, string $rol, int $distribuidoraId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($distribuidoraId);
        $usuario->assignRole($rol);
        $registrar->forgetCachedPermissions();
    }

    private function crearRevendedor(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Revendedor ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'Revendedor ' . $email,
            'email'      => $email,
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraId,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $this->asignarRol($usuario, 'revendedor', $distribuidoraId);

        return $usuario;
    }

    private function crearClienteDirecto(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Cliente ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'Cliente ' . $email,
            'email'            => $email,
            'estado'           => 'activo',
        ]);

        $this->asignarRol($usuario, 'cliente_directo', $distribuidoraId);

        return $usuario;
    }

    // ------------------------------------------------------------------
    // Catálogo
    // ------------------------------------------------------------------

    public function test_un_revendedor_consulta_el_catalogo_y_si_ve_el_precio_mayorista(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $publicacion = $this->crearPublicacion($distribuidoraA->id, 'PROPIA');
        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.catalogo@revendedor.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $fila = collect($respuesta->json('data'))->firstWhere('id', $publicacion->id);

        $this->assertNotNull($fila, 'El revendedor debería ver el catálogo de su distribuidora.');
        $this->assertArrayHasKey('precio_mayorista', $fila);
    }

    /**
     * El precio mayorista es lo que le cuesta al revendedor. Enseñárselo al
     * cliente final le revelaría el margen de la distribuidora.
     */
    public function test_un_cliente_directo_consulta_el_catalogo_pero_no_ve_el_precio_mayorista(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $publicacion = $this->crearPublicacion($distribuidoraA->id, 'PROPIA');
        $usuario = $this->crearClienteDirecto($distribuidoraA->id, 'cli.catalogo@cliente.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $fila = collect($respuesta->json('data'))->firstWhere('id', $publicacion->id);

        $this->assertNotNull($fila);
        $this->assertArrayNotHasKey('precio_mayorista', $fila);
        $this->assertArrayHasKey('precio_minorista_sugerido', $fila);
    }

    public function test_un_revendedor_no_ve_el_catalogo_de_otra_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $distribuidoraB = $this->crearDistribuidoraB();

        $propia = $this->crearPublicacion($distribuidoraA->id, 'PROPIA');
        $ajena  = $this->crearPublicacion($distribuidoraB->id, 'RIVAL');

        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.aislado.cat@revendedor.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $ids = collect($this->getJson('/api/catalogo')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($propia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    // ------------------------------------------------------------------
    // Notificaciones
    // ------------------------------------------------------------------

    public function test_un_revendedor_solo_ve_sus_propias_notificaciones(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.notif@revendedor.test');
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Notificacion::create([
            'usuario_id'       => $usuario->id,
            'distribuidora_id' => $distribuidoraA->id,
            'tipo'             => 'pedido_estado',
            'titulo'           => 'Aviso propio',
            'mensaje'          => 'Tu pedido va en camino.',
        ]);

        Notificacion::create([
            'usuario_id'       => $empleado->id,
            'distribuidora_id' => $distribuidoraA->id,
            'tipo'             => 'pedido_estado',
            'titulo'           => 'Aviso ajeno',
            'mensaje'          => 'Aviso interno de la distribuidora.',
        ]);

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $titulos = collect($this->getJson('/api/notificaciones')->assertOk()->json('data'))
            ->pluck('titulo');

        $this->assertContains('Aviso propio', $titulos);
        $this->assertNotContains('Aviso ajeno', $titulos);
    }

    public function test_un_revendedor_no_puede_marcar_leida_una_notificacion_ajena(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.notif2@revendedor.test');
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        $ajena = Notificacion::create([
            'usuario_id'       => $empleado->id,
            'distribuidora_id' => $distribuidoraA->id,
            'tipo'             => 'pedido_estado',
            'titulo'           => 'Aviso ajeno',
            'mensaje'          => 'Aviso interno.',
        ]);

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $this->postJson("/api/notificaciones/{$ajena->id}/marcar-leida")->assertStatus(404);

        $this->assertNull($ajena->fresh()->leida_at);
    }

    // ------------------------------------------------------------------
    // Que abrir estas dos puertas no haya abierto las demás
    // ------------------------------------------------------------------

    public function test_un_cliente_directo_no_entra_a_las_rutas_de_administracion(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearClienteDirecto($distribuidoraA->id, 'cli.bloqueado@cliente.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $this->getJson('/api/marcas')->assertStatus(403);
        $this->getJson('/api/productos')->assertStatus(403);
        $this->getJson('/api/distribuidora/revendedores')->assertStatus(403);
        $this->getJson('/api/stock')->assertStatus(403);
        $this->getJson('/api/reportes/resumen')->assertStatus(403);
    }

    public function test_un_revendedor_no_entra_a_las_rutas_de_administracion(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.bloqueado@revendedor.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $this->getJson('/api/marcas')->assertStatus(403);
        $this->getJson('/api/distribuidora/clientes-directos')->assertStatus(403);
        $this->postJson('/api/vales', [])->assertStatus(403);
    }

    /**
     * Pedidos y vales ya están abiertos, con su filtro de dueño dentro del
     * controlador. Que cada quien vea solo lo suyo se prueba aparte, en
     * AislamientoEntreRevendedoresTest.
     */
    public function test_un_revendedor_si_entra_a_pedidos_y_vales(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedor($distribuidoraA->id, 'rev.pedidos@revendedor.test');

        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $this->getJson('/api/pedidos')->assertOk();
        $this->getJson('/api/vales')->assertOk();
    }
}
