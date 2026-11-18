<?php

namespace Tests\Feature\Admin;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarSesionAction;
use App\Support\PersonalPlataforma;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-335 (K37) — Listado y ficha del personal para el admin general.
 */
class PersonalAdminGeneralTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLIENTE = 'jose.hernandez@cliente.test';

    private const MAYORISTA = 'maria.lopez@revendedor.test';

    private int $consecutivo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    protected function tearDown(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        parent::tearDown();
    }

    private function cuenta(string $correo): Usuario
    {
        return Usuario::where('email', $correo)->firstOrFail();
    }

    private function comoAdminGeneral(): Usuario
    {
        $admin = $this->cuenta(AdminGeneralSeeder::EMAIL);
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return $admin;
    }

    private function ramirez(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->value('id');
    }

    private function boutique(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SEGUNDA_SLUG)->value('id');
    }

    private function darRol(Usuario $usuario, string $rol, int $equipo): void
    {
        $registrar = app(PermissionRegistrar::class);
        $anterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($equipo);
            $usuario->unsetRelation('roles');
            $usuario->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web', 'team_id' => $equipo]));
            $usuario->unsetRelation('roles');
        } finally {
            $registrar->setPermissionsTeamId($anterior);
        }
    }

    private function empleadoNuevo(int $distribuidoraId, array $datos = []): Usuario
    {
        $this->consecutivo++;

        $usuario = Usuario::create($datos + [
            'nombre' => 'Persona '.$this->consecutivo,
            'email' => 'persona'.$this->consecutivo.'@tg335.test',
            'password' => Hash::make('clave-de-prueba-9'),
            'estado' => 'activo',
        ]);

        $this->darRol($usuario, 'empleado', $distribuidoraId);

        return $usuario;
    }

    /** @return list<string> */
    private function correos(array $filtros = []): array
    {
        $prueba = Livewire::test('admin.usuarios-index');

        foreach ($filtros as $campo => $valor) {
            $prueba->set($campo, $valor);
        }

        return $prueba->instance()->personal->pluck('email')->all();
    }

    private function movimiento(Usuario $usuario, string $accion, ?string $fecha = null, array $nuevos = []): Auditoria
    {
        $registro = Auditoria::withoutGlobalScopes()->create([
            'usuario_id' => $usuario->id,
            'distribuidora_id' => $this->ramirez(),
            'accion' => $accion,
            'entidad_tipo' => 'usuario',
            'entidad_id' => $usuario->id,
            'datos_nuevos' => $nuevos ?: null,
        ]);

        if ($fecha !== null) {
            $registro->forceFill(['created_at' => $fecha])->save();
        }

        return $registro;
    }

    // ------------------------------------------------------------------
    // Quién aparece
    // ------------------------------------------------------------------

    public function test_lista_solo_personal(): void
    {
        $this->comoAdminGeneral();

        $correos = $this->correos();

        foreach ([AdminGeneralSeeder::EMAIL, DemoDistribuidoraSeeder::ADMIN, DemoDistribuidoraSeeder::EMPLEADO, DemoDistribuidoraSeeder::SEGUNDA_ADMIN] as $personal) {
            $this->assertContains($personal, $correos);
        }

        $this->assertNotContains(self::CLIENTE, $correos);
        $this->assertNotContains(self::MAYORISTA, $correos);
    }

    public function test_quien_tambien_es_cliente_muestra_solo_sus_roles_de_personal(): void
    {
        $this->comoAdminGeneral();
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
        $this->darRol($empleado, 'revendedor', $this->boutique());
        $this->darRol($empleado, 'cliente_directo', $this->boutique());

        $roles = PersonalPlataforma::rolesDe([(int) $empleado->id])[$empleado->id];

        $this->assertSame(['empleado'], array_column($roles, 'rol'));
        $this->assertSame('Calzados Ramírez', $roles[0]['distribuidora']);
        $this->assertNotContains($empleado->email, $this->correos(['filtro_distribuidora' => (string) $this->boutique()]));
    }

    public function test_muestra_foto_roles_estado_y_alta(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez(), ['foto_url' => 'https://fotos.test/tg335.jpg', 'estado' => 'bloqueado']);
        $persona->forceFill(['created_at' => '2026-03-04 10:00:00'])->save();
        DistribuidoraStaff::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->ramirez(),
            'usuario_id' => $persona->id,
            'tipo' => 'empleado',
            'estado' => 'inactivo',
            'fecha_alta' => now(),
        ]);

        $this->get(route('admin.usuarios', ['q' => $persona->email]))
            ->assertOk()
            ->assertSee('https://fotos.test/tg335.jpg')
            ->assertSee($persona->nombre)
            ->assertSee('Empleado')
            ->assertSee('Calzados Ramírez')
            ->assertSee('Bloqueado')
            ->assertSee('De baja aquí')
            ->assertSee('04/03/2026');
    }

    // ------------------------------------------------------------------
    // Búsqueda, filtros y paginación
    // ------------------------------------------------------------------

    public function test_busca_por_nombre_correo_y_telefono(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez(), [
            'nombre' => 'Ximena Tovar',
            'email' => 'ximena@tg335.test',
            'telefono' => '9610000335',
        ]);

        foreach (['Ximena Tov', 'ximena@tg335', '9610000335'] as $termino) {
            $this->assertSame([$persona->email], $this->correos(['buscar' => $termino]), $termino);
        }

        // Los comodines de LIKE se buscan como texto.
        $this->assertSame([], $this->correos(['buscar' => '%']));
    }

    public function test_filtra_por_rol_estado_y_distribuidora(): void
    {
        $this->comoAdminGeneral();
        $bloqueado = $this->empleadoNuevo($this->boutique(), ['estado' => 'bloqueado']);

        $empleados = $this->correos(['filtro_rol' => 'empleado']);
        $this->assertContains(DemoDistribuidoraSeeder::EMPLEADO, $empleados);
        $this->assertNotContains(DemoDistribuidoraSeeder::ADMIN, $empleados);

        $this->assertSame([AdminGeneralSeeder::EMAIL], $this->correos(['filtro_rol' => 'admin_general']));
        $this->assertSame([$bloqueado->email], $this->correos(['filtro_estado' => 'bloqueado']));

        $deBoutique = $this->correos(['filtro_distribuidora' => (string) $this->boutique()]);
        $this->assertContains(DemoDistribuidoraSeeder::SEGUNDA_ADMIN, $deBoutique);
        $this->assertContains($bloqueado->email, $deBoutique);
        $this->assertNotContains(DemoDistribuidoraSeeder::ADMIN, $deBoutique);
    }

    public function test_rol_y_distribuidora_se_cumplen_en_la_misma_asignacion(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez());
        $this->darRol($persona, 'admin_distribuidora', $this->boutique());

        $this->assertContains($persona->email, $this->correos([
            'filtro_rol' => 'empleado',
            'filtro_distribuidora' => (string) $this->ramirez(),
        ]));
        $this->assertNotContains($persona->email, $this->correos([
            'filtro_rol' => 'empleado',
            'filtro_distribuidora' => (string) $this->boutique(),
        ]));
    }

    public function test_un_filtro_que_no_existe_se_ignora(): void
    {
        $this->comoAdminGeneral();
        $todos = $this->correos();

        $this->assertSame($todos, $this->correos(['filtro_rol' => 'revendedor', 'filtro_estado' => 'x', 'filtro_distribuidora' => 'abc']));
    }

    public function test_pagina_de_20_en_20(): void
    {
        $this->comoAdminGeneral();

        for ($i = 0; $i < 25; $i++) {
            $this->empleadoNuevo($this->ramirez());
        }

        $personal = Livewire::test('admin.usuarios-index')->instance()->personal;

        $this->assertCount(20, $personal);
        $this->assertGreaterThanOrEqual(25, $personal->total());
    }

    public function test_el_listado_no_hace_mas_consultas_con_mas_personal(): void
    {
        $this->comoAdminGeneral();
        $antes = $this->consultasDelListado();

        for ($i = 0; $i < 8; $i++) {
            $this->empleadoNuevo($this->boutique());
        }

        $this->assertSame($antes, $this->consultasDelListado());
    }

    private function consultasDelListado(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('admin.usuarios-index');
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    // ------------------------------------------------------------------
    // Ficha
    // ------------------------------------------------------------------

    public function test_la_ficha_muestra_datos_roles_ultimo_inicio_y_movimientos(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez(), ['nombre' => 'Ximena Tovar', 'telefono' => '9610000335']);
        $otra = $this->empleadoNuevo($this->ramirez());

        $this->movimiento($persona, RegistrarSesionAction::INICIO_PANEL, '2026-11-01 10:00:00');
        $this->movimiento($persona, RegistrarSesionAction::INICIO_APP, '2026-11-05 09:30:00');
        $this->movimiento($persona, RegistrarSesionAction::CIERRE_APP, '2026-11-06 08:00:00');
        $propio = $this->movimiento($persona, 'stock.ajuste', '2026-11-04 12:00:00');
        $ajeno = $this->movimiento($otra, 'stock.ajuste');

        $this->get(route('admin.usuarios.ficha', $persona->id))
            ->assertOk()
            ->assertSee('Ximena Tovar')
            ->assertSee($persona->email)
            ->assertSee('9610000335')
            ->assertSee('Calzados Ramírez')
            ->assertSee('05/11/2026 09:30')
            ->assertSee('Inicio de sesión (app)');

        $ficha = Livewire::test('admin.usuarios-ficha', ['usuario' => $persona->id])->instance();

        $this->assertSame(RegistrarSesionAction::INICIO_APP, $ficha->ultimoInicio->accion);

        $ids = $ficha->movimientos->pluck('id')->all();
        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    public function test_la_ficha_no_enseña_los_datos_guardados_en_la_auditoria(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez());
        $this->movimiento($persona, 'datos_bancarios.actualizados', null, ['clabe' => '012180001234567897']);

        $this->get(route('admin.usuarios.ficha', $persona->id))
            ->assertOk()
            ->assertDontSee('012180001234567897');
    }

    public function test_sin_inicios_lo_dice(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez());

        $this->get(route('admin.usuarios.ficha', $persona->id))->assertOk()->assertSee('Sin registro');
    }

    public function test_un_cliente_no_tiene_ficha(): void
    {
        $this->comoAdminGeneral();

        $this->get(route('admin.usuarios.ficha', $this->cuenta(self::CLIENTE)->id))->assertNotFound();
        $this->get(route('admin.usuarios.ficha', $this->cuenta(self::MAYORISTA)->id))->assertNotFound();
        $this->get(route('admin.usuarios.ficha', 999999))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Acceso y datos sensibles
    // ------------------------------------------------------------------

    public function test_ningun_otro_rol_entra(): void
    {
        $ficha = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO)->id;

        foreach ([DemoDistribuidoraSeeder::ADMIN, DemoDistribuidoraSeeder::EMPLEADO, self::CLIENTE, self::MAYORISTA] as $correo) {
            $usuario = $this->cuenta($correo);
            $this->actingAs($usuario);
            Tenant::olvidarCache();
            PropietarioActual::olvidarCache();

            $this->get(route('admin.usuarios'))->assertForbidden();
            $this->get(route('admin.usuarios.ficha', $ficha))->assertForbidden();

            // Y tampoco llamando a los componentes sin pasar por la ruta.
            Livewire::test('admin.usuarios-index')->assertForbidden();
            Livewire::test('admin.usuarios-ficha', ['usuario' => $ficha])->assertForbidden();
        }
    }

    public function test_la_respuesta_no_incluye_contrasena_ni_hash(): void
    {
        $this->comoAdminGeneral();
        $persona = $this->empleadoNuevo($this->ramirez());
        $persona->forceFill(['remember_token' => 'token-tg335'])->save();
        $hash = $persona->fresh()->getAuthPassword();

        foreach ([route('admin.usuarios', ['q' => $persona->email]), route('admin.usuarios.ficha', $persona->id)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($hash, $html);
            $this->assertStringNotContainsString('$2y$', $html);
            $this->assertStringNotContainsString('token-tg335', $html);
        }

        $listado = Livewire::test('admin.usuarios-index')->set('buscar', $persona->email)->instance()->personal->first();
        $ficha = Livewire::test('admin.usuarios-ficha', ['usuario' => $persona->id])->instance()->persona;

        foreach ([$listado, $ficha] as $modelo) {
            $this->assertArrayNotHasKey('password', $modelo->getAttributes());
            $this->assertArrayNotHasKey('remember_token', $modelo->getAttributes());
        }
    }

    public function test_no_deja_cambiado_el_equipo_de_permisos(): void
    {
        $this->comoAdminGeneral();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->ramirez());

        Livewire::test('admin.usuarios-index')->set('filtro_rol', 'empleado');
        Livewire::test('admin.usuarios-ficha', ['usuario' => $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO)->id]);

        $this->assertSame($this->ramirez(), (int) $registrar->getPermissionsTeamId());
    }

    public function test_el_menu_del_admin_general_tiene_el_enlace(): void
    {
        $this->comoAdminGeneral();

        $this->get(route('admin.planes'))
            ->assertOk()
            ->assertSee('href="'.route('admin.usuarios').'"', false)
            ->assertSee('Usuarios');
    }
}
