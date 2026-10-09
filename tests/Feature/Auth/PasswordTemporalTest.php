<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\DebeCambiarPassword;
use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-193 (A4) — Contrasena temporal que hay que cambiar al entrar.
 *
 * Es el respaldo del enlace por correo (TG-192): sirve cuando la persona esta
 * en el mostrador o cuando el correo no le llega. Mientras no la cambie, la
 * API no le deja hacer nada mas.
 */
class PasswordTemporalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function entrarComoAdmin(): Usuario
    {
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function cuentaDeMaria(): Usuario
    {
        return Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $this->cuentaDeMaria()->id))
            ->firstOrFail();
    }

    /** Genera la temporal desde el panel y regresa la que se le mostro al admin. */
    private function generarDesdeElPanel(): string
    {
        $componente = Livewire::test('distribuidora.usuarios')
            ->call('generarPasswordTemporalRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', false);

        $aviso = $componente->get('avisoEnlace');

        // El aviso trae "...: LACLAVE. Anótala ahora..."
        preg_match('/: ([A-Za-z0-9]{10})\./', $aviso, $partes);

        $this->assertNotEmpty($partes, 'El aviso no trae la contraseña: '.$aviso);

        return $partes[1];
    }

    public function test_el_panel_genera_una_temporal_y_la_muestra_una_vez(): void
    {
        $this->entrarComoAdmin();

        $password = $this->generarDesdeElPanel();
        $cuenta = $this->cuentaDeMaria()->fresh();

        $this->assertTrue(Hash::check($password, $cuenta->password), 'La contraseña generada no quedó puesta.');
        $this->assertTrue((bool) $cuenta->debe_cambiar_password);
    }

    public function test_con_la_temporal_si_puede_iniciar_sesion_en_la_app(): void
    {
        $this->entrarComoAdmin();
        $password = $this->generarDesdeElPanel();

        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'maria.lopez@revendedor.test',
            'password' => $password,
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('data.debe_cambiar_password', true)
            ->assertJsonPath('data.rol', 'revendedor');
    }

    public function test_mientras_no_la_cambie_la_api_no_le_deja_hacer_nada_mas(): void
    {
        $this->entrarComoAdmin();
        $this->generarDesdeElPanel();

        $maria = $this->cuentaDeMaria()->fresh();

        $this->actingAs($maria, 'sanctum')
            ->getJson('/api/catalogo')
            ->assertStatus(403)
            ->assertJsonPath('message', DebeCambiarPassword::MENSAJE);

        $this->actingAs($maria, 'sanctum')
            ->getJson('/api/pedidos')
            ->assertStatus(403);
    }

    public function test_pero_si_puede_ver_su_sesion_y_cambiar_la_contrasena(): void
    {
        $this->entrarComoAdmin();
        $password = $this->generarDesdeElPanel();
        $maria = $this->cuentaDeMaria()->fresh();

        $this->actingAs($maria, 'sanctum')->getJson('/api/auth/me')->assertOk();

        $this->actingAs($maria, 'sanctum')->postJson('/api/perfil/password', [
            'password_actual'       => $password,
            'password'              => 'miNuevaClave123',
            'password_confirmation' => 'miNuevaClave123',
        ])->assertOk();

        $this->assertFalse((bool) $this->cuentaDeMaria()->fresh()->debe_cambiar_password);
    }

    public function test_al_cambiarla_ya_puede_usar_la_app(): void
    {
        $this->entrarComoAdmin();
        $password = $this->generarDesdeElPanel();
        $maria = $this->cuentaDeMaria()->fresh();

        $this->actingAs($maria, 'sanctum')->postJson('/api/perfil/password', [
            'password_actual'       => $password,
            'password'              => 'miNuevaClave123',
            'password_confirmation' => 'miNuevaClave123',
        ])->assertOk();

        $this->actingAs($this->cuentaDeMaria()->fresh(), 'sanctum')
            ->getJson('/api/catalogo')
            ->assertOk();
    }

    public function test_se_cierran_las_sesiones_que_tenia_abiertas(): void
    {
        $maria = $this->cuentaDeMaria();
        $maria->createToken('celular')->plainTextToken;

        $this->assertSame(1, $maria->tokens()->count());

        $this->entrarComoAdmin();
        $this->generarDesdeElPanel();

        $this->assertSame(0, $this->cuentaDeMaria()->fresh()->tokens()->count());
    }

    public function test_queda_en_la_bitacora_de_auditoria(): void
    {
        $admin = $this->entrarComoAdmin();
        $this->generarDesdeElPanel();

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'password.temporal_generada')
            ->latest('id')
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame($admin->id, $registro->usuario_id);
        $this->assertSame('revendedor', $registro->entidad_tipo);

        // La contraseña NUNCA se guarda en la bitácora.
        $this->assertSame(['email' => 'maria.lopez@revendedor.test'], $registro->datos_nuevos);
    }

    public function test_tambien_funciona_con_un_cliente_directo(): void
    {
        $this->entrarComoAdmin();

        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $cliente = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();

        Livewire::test('distribuidora.clientes')
            ->call('generarPasswordTemporalCliente', $cliente->id)
            ->assertSet('avisoEnlaceEsError', false);

        $this->assertTrue((bool) $cuenta->fresh()->debe_cambiar_password);
    }
}
