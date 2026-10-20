<?php

namespace Tests\Feature\Auth;

use App\Mail\RestablecerPasswordMail;
use App\Models\Usuario;
use App\Notifications\RestablecerPasswordNotification;
use App\Providers\AppServiceProvider;
use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recuperar contraseña: tope por IP, correo en cola y mensajes en español.
 */
class RecuperarPasswordSeguridadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CORREO_EXISTENTE = 'empleado@calzadosramirez.test';

    public function test_la_api_de_pedir_enlace_corta_con_429_al_sexto_intento_por_ip(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "nadie{$i}@no-existe.test"])
                ->assertOk()
                ->assertExactJson(['message' => EnviarEnlaceRecuperacionAction::MENSAJE]);
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'otro@no-existe.test'])
            ->assertStatus(429)
            ->assertExactJson(['message' => AppServiceProvider::MENSAJE_DEMASIADOS_INTENTOS]);
    }

    public function test_la_api_de_restablecer_corta_con_429_al_sexto_intento_por_ip(): void
    {
        $datos = ['email' => self::CORREO_EXISTENTE, 'token' => 'inventado', 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', $datos)->assertStatus(422);
        }

        $this->postJson('/api/auth/reset-password', $datos)->assertStatus(429);
    }

    public function test_el_tope_es_por_ip_otra_ip_sigue_pudiendo(): void
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/api/auth/forgot-password', ['email' => 'nadie@no-existe.test']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/auth/forgot-password', ['email' => 'nadie@no-existe.test'])
            ->assertOk();
    }

    public function test_la_pantalla_web_de_pedir_enlace_frena_al_sexto_intento(): void
    {
        Notification::fake();

        $componente = Livewire::test('auth.forgot-password');
        for ($i = 0; $i < 5; $i++) {
            $componente->set('email', "nadie{$i}@no-existe.test")->call('sendResetLink')
                ->assertHasNoErrors()
                ->assertSet('status', EnviarEnlaceRecuperacionAction::MENSAJE);
        }

        $componente->set('status', '')->set('email', 'otro@no-existe.test')->call('sendResetLink')
            ->assertHasErrors('email')
            ->assertSee('Demasiados intentos')
            ->assertSet('status', '');
    }

    public function test_la_pantalla_web_de_restablecer_frena_al_sexto_intento(): void
    {
        $componente = Livewire::test('auth.reset-password')
            ->set('token', 'inventado')
            ->set('email', self::CORREO_EXISTENTE)
            ->set('password', 'NuevaClave123')
            ->set('password_confirmation', 'NuevaClave123');

        for ($i = 0; $i < 5; $i++) {
            $componente->call('resetPassword')->assertSee('El enlace no es válido');
        }

        $componente->call('resetPassword')->assertSee('Demasiados intentos');
    }

    public function test_el_correo_del_enlace_va_en_cola_y_usa_la_plantilla_en_espanol(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE])->assertOk();

        $usuario = Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail();

        Notification::assertSentTo($usuario, RestablecerPasswordNotification::class, function ($n) use ($usuario) {
            $this->assertInstanceOf(ShouldQueue::class, $n);
            $this->assertInstanceOf(RestablecerPasswordMail::class, $n->toMail($usuario));
            return true;
        });
    }

    public function test_el_correo_queda_guardado_en_la_cola_database(): void
    {
        config(['queue.default' => 'database']);

        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE])->assertOk();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertStringContainsString('RestablecerPasswordNotification', (string) \DB::table('jobs')->value('payload'));
    }

    public function test_correo_mal_escrito_da_mensaje_en_espanol_en_api_y_web(): void
    {
        $this->postJson('/api/auth/reset-password', ['email' => 'no-es-correo', 'token' => 'x', 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'El correo no es válido.');

        Livewire::test('auth.reset-password')
            ->set('token', 'x')->set('email', 'no-es-correo')
            ->set('password', 'NuevaClave123')->set('password_confirmation', 'NuevaClave123')
            ->call('resetPassword')
            ->assertSee('El correo no es válido.');
    }
}
