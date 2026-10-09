<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\DebeCambiarPassword;
use App\Services\Auth\CuentaEnVariasDistribuidoras;
use App\Services\Auth\GenerarPasswordTemporalAction;
use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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
        Livewire::test('distribuidora.usuarios')
            ->call('generarPasswordTemporalRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', false)
            // La contrasena NO se queda en una propiedad del componente: esas
            // viajan al navegador en cada accion siguiente (lo reporto Gaby).
            ->assertSet('avisoEnlace', null);

        $aviso = session('aviso_password_temporal');

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
        // assertEquals y no assertSame: MySQL guarda el JSON reordenando las
        // llaves por longitud, asi que el orden no es de fiar.
        $this->assertEquals(['email' => 'maria.lopez@revendedor.test'], $registro->datos_nuevos);
    }

    public function test_tambien_funciona_con_un_cliente_directo(): void
    {
        $this->entrarComoAdmin();

        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $cliente = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();

        Livewire::test('distribuidora.clientes')
            ->call('generarPasswordTemporalCliente', $cliente->id)
            ->assertSet('avisoEnlaceEsError', false);

        $this->assertStringContainsString('Contraseña temporal', (string) session('aviso_password_temporal'));

        $this->assertTrue((bool) $cuenta->fresh()->debe_cambiar_password);
    }

    // ------------------------------------------------------------------
    // Cuentas que comparten dos distribuidoras (hallazgo de seguridad)
    // ------------------------------------------------------------------

    /** Da de alta a la misma persona en la otra distribuidora del seeder. */
    private function afiliarMariaATambienOtraDistribuidora(string $estado = 'activo'): void
    {
        $otra = DB::table('distribuidoras')
            ->where('nombre_comercial', 'Boutique del Calzado')
            ->value('id');

        // Se busca por la cuenta y no por el correo: al activarle el acceso,
        // el correo vive en usuarios y el del contacto queda vacio.
        $revendedorId = DB::table('revendedores')
            ->where('usuario_id', $this->cuentaDeMaria()->id)
            ->value('id');

        DB::table('revendedor_distribuidora')->insert([
            'distribuidora_id' => $otra,
            'revendedor_id'    => $revendedorId,
            'codigo_interno'   => 'REV-OTRA',
            'estado'           => $estado,
            'fecha_alta'       => now()->toDateString(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_no_se_permite_si_la_cuenta_la_usa_otra_distribuidora(): void
    {
        // El riesgo: la cuenta es UNA sola. Si la distribuidora A le pone una
        // contrasena temporal, con esa contrasena puede entrar como esa
        // persona y ver lo suyo con la distribuidora B.
        $this->afiliarMariaATambienOtraDistribuidora();

        $this->entrarComoAdmin();
        $antes = $this->cuentaDeMaria()->password;

        Livewire::test('distribuidora.usuarios')
            ->call('generarPasswordTemporalRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', CuentaEnVariasDistribuidoras::MENSAJE);

        $this->assertNull(session('aviso_password_temporal'), 'No debio mostrarse ninguna contrasena.');

        // Y sobre todo: la cuenta quedo intacta.
        $cuenta = $this->cuentaDeMaria()->fresh();
        $this->assertSame($antes, $cuenta->password, 'Le cambiaron la contrasena de todos modos.');
        $this->assertFalse((bool) $cuenta->debe_cambiar_password);
    }

    public function test_no_se_puede_saltar_la_regla_llamando_a_la_accion_directo(): void
    {
        // La revision vive en la accion, no solo en la pantalla.
        $this->afiliarMariaATambienOtraDistribuidora();
        $this->entrarComoAdmin();

        $this->expectException(ValidationException::class);

        app(GenerarPasswordTemporalAction::class)
            ->ejecutar($this->cuentaDeMaria(), 'revendedor', 1);
    }

    public function test_no_se_cierran_las_sesiones_de_una_cuenta_compartida(): void
    {
        $this->afiliarMariaATambienOtraDistribuidora();

        $maria = $this->cuentaDeMaria();
        $maria->createToken('celular');

        $this->entrarComoAdmin();

        Livewire::test('distribuidora.usuarios')
            ->call('generarPasswordTemporalRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', true);

        // Si se le hubieran borrado, la habrian sacado de la app de la otra
        // distribuidora sin tener por que.
        $this->assertSame(1, $this->cuentaDeMaria()->fresh()->tokens()->count());
    }

    public function test_una_afiliacion_inactiva_en_otra_distribuidora_no_estorba(): void
    {
        // Ya no opera con la otra distribuidora: ahi no hay nada que proteger.
        $this->afiliarMariaATambienOtraDistribuidora('inactivo');

        $this->entrarComoAdmin();
        $password = $this->generarDesdeElPanel();

        $this->assertTrue(Hash::check($password, $this->cuentaDeMaria()->fresh()->password));
    }

    public function test_tampoco_para_un_cliente_dado_de_alta_en_dos_distribuidoras(): void
    {
        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $cliente = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();

        $otra = DB::table('distribuidoras')
            ->where('nombre_comercial', 'Boutique del Calzado')
            ->value('id');

        DB::table('clientes_directos')->insert([
            'distribuidora_id' => $otra,
            'usuario_id'       => $cuenta->id,
            'nombre'           => 'Jose Hernandez',
            'telefono'         => '9635554444',
            'email'            => 'jose.hernandez@cliente.test',
            'estado'           => 'activo',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->entrarComoAdmin();

        Livewire::test('distribuidora.clientes')
            ->call('generarPasswordTemporalCliente', $cliente->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', CuentaEnVariasDistribuidoras::MENSAJE);

        $this->assertFalse((bool) $cuenta->fresh()->debe_cambiar_password);
    }

    public function test_el_enlace_por_correo_si_funciona_con_una_cuenta_compartida(): void
    {
        // Es la salida que se le ofrece al personal: el correo solo le llega
        // al dueno de la cuenta, asi que no sirve para apropiarsela.
        Notification::fake();

        $this->afiliarMariaATambienOtraDistribuidora();
        $this->entrarComoAdmin();

        Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', false);

        Notification::assertSentTo($this->cuentaDeMaria(), ResetPassword::class);
    }
}
