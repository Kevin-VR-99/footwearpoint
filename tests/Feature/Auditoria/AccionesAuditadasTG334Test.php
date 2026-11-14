<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\CategoriaDirectorio;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\PlanSuscripcion;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Directorio\AsignarCategoriasDirectorioAction;
use App\Services\Directorio\CambiarEstadoCategoriaDirectorioAction;
use App\Services\Directorio\GuardarCategoriaDirectorioAction;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambiarVisibilidadMarketplaceAction;
use App\Services\Distribuidora\ConfiguracionCicloAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\FijarDescuentoLineaAction;
use App\Services\Distribuidora\FijarDescuentoMayoristaAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Services\Suscripcion\AsignarSuscripcionAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-334 (K36) — Las acciones que antes no dejaban rastro.
 *
 * Cada prueba revisa la acción, quién la hizo, el antes y el después (solo
 * lo que cambió) y de quién es el registro: lo del admin general sin
 * distribuidora (/admin/auditoria) y lo de cada distribuidora con la suya
 * (/auditoria). Nunca contraseñas, tokens ni la CLABE completa.
 */
class AccionesAuditadasTG334Test extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLAVE = 'ClaveSecreta-Nunca-En-Auditoria-9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    protected function tearDown(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        parent::tearDown();
    }

    private function comoAdminGeneral(): Usuario
    {
        $admin = $this->adminGeneral();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);
        Tenant::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        return $admin;
    }

    private function comoDistribuidora(): Usuario
    {
        $admin = Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id());

        return $admin;
    }

    private function registro(string $accion): Auditoria
    {
        return Auditoria::withoutGlobalScopes()->where('accion', $accion)->latest('id')->firstOrFail();
    }

    /** La acción, quién, de quién es el registro y que no lleve nada sensible. */
    private function assertRegistro(string $accion, Usuario $quien, ?int $distribuidoraId): Auditoria
    {
        $registro = $this->registro($accion);

        $this->assertSame($quien->id, $registro->usuario_id, "{$accion}: quién la hizo");
        $this->assertSame($distribuidoraId, $registro->distribuidora_id, "{$accion}: de qué distribuidora es");
        $this->assertSinDatosSensibles($registro);

        return $registro;
    }

    private function assertSinDatosSensibles(Auditoria $registro): void
    {
        $json = json_encode([$registro->datos_previos, $registro->datos_nuevos], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::CLAVE, (string) $json);
        foreach (RegistrarAuditoriaAction::CAMPOS_PROHIBIDOS as $campo) {
            $this->assertStringNotContainsString('"'.$campo.'"', (string) $json, "{$registro->accion} guardó {$campo}");
        }
    }

    private function pendiente(string $slug = 'pendiente-tg334'): Distribuidora
    {
        return app(CrearDistribuidoraAction::class)->ejecutar(
            ['nombre_comercial' => 'Pendiente TG-334', 'slug' => $slug],
            ['nombre' => 'Admin Pendiente', 'email' => $slug.'@tg334.test', 'password' => self::CLAVE],
            false,
        );
    }

    private function segunda(): Distribuidora
    {
        return Distribuidora::where('slug', DemoDistribuidoraSeeder::SEGUNDA_SLUG)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // El filtro común
    // ------------------------------------------------------------------

    public function test_el_filtro_quita_contrasenas_y_tokens_y_enmascara_la_clabe(): void
    {
        $limpio = RegistrarAuditoriaAction::limpiar([
            'nombre' => 'Ana',
            'password' => self::CLAVE,
            'Remember_Token' => 'abc',
            'mp_access_token' => 'APP_USR-123',
            'clabe' => '012180001234567897',
            'anidado' => ['token' => 'x', 'clabe' => '646180157000000004'],
        ]);

        $this->assertSame([
            'nombre' => 'Ana',
            'clabe' => '••••••••••••••7897',
            'anidado' => ['clabe' => '••••••••••••••0004'],
        ], $limpio);
        $this->assertNull(RegistrarAuditoriaAction::limpiar(null));
    }

    // ------------------------------------------------------------------
    // Admin general: sin distribuidora
    // ------------------------------------------------------------------

    public function test_crear_distribuidora_sin_contrasena_del_administrador(): void
    {
        $admin = $this->comoAdminGeneral();

        $distribuidora = $this->pendiente();

        $registro = $this->assertRegistro('distribuidora.creada', $admin, null);
        $this->assertSame($distribuidora->id, $registro->entidad_id);
        $this->assertNull($registro->datos_previos);
        $this->assertSame('Pendiente TG-334', $registro->datos_nuevos['nombre_comercial']);
        $this->assertSame('pendiente', $registro->datos_nuevos['estado']);
        $this->assertSame('pendiente-tg334@tg334.test', $registro->datos_nuevos['admin_email']);
    }

    public function test_aprobar_distribuidora(): void
    {
        $admin = $this->comoAdminGeneral();
        $distribuidora = $this->pendiente();

        app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora);

        $registro = $this->assertRegistro('distribuidora.aprobada', $admin, null);
        $this->assertSame(['estado' => 'pendiente'], $registro->datos_previos);
        $this->assertSame('activa', $registro->datos_nuevos['estado']);
    }

    public function test_rechazar_distribuidora_con_su_motivo(): void
    {
        $admin = $this->comoAdminGeneral();
        $distribuidora = $this->pendiente();

        app(RechazarDistribuidoraAction::class)->ejecutar($distribuidora, 'Datos incompletos');

        $registro = $this->assertRegistro('distribuidora.rechazada', $admin, null);
        $this->assertSame(['estado' => 'pendiente', 'motivo_rechazo' => null], $registro->datos_previos);
        $this->assertSame(['estado' => 'rechazada', 'motivo_rechazo' => 'Datos incompletos'], $registro->datos_nuevos);
    }

    public function test_suspender_y_reactivar_a_mano(): void
    {
        $admin = $this->comoAdminGeneral();
        $segunda = $this->segunda();

        app(SuspenderDistribuidoraAction::class)->ejecutar($segunda);
        app(ReactivarDistribuidoraAction::class)->ejecutar($segunda->fresh());

        $suspension = $this->assertRegistro('distribuidora.suspendida', $admin, null);
        $this->assertSame('activa', $suspension->datos_previos['estado']);
        $this->assertSame(['estado' => 'suspendida', 'motivo_suspension' => Distribuidora::MOTIVO_MANUAL], $suspension->datos_nuevos);

        $reactivacion = $this->assertRegistro('distribuidora.reactivada', $admin, null);
        $this->assertSame(['estado' => 'suspendida', 'motivo_suspension' => Distribuidora::MOTIVO_MANUAL], $reactivacion->datos_previos);
        $this->assertSame(['estado' => 'activa', 'motivo_suspension' => null], $reactivacion->datos_nuevos);
        $this->assertSame($segunda->id, $reactivacion->entidad_id);
    }

    public function test_asignar_suscripcion(): void
    {
        $admin = $this->comoAdminGeneral();
        $segunda = $this->segunda();
        $plan = PlanSuscripcion::where('activo', true)->firstOrFail();

        $suscripcion = app(AsignarSuscripcionAction::class)->ejecutar($segunda, $plan, 2, 1);

        $registro = $this->assertRegistro('suscripcion.asignada', $admin, null);
        $this->assertSame($suscripcion->id, $registro->entidad_id);
        $this->assertSame($segunda->id, $registro->datos_nuevos['distribuidora_id']);
        $this->assertSame($plan->id, $registro->datos_nuevos['plan_id']);
        $this->assertSame(2, $registro->datos_nuevos['meses']);
    }

    public function test_visibilidad_en_el_marketplace_solo_si_cambia(): void
    {
        $admin = $this->comoAdminGeneral();
        $segunda = $this->segunda();
        $antes = (bool) $segunda->marketplace_visible;

        app(CambiarVisibilidadMarketplaceAction::class)->ejecutar($segunda, ! $antes);
        app(CambiarVisibilidadMarketplaceAction::class)->ejecutar($segunda->fresh(), ! $antes);

        $registro = $this->assertRegistro('distribuidora.marketplace_visibilidad', $admin, null);
        $this->assertSame(['marketplace_visible' => $antes], $registro->datos_previos);
        $this->assertSame(['marketplace_visible' => ! $antes], $registro->datos_nuevos);
        $this->assertSame(1, Auditoria::withoutGlobalScopes()->where('accion', 'distribuidora.marketplace_visibilidad')->count());
    }

    public function test_planes_crear_editar_desactivar_y_activar(): void
    {
        $admin = $this->comoAdminGeneral();

        Livewire::test('admin.planes-index')
            ->call('nuevo')
            ->set('nombre', 'Plan TG-334')
            ->set('precio_base_mensual', '450')
            ->set('lineas_incluidas', '3')
            ->set('precio_linea_extra', '150')
            ->call('guardar')
            ->assertHasNoErrors();
        $plan = PlanSuscripcion::where('nombre', 'Plan TG-334')->firstOrFail();

        Livewire::test('admin.planes-index')
            ->call('editar', $plan->id)
            ->set('precio_base_mensual', '499')
            ->call('guardar')
            ->call('desactivar', $plan->id)
            ->call('activar', $plan->id);

        $creado = $this->assertRegistro('plan.creado', $admin, null);
        $this->assertSame('Plan TG-334', $creado->datos_nuevos['nombre']);

        $editado = $this->assertRegistro('plan.actualizado', $admin, null);
        $this->assertSame(['precio_base_mensual'], array_keys($editado->datos_nuevos), 'Solo lo que cambió.');
        $this->assertEquals(450, $editado->datos_previos['precio_base_mensual']);
        $this->assertEquals(499, $editado->datos_nuevos['precio_base_mensual']);

        // El antes sale de la base (1/0); se compara el valor, no el tipo.
        $this->assertEquals(['activo' => true], $this->assertRegistro('plan.desactivado', $admin, null)->datos_previos);
        $this->assertEquals(['activo' => true], $this->assertRegistro('plan.activado', $admin, null)->datos_nuevos);
    }

    public function test_categorias_del_directorio(): void
    {
        $admin = $this->comoAdminGeneral();
        $guardar = app(GuardarCategoriaDirectorioAction::class);

        $categoria = $guardar->ejecutar(['nombre' => 'Botas TG-334']);
        $guardar->ejecutar(['nombre' => 'Botas vaqueras TG-334'], $categoria);
        app(CambiarEstadoCategoriaDirectorioAction::class)->ejecutar($categoria->fresh(), false);
        app(CambiarEstadoCategoriaDirectorioAction::class)->ejecutar($categoria->fresh(), true);
        app(AsignarCategoriasDirectorioAction::class)->ejecutar($this->segunda(), [$categoria->id]);

        $this->assertSame('Botas TG-334', $this->assertRegistro('categoria_directorio.creada', $admin, null)->datos_nuevos['nombre']);

        $editada = $this->assertRegistro('categoria_directorio.actualizada', $admin, null);
        $this->assertSame(['nombre' => 'Botas TG-334'], $editada->datos_previos);
        $this->assertSame(['nombre' => 'Botas vaqueras TG-334'], $editada->datos_nuevos);

        $this->assertSame(['activa' => false], $this->assertRegistro('categoria_directorio.desactivada', $admin, null)->datos_nuevos);
        $this->assertSame(['activa' => true], $this->assertRegistro('categoria_directorio.activada', $admin, null)->datos_nuevos);

        $asignadas = $this->assertRegistro('distribuidora.categorias_directorio', $admin, null);
        $this->assertSame($this->segunda()->id, $asignadas->entidad_id);
        $this->assertContains($categoria->id, $asignadas->datos_nuevos['categorias']);
    }

    // ------------------------------------------------------------------
    // Distribuidora: con su distribuidora
    // ------------------------------------------------------------------

    public function test_clientes_directos_alta_y_cambio(): void
    {
        $admin = $this->comoDistribuidora();
        $acciones = app(GestionarClienteDirectoAction::class);

        $cliente = $acciones->crear(['nombre' => 'Cliente TG-334', 'telefono' => '9611234560']);
        $acciones->actualizar($cliente, ['notas' => 'Prefiere talla 25']);

        $alta = $this->assertRegistro('cliente_directo.creado', $admin, Tenant::id());
        $this->assertSame($cliente->id, $alta->entidad_id);
        $this->assertSame('Cliente TG-334', $alta->datos_nuevos['nombre']);

        $cambio = $this->assertRegistro('cliente_directo.actualizado', $admin, Tenant::id());
        $this->assertSame(['notas' => null], $cambio->datos_previos);
        $this->assertSame(['notas' => 'Prefiere talla 25'], $cambio->datos_nuevos);
    }

    public function test_clientes_mayoristas_alta_y_cambio(): void
    {
        $admin = $this->comoDistribuidora();
        $acciones = app(GestionarRevendedorAction::class);

        $afiliacion = $acciones->afiliar(['nombre' => 'Mayorista TG-334', 'telefono' => '9611234561']);
        $acciones->actualizar($afiliacion, ['nombre' => 'Mayorista TG-334 bis', 'notas' => 'Paga los viernes']);

        $alta = $this->assertRegistro('cliente_mayorista.creado', $admin, Tenant::id());
        $this->assertSame($afiliacion->id, $alta->entidad_id);
        $this->assertSame('Mayorista TG-334', $alta->datos_nuevos['nombre']);

        $cambio = $this->assertRegistro('cliente_mayorista.actualizado', $admin, Tenant::id());
        $this->assertSame(['nombre' => 'Mayorista TG-334', 'notas' => null], $cambio->datos_previos);
        $this->assertSame(['nombre' => 'Mayorista TG-334 bis', 'notas' => 'Paga los viernes'], $cambio->datos_nuevos);
    }

    public function test_configuracion_general_solo_lo_que_cambio(): void
    {
        $admin = $this->comoDistribuidora();
        $antes = (int) ConfiguracionDistribuidora::query()->value('dias_maximos_recoleccion');

        app(ActualizarConfiguracionDistribuidoraAction::class)->ejecutar(['dias_maximos_recoleccion' => $antes + 2]);

        $registro = $this->assertRegistro('configuracion.actualizada', $admin, Tenant::id());
        $this->assertEquals(['dias_maximos_recoleccion' => $antes], $registro->datos_previos);
        $this->assertEquals(['dias_maximos_recoleccion' => $antes + 2], $registro->datos_nuevos);
    }

    public function test_configuracion_de_ciclos_crear_editar_y_borrar(): void
    {
        $admin = $this->comoDistribuidora();
        $acciones = app(ConfiguracionCicloAction::class);

        $ciclo = $acciones->crear([
            'dia_cierre' => 3, 'hora_cierre' => '18:00', 'dia_solicitud_fabrica' => 4,
            'dias_estimados_llegada' => 7, 'activa' => false, 'dias_recepcion' => [5],
        ]);
        $acciones->actualizar($ciclo, ['dias_estimados_llegada' => 9, 'dias_recepcion' => [5, 6]]);

        $creada = $this->assertRegistro('ciclo.configuracion_creada', $admin, Tenant::id());
        $this->assertSame([5], $creada->datos_nuevos['dias_recepcion']);

        $editada = $this->assertRegistro('ciclo.configuracion_actualizada', $admin, Tenant::id());
        $this->assertEquals(['dias_estimados_llegada' => 7, 'dias_recepcion' => [5]], $editada->datos_previos);
        $this->assertEquals(['dias_estimados_llegada' => 9, 'dias_recepcion' => [5, 6]], $editada->datos_nuevos);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/distribuidora/ciclos-config/{$ciclo->id}")->assertOk();

        $borrada = $this->assertRegistro('ciclo.configuracion_eliminada', $admin, Tenant::id());
        $this->assertSame($ciclo->id, $borrada->entidad_id);
        $this->assertNull($borrada->datos_nuevos);
        $this->assertNull(ConfiguracionCiclo::find($ciclo->id));
    }

    public function test_descuento_por_linea_y_general_de_mayoreo(): void
    {
        $admin = $this->comoDistribuidora();
        $fila = DistribuidoraLinea::query()->vigentes()->firstOrFail();

        app(FijarDescuentoLineaAction::class)->ejecutar((int) $fila->linea_id, 12.5);
        app(FijarDescuentoMayoristaAction::class)->ejecutar(8);

        $linea = $this->assertRegistro('oferta.descuento_linea', $admin, Tenant::id());
        $this->assertSame($fila->id, $linea->entidad_id);
        $this->assertEquals(['descuento_mayorista_pct' => 12.5], $linea->datos_nuevos);

        $general = $this->assertRegistro('oferta.descuento_mayorista', $admin, Tenant::id());
        $this->assertEquals(['descuento_mayorista_pct' => 8], $general->datos_nuevos);
    }

    public function test_perfil_de_la_distribuidora(): void
    {
        $admin = $this->comoDistribuidora();
        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        $antes = $distribuidora->horario_publico;

        app(ActualizarPerfilDistribuidoraAction::class)->ejecutar($distribuidora, ['horario_publico' => 'Lunes a sábado de 9 a 7']);

        $registro = $this->assertRegistro('distribuidora.perfil_actualizado', $admin, Tenant::id());
        $this->assertSame(['horario_publico' => $antes], $registro->datos_previos);
        $this->assertSame(['horario_publico' => 'Lunes a sábado de 9 a 7'], $registro->datos_nuevos);
    }

    /** Cada distribuidora ve en /auditoria solo lo suyo; lo del admin general no. */
    public function test_lo_de_la_distribuidora_no_sale_del_admin_general_y_viceversa(): void
    {
        $this->comoAdminGeneral();
        $this->pendiente();

        $this->comoDistribuidora();
        app(GestionarClienteDirectoAction::class)->crear(['nombre' => 'Cliente visible TG-334']);

        $deLaDistribuidora = Auditoria::query()->pluck('accion')->all();
        $this->assertContains('cliente_directo.creado', $deLaDistribuidora);
        $this->assertNotContains('distribuidora.creada', $deLaDistribuidora);
    }
}
