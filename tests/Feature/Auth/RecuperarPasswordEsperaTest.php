<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Notifications\RestablecerPasswordNotification;
use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Espera de 1 minuto entre enlaces (con contador en pantalla) y enlace de
 * 15 minutos.
 */
class RecuperarPasswordEsperaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CORREO_EXISTENTE = 'empleado@calzadosramirez.test';
    private const CORREO_INEXISTENTE = 'nadie@no-existe.test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_el_broker_espera_60_segundos_y_el_enlace_dura_15_minutos(): void
    {
        $this->assertSame(60, config('auth.passwords.users.throttle'));
        $this->assertSame(15, config('auth.passwords.users.expire'));
    }

    public function test_tras_pedir_el_enlace_se_ve_el_contador_y_no_se_manda_otro(): void
    {
        $componente = Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_EXISTENTE)
            ->call('sendResetLink')
            ->assertSet('espera', 60)
            ->assertSee('Reenviar en')
            ->assertSee('1:00');

        $this->travel(20)->seconds();

        $componente->call('sendResetLink')
            ->assertSet('status', EnviarEnlaceRecuperacionAction::MENSAJE)
            ->assertSee('Reenviar en');
        $this->assertLessThanOrEqual(40, $componente->get('espera'));
        $this->assertGreaterThan(0, $componente->get('espera'));

        Notification::assertSentToTimes(
            Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail(),
            RestablecerPasswordNotification::class,
            1
        );
    }

    public function test_pasado_el_minuto_se_puede_pedir_otro(): void
    {
        $componente = Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_EXISTENTE)
            ->call('sendResetLink');

        $this->travel(61)->seconds();

        $componente->call('sendResetLink')->assertSet('espera', 60);

        Notification::assertSentToTimes(
            Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail(),
            RestablecerPasswordNotification::class,
            2
        );
    }

    public function test_el_contador_sale_igual_con_un_correo_que_no_existe(): void
    {
        Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_INEXISTENTE)
            ->call('sendResetLink')
            ->assertSet('status', EnviarEnlaceRecuperacionAction::MENSAJE)
            ->assertSet('espera', 60)
            ->assertSee('Reenviar en');

        Notification::assertNothingSent();
    }

    public function test_al_recargar_la_pagina_sigue_el_contador(): void
    {
        Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_INEXISTENTE)
            ->call('sendResetLink');

        $this->travel(15)->seconds();

        $recargada = Livewire::test('auth.forgot-password')
            ->assertSet('email', self::CORREO_INEXISTENTE)
            ->assertSee('Reenviar en');

        $this->assertGreaterThan(0, $recargada->get('espera'));
        $this->assertLessThanOrEqual(45, $recargada->get('espera'));
    }

    public function test_al_recargar_pasado_el_minuto_ya_no_hay_contador(): void
    {
        Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_INEXISTENTE)
            ->call('sendResetLink');

        $this->travel(61)->seconds();

        Livewire::test('auth.forgot-password')->assertSet('espera', 0)->assertSet('email', '');
    }

    public function test_la_pagina_responde_200(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar enlace');
    }
}
