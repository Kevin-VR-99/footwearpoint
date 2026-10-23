<?php

namespace Tests\Feature\Seguridad;

use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Rules\PasswordSegura;
use App\Services\Distribuidora\ActualizarAdminDistribuidoraAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * INC-002 (QA Aure): contraseñas débiles como 12345678 se aceptaban.
 * Política única en AppServiceProvider (Password::defaults) + PasswordSegura.
 */
class PoliticaPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function errores(string $password, array $extra = []): array
    {
        $v = Validator::make(
            array_merge(['password' => $password, 'password_confirmation' => $password], $extra),
            ['password' => ['required', 'string', Password::defaults(), 'max:255', 'confirmed']],
        );

        return $v->errors()->get('password');
    }

    public static function debiles(): array
    {
        return [
            'solo numeros' => ['12345678'],
            'repetido' => ['11111111'],
            'solo letras secuencia' => ['abcdefgh'],
            'comun password' => ['password'],
            'comun password1' => ['password1'],
            'teclado' => ['qwerty123'],
            'secuencia mixta' => ['abcd1234'],
            'secuencia descendente' => ['87654321'],
            'nombre app' => ['FootwearPoint1'],
            'corta' => ['ab1'],
        ];
    }

    /** @dataProvider debiles */
    public function test_rechaza_contrasenas_debiles(string $password): void
    {
        $this->assertNotEmpty($this->errores($password), "Se aceptó «{$password}»");
    }

    public static function fuertes(): array
    {
        return [
            ['Tenis.Rojo42'],
            ['zapato7Azul'],
            ['mi perro come 3 croquetas'],
            ['Kx9!mPq2'],
        ];
    }

    /** @dataProvider fuertes */
    public function test_acepta_contrasenas_fuertes(string $password): void
    {
        $this->assertSame([], $this->errores($password));
    }

    public function test_mensajes_en_espanol(): void
    {
        $this->assertContains('La contraseña debe incluir al menos una letra.', $this->errores('83920174'));
        $this->assertContains('La contraseña debe incluir al menos un número.', $this->errores('zapatoazul'));
        $this->assertContains(PasswordSegura::MENSAJE_SECUENCIA, $this->errores('zyxw9876'));
        $this->assertContains(PasswordSegura::MENSAJE_COMUN, $this->errores('password1'));
    }

    public function test_rechaza_correo_o_nombre_de_la_persona(): void
    {
        $this->assertContains(PasswordSegura::MENSAJE_PERSONAL, $this->errores('gabriela2026', ['email' => 'gabriela@tienda.test']));
        $this->assertContains(PasswordSegura::MENSAJE_PERSONAL, $this->errores('Ramirez88x', ['nombre' => 'Ana Ramirez']));
    }

    public function test_confirmacion_distinta_se_rechaza(): void
    {
        $v = Validator::make(
            ['password' => 'Tenis.Rojo42', 'password_confirmation' => 'Tenis.Rojo43'],
            ['password' => ['required', 'string', Password::defaults(), 'max:255', 'confirmed']],
        );
        $this->assertTrue($v->fails());
    }

    public function test_api_cambio_de_password_rechaza_12345678_y_acepta_fuerte(): void
    {
        $token = $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])
            ->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        foreach (['12345678', 'password1', 'qwerty123'] as $debil) {
            $this->withToken($token)->postJson('/api/perfil/password', [
                'password_actual' => 'password',
                'password' => $debil,
                'password_confirmation' => $debil,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertTrue(Hash::check('password', Usuario::where('email', self::MARIA)->first()->password));

        $this->withToken($token)->postJson('/api/perfil/password', [
            'password_actual' => 'password',
            'password' => 'Tenis.Rojo42',
            'password_confirmation' => 'Tenis.Rojo42',
        ])->assertOk();
    }

    public function test_api_restablecer_password_rechaza_debil(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token' => 'x', 'email' => self::MARIA,
            'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_actualizar_admin_distribuidora_rechaza_debil(): void
    {
        $d = Distribuidora::query()->firstOrFail();
        $admin = app(ActualizarAdminDistribuidoraAction::class)->administradorDe($d);
        if (! $admin) {
            $this->markTestSkipped('La distribuidora demo no tiene admin.');
        }

        try {
            app(ActualizarAdminDistribuidoraAction::class)->ejecutar($d, [
                'nombre' => $admin->nombre, 'email' => $admin->email,
                'password' => '12345678', 'password_confirmation' => '12345678',
            ]);
            $this->fail('Aceptó 12345678');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
    }

    public function test_alta_de_distribuidora_en_admin_rechaza_debil(): void
    {
        $adminGeneral = Usuario::firstOrCreate(
            ['email' => \Database\Seeders\AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($adminGeneral);

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            ->set('admin_password', '12345678')
            ->call('crearDistribuidora')
            ->assertHasErrors('admin_password');
    }

    public function test_las_cuentas_demo_siguen_entrando_con_password(): void
    {
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])->assertOk();
    }
}
