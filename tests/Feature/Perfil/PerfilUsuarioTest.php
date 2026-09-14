<?php

namespace Tests\Feature\Perfil;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * E1-05 (TG-110) — GET/PATCH /api/perfil y POST /api/perfil/password.
 *
 * Usa las cuentas de la app que crea DemoContactosSeeder (E3-07):
 * maria.lopez@revendedor.test (revendedor) y jose.hernandez@cliente.test
 * (cliente directo), las dos con contraseña "password".
 *
 * Tokens reales sacados del login, como en SesionActualTest: cambiar la
 * contraseña tiene que conservar justo el token con el que se hizo el cambio.
 */
class PerfilUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
    private const JOSE  = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function iniciarSesion(string $email, string $password = 'password'): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => $password,
        ])->assertOk()->json('data.token');

        $this->olvidarSesionEnMemoria();

        return $token;
    }

    /**
     * Dentro de una prueba Laravel recuerda al usuario entre peticiones. En la
     * vida real cada petición llega sola con su token.
     */
    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function usuario(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Ver
    // ------------------------------------------------------------------

    public function test_sin_token_no_se_puede_entrar_a_ninguna_ruta_del_perfil(): void
    {
        $this->getJson('/api/perfil')->assertUnauthorized();
        $this->patchJson('/api/perfil', ['nombre' => 'X'])->assertUnauthorized();
        $this->postJson('/api/perfil/password', [])->assertUnauthorized();
    }

    public function test_cada_quien_ve_sus_propios_datos(): void
    {
        $token = $this->iniciarSesion(self::MARIA);
        $maria = $this->usuario(self::MARIA);

        $this->withToken($token)->getJson('/api/perfil')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id'       => $maria->id,
                    'nombre'   => $maria->nombre,
                    'email'    => self::MARIA,
                    'telefono' => $maria->telefono,
                    'estado'   => 'activo',
                ],
            ]);
    }

    // ------------------------------------------------------------------
    // Editar nombre y teléfono
    // ------------------------------------------------------------------

    public function test_un_revendedor_edita_su_nombre_y_telefono_y_se_actualiza_tambien_su_registro(): void
    {
        $token = $this->iniciarSesion(self::MARIA);
        $maria = $this->usuario(self::MARIA);

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => 'María López Ruiz',
            'telefono' => '9630001111',
        ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'María López Ruiz')
            ->assertJsonPath('data.telefono', '9630001111');

        $this->assertDatabaseHas('usuarios', [
            'id'       => $maria->id,
            'nombre'   => 'María López Ruiz',
            'telefono' => '9630001111',
        ]);

        // Lo que ve el empleado en el panel web.
        $this->assertDatabaseHas('revendedores', [
            'usuario_id' => $maria->id,
            'nombre'     => 'María López Ruiz',
            'telefono'   => '9630001111',
        ]);
    }

    public function test_un_cliente_directo_edita_su_perfil_y_se_actualiza_tambien_su_registro(): void
    {
        $token = $this->iniciarSesion(self::JOSE);
        $jose = $this->usuario(self::JOSE);

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => 'José Hernández Díaz',
            'telefono' => '9632223333',
        ])->assertOk();

        $this->assertDatabaseHas('usuarios', ['id' => $jose->id, 'nombre' => 'José Hernández Díaz']);
        $this->assertDatabaseHas('clientes_directos', [
            'usuario_id' => $jose->id,
            'nombre'     => 'José Hernández Díaz',
            'telefono'   => '9632223333',
        ]);
    }

    /**
     * Un cliente inactivo no resuelve distribuidora (tenant null). Su registro
     * igual se tiene que actualizar: por eso la acción no pasa por el
     * TenantScope.
     */
    public function test_sin_distribuidora_activa_tambien_se_actualiza_el_registro_ligado(): void
    {
        $token = $this->iniciarSesion(self::JOSE);
        $jose = $this->usuario(self::JOSE);

        ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $jose->id)
            ->update(['estado' => 'inactivo']);
        $this->olvidarSesionEnMemoria();

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => 'José Sin Distribuidora',
            'telefono' => null,
        ])->assertOk();

        $this->assertDatabaseHas('clientes_directos', [
            'usuario_id' => $jose->id,
            'nombre'     => 'José Sin Distribuidora',
            'telefono'   => null,
        ]);
    }

    public function test_un_telefono_vacio_se_guarda_como_sin_telefono_en_los_dos_lugares(): void
    {
        $token = $this->iniciarSesion(self::MARIA);
        $maria = $this->usuario(self::MARIA);

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => 'María López',
            'telefono' => '   ',
        ])->assertOk()->assertJsonPath('data.telefono', null);

        $this->assertDatabaseHas('usuarios', ['id' => $maria->id, 'telefono' => null]);
        $this->assertDatabaseHas('revendedores', ['usuario_id' => $maria->id, 'telefono' => null]);
    }

    public function test_el_nombre_es_obligatorio_y_no_se_cambia_nada_si_falta(): void
    {
        $token = $this->iniciarSesion(self::MARIA);
        $nombreAntes = $this->usuario(self::MARIA)->nombre;

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => '   ',
            'telefono' => '9630001111',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'El nombre es obligatorio.');

        $this->assertSame($nombreAntes, $this->usuario(self::MARIA)->nombre);
    }

    public function test_el_correo_no_se_puede_cambiar_desde_el_perfil(): void
    {
        $token = $this->iniciarSesion(self::MARIA);

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre' => 'María López',
            'email'  => 'otro@correo.test',
        ])->assertOk()->assertJsonPath('data.email', self::MARIA);

        $this->assertDatabaseMissing('usuarios', ['email' => 'otro@correo.test']);
    }

    public function test_editar_un_perfil_no_toca_los_datos_de_otra_persona(): void
    {
        $token = $this->iniciarSesion(self::MARIA);
        $roberto = Revendedor::where('email', 'roberto.garcia@revendedor.test')->firstOrFail();

        $this->withToken($token)->patchJson('/api/perfil', [
            'nombre'   => 'María Cambiada',
            'telefono' => '9639999999',
        ])->assertOk();

        $this->assertSame('Roberto García', $roberto->fresh()->nombre);
        $this->assertSame('9635552222', $roberto->fresh()->telefono);
    }

    // ------------------------------------------------------------------
    // Cambiar contraseña
    // ------------------------------------------------------------------

    public function test_con_la_contrasena_actual_incorrecta_no_se_cambia(): void
    {
        $token = $this->iniciarSesion(self::MARIA);

        $this->withToken($token)->postJson('/api/perfil/password', [
            'password_actual'       => 'no-es-esta',
            'password'              => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password_actual.0', 'La contraseña actual no es correcta.');

        $this->assertTrue(Hash::check('password', $this->usuario(self::MARIA)->password));
    }

    public function test_la_nueva_contrasena_cumple_las_mismas_reglas_minimas(): void
    {
        $token = $this->iniciarSesion(self::MARIA);

        $this->withToken($token)->postJson('/api/perfil/password', [
            'password_actual'       => 'password',
            'password'              => 'corta',
            'password_confirmation' => 'corta',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 8 caracteres.');

        $this->withToken($token)->postJson('/api/perfil/password', [
            'password_actual'       => 'password',
            'password'              => 'nuevaClave123',
            'password_confirmation' => 'otraClave123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Las contraseñas no coinciden.');
    }

    public function test_al_cambiar_la_contrasena_se_cierran_los_otros_telefonos_y_sigue_el_actual(): void
    {
        // Dos teléfonos con sesión abierta.
        $tokenEsteTelefono = $this->iniciarSesion(self::MARIA);
        $tokenOtroTelefono = $this->iniciarSesion(self::MARIA);

        $this->withToken($tokenEsteTelefono)->postJson('/api/perfil/password', [
            'password_actual'       => 'password',
            'password'              => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ])->assertOk();
        $this->olvidarSesionEnMemoria();

        $this->assertTrue(Hash::check('nuevaClave123', $this->usuario(self::MARIA)->password));
        $this->assertSame(1, $this->usuario(self::MARIA)->tokens()->count());

        // El teléfono donde se cambió sigue dentro.
        $this->withToken($tokenEsteTelefono)->getJson('/api/perfil')->assertOk();
        $this->olvidarSesionEnMemoria();

        // El otro ya no.
        $this->withToken($tokenOtroTelefono)->getJson('/api/perfil')->assertUnauthorized();
        $this->olvidarSesionEnMemoria();

        // Y ya se entra con la nueva, no con la vieja.
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'nuevaClave123'])
            ->assertOk();
    }
}
