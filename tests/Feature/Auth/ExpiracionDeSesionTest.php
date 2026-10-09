<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Services\Auth\ExpiracionDeSesion;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TG-187 (A5) — Expiracion de las sesiones de la app.
 *
 * Antes, el token que la app guarda en el telefono no caducaba nunca: uno
 * emitido hace meses seguia abriendo la API. Ahora se vence por inactividad
 * y, ademas, tiene un tope de vida.
 *
 * Las pruebas usan un token de verdad (Bearer), no actingAs, porque lo que
 * se esta probando es justamente el guard de Sanctum.
 */
class ExpiracionDeSesionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Inicia sesion como la revendedora del seeder y regresa su token. */
    private function tokenDeMaria(): string
    {
        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'maria.lopez@revendedor.test',
            'password' => 'password',
        ])->assertOk();

        return $respuesta->json('data.token');
    }

    /**
     * Pide el catalogo como lo haria la app.
     *
     * Auth::forgetGuards() no es un adorno: dentro de una misma prueba todas
     * las peticiones comparten la aplicacion, y el guard se queda con el
     * usuario que ya resolvio. Sin olvidarlo, la segunda peticion ni siquiera
     * pasaria por Sanctum y el token vencido seguiria respondiendo 200. En el
     * servidor de verdad cada peticion arranca limpia.
     */
    private function catalogoCon(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/catalogo');
    }

    private function cuentaDeMaria(): Usuario
    {
        return Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
    }

    public function test_un_token_que_se_acaba_de_usar_sigue_sirviendo(): void
    {
        $token = $this->tokenDeMaria();

        $this->catalogoCon($token)->assertOk();

        // Dentro del periodo tampoco pasa nada (13 de los 14 dias).
        $this->travel(13)->days();

        $this->catalogoCon($token)->assertOk();
    }

    public function test_despues_del_periodo_sin_usar_la_app_la_sesion_se_vence(): void
    {
        $token = $this->tokenDeMaria();
        $this->catalogoCon($token)->assertOk();

        // Deja el telefono guardado dos semanas y media.
        $this->travel(15)->days();

        $this->catalogoCon($token)->assertStatus(401);
    }

    public function test_un_token_que_nunca_se_uso_tambien_se_vence(): void
    {
        // Sanctum escribe last_used_at en la PRIMERA peticion; mientras no
        // haya ninguna, la inactividad se cuenta desde que se creo.
        $token = $this->tokenDeMaria();

        $this->travel(15)->days();

        $this->catalogoCon($token)->assertStatus(401);
    }

    public function test_el_token_vencido_se_borra_de_la_base(): void
    {
        $token = $this->tokenDeMaria();
        $this->assertSame(1, $this->cuentaDeMaria()->tokens()->count());

        $this->travel(15)->days();
        $this->catalogoCon($token)->assertStatus(401);

        // No se queda ahi respondiendo 401 para siempre.
        $this->assertSame(0, $this->cuentaDeMaria()->tokens()->count());
    }

    public function test_usar_la_app_reinicia_el_reloj_de_inactividad(): void
    {
        $token = $this->tokenDeMaria();

        // Entra cada diez dias: nunca llega a los catorce sin usarla.
        foreach ([10, 10, 10] as $dias) {
            $this->travel($dias)->days();
            $this->catalogoCon($token)->assertOk();
        }

        // Treinta dias despues del login, la sesion sigue viva.
        $this->assertSame(1, $this->cuentaDeMaria()->tokens()->count());
    }

    public function test_aunque_la_use_a_diario_el_token_no_vive_para_siempre(): void
    {
        // Solo el tope absoluto: se apaga la inactividad para que no sea ella
        // la que venza la sesion.
        config(['sanctum.inactividad_minutos' => 0]);

        $token = $this->tokenDeMaria();

        $this->travel(89)->days();
        $this->catalogoCon($token)->assertOk();

        // Dia 91: ya paso el tope de 90 dias, aunque la use seguido.
        $this->travel(2)->days();
        $this->catalogoCon($token)->assertStatus(401);
    }

    public function test_el_periodo_se_puede_configurar(): void
    {
        // Es lo que se usa para ensenarlo en vivo: dos minutos desde el .env.
        config(['sanctum.inactividad_minutos' => 2]);

        $token = $this->tokenDeMaria();
        $this->catalogoCon($token)->assertOk();

        $this->travel(3)->minutes();

        $this->catalogoCon($token)->assertStatus(401);
    }

    public function test_en_cero_la_sesion_no_caduca(): void
    {
        // La salida de emergencia: deja el comportamiento de antes de TG-187.
        config([
            'sanctum.inactividad_minutos' => 0,
            'sanctum.expiration'          => 0,
        ]);

        $token = $this->tokenDeMaria();

        $this->travel(400)->days();

        $this->catalogoCon($token)->assertOk();
    }

    public function test_despues_de_vencerse_puede_volver_a_entrar(): void
    {
        $token = $this->tokenDeMaria();

        $this->travel(15)->days();
        $this->catalogoCon($token)->assertStatus(401);

        // Lo que hace la app: manda al login y con la misma contrasena entra.
        $nuevo = $this->tokenDeMaria();

        $this->assertNotSame($token, $nuevo);
        $this->catalogoCon($nuevo)->assertOk();
    }

    public function test_la_regla_dice_por_que_se_vencio(): void
    {
        $regla = app(ExpiracionDeSesion::class);

        $this->tokenDeMaria();
        $token = $this->cuentaDeMaria()->tokens()->firstOrFail();

        $this->assertNull($regla->motivo($token));

        $this->travel(15)->days();
        $this->assertSame(ExpiracionDeSesion::POR_INACTIVIDAD, $regla->motivo($token));

        // Usandolo seguido, el motivo cambia: lo que lo mata es la antiguedad.
        $token->forceFill(['last_used_at' => now()])->save();
        $this->travel(100)->days();
        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertSame(ExpiracionDeSesion::POR_ANTIGUEDAD, $regla->motivo($token));
    }
}
