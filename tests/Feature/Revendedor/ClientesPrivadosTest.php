<?php

namespace Tests\Feature\Revendedor;

use App\Models\ClientePrivadoRevendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clientes privados del revendedor (E9-06), corregidos en TG-162.
 *
 * Antes, un nombre largo, un teléfono largo, un nombre repetido o un monto
 * vacío chocaban con la tabla y la app recibía un error 500. Ahora son
 * errores de validación con mensaje.
 *
 * Usa las cuentas de demo: María (revendedora con cuenta). A Roberto, el otro
 * revendedor de demo, se le activa cuenta aquí para probar el aislamiento.
 */
class ClientesPrivadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const RUTA = '/api/revendedor/clientes-privados';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoMaria(): void
    {
        $this->comoUsuario(Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail());
    }

    private function comoRoberto(): void
    {
        $email = 'roberto.cuenta@revendedor.test';

        if (! Usuario::where('email', $email)->exists()) {
            $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
                ->whereHas('revendedor', fn ($q) => $q->where('email', 'roberto.garcia@revendedor.test'))
                ->with('revendedor')
                ->firstOrFail();

            app(ActivarCuentaAccesoAction::class)->paraRevendedor($afiliacion, $email, 'password');
        }

        $this->comoUsuario(Usuario::where('email', $email)->firstOrFail());
    }

    private function comoUsuario(Usuario $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function crear(array $datos)
    {
        return $this->postJson(self::RUTA, $datos + ['nombre' => 'Cliente de prueba']);
    }

    // ------------------------------------------------------------------
    // Lo que tiene que funcionar
    // ------------------------------------------------------------------

    public function test_una_revendedora_registra_un_cliente_privado(): void
    {
        $this->comoMaria();

        $this->crear(['nombre' => 'Doña Chela', 'telefono' => '9631112222', 'monto' => 850, 'saldo' => 300])
            ->assertStatus(201)
            ->assertJsonPath('data.nombre', 'Doña Chela');

        $this->assertDatabaseHas('clientes_privados_revendedor', ['nombre' => 'Doña Chela', 'saldo' => 300]);
    }

    /** Antes: error 500, porque en la tabla monto y saldo no aceptan vacío. */
    public function test_monto_y_saldo_vacios_se_guardan_como_cero(): void
    {
        $this->comoMaria();

        $this->crear(['nombre' => 'Sin monto', 'monto' => null, 'saldo' => null])->assertStatus(201);

        $this->assertDatabaseHas('clientes_privados_revendedor', ['nombre' => 'Sin monto', 'monto' => 0, 'saldo' => 0]);
    }

    // ------------------------------------------------------------------
    // Antes daban error 500; ahora son errores con mensaje
    // ------------------------------------------------------------------

    public function test_un_nombre_mas_largo_que_la_tabla_da_error_de_validacion(): void
    {
        $this->comoMaria();

        $this->crear(['nombre' => str_repeat('a', 151)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);
    }

    public function test_telefono_y_referencia_mas_largos_que_la_tabla_dan_error_de_validacion(): void
    {
        $this->comoMaria();

        $this->crear(['telefono' => str_repeat('9', 31), 'referencia' => str_repeat('r', 151)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telefono', 'referencia']);
    }

    public function test_montos_negativos_o_demasiado_grandes_no_se_aceptan(): void
    {
        $this->comoMaria();

        $this->crear(['monto' => -10, 'saldo' => 100000000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monto', 'saldo']);
    }

    public function test_un_nombre_repetido_da_un_mensaje_claro(): void
    {
        $this->comoMaria();
        $this->crear(['nombre' => 'Doña Chela'])->assertStatus(201);

        $this->crear(['nombre' => 'Doña Chela'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'Ya tienes un cliente con ese nombre.');
    }

    /** Guardar un cliente sin cambiarle el nombre no debe contar como repetido. */
    public function test_editar_un_cliente_sin_cambiar_su_nombre_si_se_puede(): void
    {
        $this->comoMaria();
        $id = $this->crear(['nombre' => 'Doña Chela'])->json('data.id');

        $this->putJson(self::RUTA."/{$id}", ['nombre' => 'Doña Chela', 'saldo' => 50])->assertOk();

        $this->assertDatabaseHas('clientes_privados_revendedor', ['id' => $id, 'saldo' => 50]);
    }

    public function test_dos_revendedores_si_pueden_tener_un_cliente_con_el_mismo_nombre(): void
    {
        $this->comoMaria();
        $this->crear(['nombre' => 'Doña Chela'])->assertStatus(201);

        $this->comoRoberto();
        $this->crear(['nombre' => 'Doña Chela'])->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Un revendedor no ve ni toca los clientes de otro
    // ------------------------------------------------------------------

    public function test_un_revendedor_no_ve_los_clientes_privados_de_otro(): void
    {
        $this->comoMaria();
        $this->crear(['nombre' => 'Cliente de María'])->assertStatus(201);

        $this->comoRoberto();
        $this->crear(['nombre' => 'Cliente de Roberto'])->assertStatus(201);

        $nombres = collect($this->getJson(self::RUTA)->assertOk()->json('data'))->pluck('nombre');

        $this->assertContains('Cliente de Roberto', $nombres);
        $this->assertNotContains('Cliente de María', $nombres);
    }

    public function test_un_revendedor_no_puede_editar_ni_borrar_los_clientes_de_otro(): void
    {
        $this->comoMaria();
        $id = $this->crear(['nombre' => 'Cliente de María'])->json('data.id');

        $this->comoRoberto();
        $this->putJson(self::RUTA."/{$id}", ['nombre' => 'Hackeado'])->assertStatus(404);
        $this->deleteJson(self::RUTA."/{$id}")->assertStatus(404);

        $this->assertDatabaseHas('clientes_privados_revendedor', ['id' => $id, 'nombre' => 'Cliente de María']);
    }

    public function test_un_cliente_directo_no_tiene_clientes_privados(): void
    {
        $this->comoUsuario(Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail());

        $this->getJson(self::RUTA)->assertStatus(403);
        $this->crear(['nombre' => 'No debería'])->assertStatus(403);

        $this->assertSame(0, ClientePrivadoRevendedor::count());
    }
}
