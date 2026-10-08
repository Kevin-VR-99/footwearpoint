<?php

namespace Tests\Feature\Seguridad;

use App\Exceptions\RespuestaErrorApi;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Stock\StockService;
use App\Support\ContextoOperativo;
use App\Support\MensajeError;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-224 (G3) — Mensajes amigables; el error técnico nunca llega a la
 * pantalla ni a la respuesta de la API. El detalle solo va al log.
 */
class SinErroresTecnicosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';
    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    /** Una excepción de base de datos como las reales: trae SQL y SQLSTATE. */
    private function errorDeBaseDeDatos(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into distribuidoras (clave_secreta) values (?)',
            ['valor-secreto'],
            new \Exception('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
        );
    }

    private function comoPanel(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoApi(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoAdminGeneral(): void
    {
        $admin = Usuario::create([
            'nombre'   => 'Admin General',
            'email'    => 'admin.general@footwearpoint.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        $registrar->forgetCachedPermissions();

        $this->actingAs($admin);
    }

    private function formularioDistribuidora()
    {
        return Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            ->set('nuevo_nombre_comercial', 'Zapatería Norte')
            ->set('nuevo_razon_social', 'Zapatería Norte S.A. de C.V.')
            ->set('admin_nombre', 'Laura Méndez')
            ->set('admin_email', 'laura@zapaterianorte.test')
            ->set('admin_password', 'clave-segura-1');
    }

    /** Pedido de María (revendedora) ya enviado, para abrirlo en el panel. */
    private function pedidoEnviadoDeMaria(): int
    {
        $this->comoApi(self::MARIA);

        $maria = Usuario::where('email', self::MARIA)->firstOrFail();
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $distribuidora->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->firstOrFail();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    // ---------------------------------------------------------------
    // Panel (Livewire)
    // ---------------------------------------------------------------

    public function test_alta_de_distribuidora_no_muestra_el_error_de_base_de_datos(): void
    {
        Exceptions::fake();
        $this->comoAdminGeneral();

        $error = $this->errorDeBaseDeDatos();
        $this->mock(CrearDistribuidoraAction::class)
            ->shouldReceive('ejecutar')->once()->andThrow($error);

        $componente = $this->formularioDistribuidora()
            ->call('crearDistribuidora')
            ->assertHasErrors(['nuevo_nombre_comercial'])
            ->assertSet('mensaje', '');

        $this->assertSame(
            'No se pudo crear la distribuidora. Intenta de nuevo.',
            $componente->errors()->first('nuevo_nombre_comercial')
        );
        $componente->assertDontSee('SQLSTATE')->assertDontSee('clave_secreta');

        Exceptions::assertReported(fn (QueryException $e) => $e === $error);
    }

    public function test_alta_de_distribuidora_muestra_el_mensaje_de_validacion_en_su_campo(): void
    {
        Exceptions::fake();
        $this->comoAdminGeneral();

        $this->mock(CrearDistribuidoraAction::class)
            ->shouldReceive('ejecutar')->once()
            ->andThrow(ValidationException::withMessages(['admin_email' => 'Ese correo ya tiene una cuenta.']));

        $componente = $this->formularioDistribuidora()
            ->call('crearDistribuidora')
            ->assertHasErrors(['admin_email']);

        $this->assertSame('Ese correo ya tiene una cuenta.', $componente->errors()->first('admin_email'));
        Exceptions::assertNotReported(ValidationException::class);
    }

    public function test_crear_borrador_de_pedido_muestra_mensaje_generico_y_reporta_el_error(): void
    {
        Exceptions::fake();
        $this->comoPanel(self::EMPLEADO);

        $this->mock(CrearPedidoBorradorAction::class)
            ->shouldReceive('ejecutar')->once()
            ->andThrow(new RuntimeException('detalle interno: tabla pedidos bloqueada'));

        Livewire::test('pedidos.create')
            ->set('tipo', 'cliente_directo')
            ->set('propietario_id', '1')
            ->set('sucursal_id', '1')
            ->call('crearBorrador')
            ->assertSet('errorMsg', 'No se pudo crear el borrador. Intenta de nuevo.')
            ->assertDontSee('detalle interno');

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'detalle interno'));
    }

    public function test_agregar_linea_a_un_pedido_inexistente_no_muestra_el_modelo(): void
    {
        $this->comoPanel(self::EMPLEADO);

        $variante = Variante::withoutGlobalScopes()->firstOrFail();

        Livewire::test('pedidos.create')
            ->set('pedidoId', 999999)
            ->set('linea_id', '1')
            ->set('producto_campana_id', '1')
            ->set('variante_id', (string) $variante->id)
            ->set('cantidad', '1')
            ->call('agregarLinea')
            ->assertSet('errorMsg', MensajeError::NO_ENCONTRADO)
            ->assertDontSee('No query results')
            ->assertDontSee('App\\Models');
    }

    public function test_registrar_pago_muestra_mensaje_generico_y_reporta_el_error(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        Exceptions::fake();
        $this->comoPanel(self::EMPLEADO);

        // Parcial: resumen() sigue siendo el real (lo usa mount); solo falla el cobro.
        $accion = Mockery::mock(RegistrarPagoPedidoAction::class, [app(RegistrarAuditoriaAction::class)])->makePartial();
        $accion->shouldReceive('ejecutar')->once()
            ->andThrow(new RuntimeException('SQLSTATE[40001]: Deadlock found'));
        $this->instance(RegistrarPagoPedidoAction::class, $accion);

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoMetodo', 'efectivo')
            ->set('pagoMonto', '10')
            ->call('registrarPago')
            ->assertSet('errorMsg', 'No se pudo registrar el pago. Intenta de nuevo.')
            ->assertDontSee('SQLSTATE');

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'Deadlock'));
    }

    public function test_entrada_de_stock_conserva_su_mensaje_y_ahora_reporta_el_error(): void
    {
        Exceptions::fake();
        $this->comoPanel(self::EMPLEADO);

        $stock = Mockery::mock(StockService::class, [app(ContextoOperativo::class)])->makePartial();
        $stock->shouldReceive('registrarEntrada')->once()->andThrow($this->errorDeBaseDeDatos());
        $this->instance(StockService::class, $stock);

        $variante = Variante::withoutGlobalScopes()->firstOrFail();

        Livewire::test('stock.index')
            ->set('entrada_variante_id', (string) $variante->id)
            ->set('entrada_cantidad', '3')
            ->call('registrarEntrada')
            ->assertSet('errorMsg', 'No se pudo registrar la entrada. Revisa los datos e intenta de nuevo.')
            ->assertDontSee('SQLSTATE');

        Exceptions::assertReported(QueryException::class);
    }

    // ---------------------------------------------------------------
    // API
    // ---------------------------------------------------------------

    public function test_registrar_empleado_por_api_no_devuelve_mensaje_archivo_ni_linea(): void
    {
        Exceptions::fake();
        $this->comoApi(self::ADMIN);

        // Falla forzada a la mitad del alta, como un error real de base de datos.
        DistribuidoraStaff::creating(fn () => throw new RuntimeException('SQLSTATE[HY000]: detalle secreto'));

        $respuesta = $this->postJson('/api/auth/register-empleado', [
            'nombre' => 'Empleado Nuevo',
            'email' => 'empleado.nuevo@calzadosramirez.test',
            'password' => 'password123',
        ]);

        $respuesta->assertStatus(500)
            ->assertExactJson(['message' => 'No se pudo registrar al empleado. Intenta de nuevo.'])
            ->assertJsonMissingPath('error')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line');
        $this->assertStringNotContainsString('SQLSTATE', $respuesta->getContent());

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'detalle secreto'));
    }

    public function test_un_500_en_la_api_nunca_muestra_detalles_aunque_app_debug_este_activo(): void
    {
        config(['app.debug' => true]);
        Exceptions::fake();
        $this->comoApi(self::EMPLEADO);

        $this->mock(StockService::class)
            ->shouldReceive('consultar')->once()->andThrow($this->errorDeBaseDeDatos());

        $respuesta = $this->getJson('/api/stock');

        $respuesta->assertStatus(500)
            ->assertExactJson(['message' => RespuestaErrorApi::ERROR_SERVIDOR]);

        foreach (['SQLSTATE', 'clave_secreta', 'valor-secreto', 'QueryException', '.php'] as $detalle) {
            $this->assertStringNotContainsString($detalle, $respuesta->getContent());
        }

        // El detalle sí queda en el log.
        Exceptions::assertReported(QueryException::class);
    }

    public function test_un_404_en_la_api_responde_en_espanol_sin_nombrar_el_modelo(): void
    {
        $this->comoApi(self::MARIA);

        $respuesta = $this->getJson('/api/pedidos/999999');

        $respuesta->assertNotFound()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_ENCONTRADO]);
        $this->assertStringNotContainsString('Models', $respuesta->getContent());
    }

    public function test_un_403_por_rol_en_la_api_responde_en_espanol(): void
    {
        $this->comoApi(self::MARIA);

        $respuesta = $this->getJson('/api/stock');

        $respuesta->assertForbidden()
            ->assertExactJson(['message' => RespuestaErrorApi::SIN_PERMISO]);
        $this->assertStringNotContainsString('does not have', $respuesta->getContent());
    }

    public function test_la_api_responde_json_en_espanol_aunque_no_se_pida_json(): void
    {
        // Sin "Accept: application/json": antes intentaba redirigir al login.
        $this->get('/api/stock')
            ->assertUnauthorized()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_AUTENTICADO]);

        $this->getJson('/api/auth/login')
            ->assertStatus(405)
            ->assertExactJson(['message' => RespuestaErrorApi::METODO_NO_PERMITIDO]);
    }

    public function test_la_validacion_de_la_api_conserva_su_formato_422(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['email']]);
    }

    // ---------------------------------------------------------------
    // Páginas de error del panel
    // ---------------------------------------------------------------

    public function test_pagina_404_en_espanol(): void
    {
        $this->get('/esta-pagina-no-existe')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertSee('Ir al inicio');
    }

    public function test_pagina_403_por_rol_en_espanol(): void
    {
        $this->comoPanel(self::ADMIN);

        $this->get('/admin')
            ->assertForbidden()
            ->assertSee('Acceso no permitido')
            ->assertSee(RespuestaErrorApi::SIN_PERMISO)
            ->assertDontSee('User does not have the right roles');
    }

    public function test_pedido_inexistente_en_el_panel_muestra_404_en_espanol(): void
    {
        $this->comoPanel(self::EMPLEADO);

        $this->get('/pedidos/999999')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertDontSee('No query results');
    }
}
