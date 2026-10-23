<?php

namespace Tests\Feature\Perfil;

use App\Mail\AvisoCambioCorreoMail;
use App\Mail\ConfirmarCambioContactoMail;
use App\Models\Auditoria;
use App\Models\CambioContactoPendiente;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * El revendedor cambia su foto, correo y teléfono desde la app. Correo y
 * teléfono solo cambian al abrir el enlace firmado que le mandamos.
 */
class CambioContactoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
    private const JOSE  = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('s3');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function token(string $email): string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $token;
    }

    private function enlaceEnviadoA(string $correo): string
    {
        $url = null;
        Mail::assertSent(ConfirmarCambioContactoMail::class, function ($mail) use ($correo, &$url) {
            if ($mail->hasTo($correo)) {
                $url = $mail->url;

                return true;
            }

            return false;
        });

        return $url;
    }

    private function maria(): Usuario
    {
        return Usuario::where('email', self::MARIA)->firstOrFail();
    }

    public function test_sube_su_foto_y_la_devuelve_el_perfil(): void
    {
        $token = $this->token(self::MARIA);

        $url = $this->withToken($token)->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->image('yo.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.foto_url');

        $this->assertNotNull($url);
        $this->assertSame($url, Revendedor::where('usuario_id', $this->maria()->id)->value('foto_url'));
        $this->assertCount(1, Storage::disk('s3')->files('perfiles/revendedores'));

        $this->withToken($token)->getJson('/api/perfil')->assertJsonPath('data.foto_url', $url);
    }

    public function test_la_foto_tiene_que_ser_imagen(): void
    {
        $this->withToken($this->token(self::MARIA))->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['foto']);
    }

    public function test_cambiar_correo_manda_enlace_al_nuevo_y_aviso_al_anterior_sin_cambiar_aun(): void
    {
        $token = $this->token(self::MARIA);
        $usuarioId = $this->maria()->id;

        $this->withToken($token)->postJson('/api/perfil/correo', ['email' => 'maria.nueva@gmail.com'])
            ->assertOk()
            ->assertJsonPath('data.email', self::MARIA)
            ->assertJsonPath('data.cambios_pendientes.email', 'maria.nueva@gmail.com');

        Mail::assertSent(ConfirmarCambioContactoMail::class, fn ($m) => $m->hasTo('maria.nueva@gmail.com') && ! $m->hasTo(self::MARIA));
        Mail::assertSent(AvisoCambioCorreoMail::class, fn ($m) => $m->hasTo(self::MARIA));
        $this->assertSame(self::MARIA, Usuario::find($usuarioId)->email);

        $url = $this->enlaceEnviadoA('maria.nueva@gmail.com');
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk()->assertSee('data-confirmar-cambio="listo"', false)
            ->assertSee('Abrir la app')->assertSee('Descargar la app');

        $this->assertDatabaseHas('usuarios', ['id' => $usuarioId, 'email' => 'maria.nueva@gmail.com']);
        $this->assertTrue(Auditoria::where('accion', 'perfil.correo_cambiado')->exists());

        // Un segundo clic no hace nada nuevo.
        $this->get($url)->assertOk()->assertSee('data-confirmar-cambio="ya_estaba"', false);
    }

    public function test_cambiar_telefono_manda_enlace_al_correo_actual_y_aplica_al_confirmar(): void
    {
        $token = $this->token(self::MARIA);
        $usuarioId = $this->maria()->id;

        $this->withToken($token)->postJson('/api/perfil/telefono', ['telefono' => '961 555 0000'])
            ->assertOk()->assertJsonPath('data.cambios_pendientes.telefono', '961 555 0000');

        $this->assertNotSame('961 555 0000', Revendedor::where('usuario_id', $usuarioId)->value('telefono'));

        $url = $this->enlaceEnviadoA(self::MARIA);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertSee('data-confirmar-cambio="listo"', false);

        $this->assertSame('961 555 0000', Revendedor::where('usuario_id', $usuarioId)->value('telefono'));
        $this->assertTrue(Auditoria::where('accion', 'perfil.telefono_cambiado')->exists());
    }

    public function test_el_enlace_vencido_no_cambia_nada(): void
    {
        $token = $this->token(self::MARIA);
        $this->withToken($token)->postJson('/api/perfil/correo', ['email' => 'maria.tarde@gmail.com'])->assertOk();
        $url = $this->enlaceEnviadoA('maria.tarde@gmail.com');

        $this->travel(61)->minutes();
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertForbidden();
        $this->assertSame(self::MARIA, $this->maria()->email);

        // Aunque alguien firme otro enlace, el registro ya venció.
        $cambio = CambioContactoPendiente::latest('id')->firstOrFail();
        $this->get(URL::temporarySignedRoute('perfil.confirmar-cambio', now()->addMinutes(5), ['cambio' => $cambio->id]))
            ->assertOk()->assertSee('data-confirmar-cambio="vencido"', false);
        $this->assertSame(self::MARIA, $this->maria()->email);
    }

    public function test_el_enlace_alterado_se_rechaza(): void
    {
        $this->withToken($this->token(self::MARIA))->postJson('/api/perfil/correo', ['email' => 'maria.x@gmail.com'])->assertOk();
        $url = $this->enlaceEnviadoA('maria.x@gmail.com');

        $this->get(str_replace('/confirmar-cambio/', '/confirmar-cambio/9', $url))->assertForbidden();
    }

    public function test_si_otro_tomo_el_correo_mientras_tanto_no_se_aplica(): void
    {
        $this->withToken($this->token(self::MARIA))->postJson('/api/perfil/correo', ['email' => 'disputado@gmail.com'])->assertOk();
        $url = $this->enlaceEnviadoA('disputado@gmail.com');

        Usuario::where('email', self::JOSE)->update(['email' => 'disputado@gmail.com']);
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk()->assertSee('data-confirmar-cambio="ocupado"', false);
        $this->assertSame(1, Usuario::where('email', 'disputado@gmail.com')->count());
    }

    public function test_no_acepta_un_correo_que_ya_usa_otra_cuenta(): void
    {
        $this->withToken($this->token(self::MARIA))->postJson('/api/perfil/correo', ['email' => self::JOSE])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_solo_el_revendedor_usa_este_flujo(): void
    {
        $this->withToken($this->token(self::JOSE))->postJson('/api/perfil/correo', ['email' => 'jose.nuevo@gmail.com'])
            ->assertForbidden();
    }

    public function test_sin_token_no_entra(): void
    {
        $this->postJson('/api/perfil/correo', ['email' => 'x@gmail.com'])->assertUnauthorized();
        $this->postJson('/api/perfil/telefono', ['telefono' => '9610000000'])->assertUnauthorized();
        $this->postJson('/api/perfil/foto', [])->assertUnauthorized();
    }
}
