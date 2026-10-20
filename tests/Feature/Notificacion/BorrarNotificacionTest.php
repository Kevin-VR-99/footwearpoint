<?php

namespace Tests\Feature\Notificacion;

use App\Models\Notificacion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-281 (E19-11) — Quitar un aviso de la bandeja desde la app.
 *
 * La bandeja se llenaba y no había forma de limpiarla. Cada renglón de
 * notificaciones es de UNA persona (usuario_id), así que borrar no le quita
 * el aviso a nadie más, y lo que pasó de verdad —el pedido, el pago, el
 * vale— sigue en su tabla y en la auditoría.
 */
class BorrarNotificacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $usuario;
    }

    private function avisoDe(Usuario $usuario, string $titulo = 'Tu pedido va en camino'): Notificacion
    {
        return Notificacion::create([
            'usuario_id'       => $usuario->id,
            'distribuidora_id' => 1,
            'tipo'             => 'pedido_estado',
            'titulo'           => $titulo,
            'mensaje'          => 'Aviso de prueba.',
        ]);
    }

    public function test_la_persona_borra_un_aviso_suyo(): void
    {
        $maria = $this->como(self::MARIA);
        $aviso = $this->avisoDe($maria);

        $this->deleteJson("/api/notificaciones/{$aviso->id}")->assertOk();

        $this->assertDatabaseMissing('notificaciones', ['id' => $aviso->id]);
    }

    public function test_borrar_uno_no_toca_los_demas(): void
    {
        $maria = $this->como(self::MARIA);
        $borrado = $this->avisoDe($maria, 'Este se va');
        $queda = $this->avisoDe($maria, 'Este se queda');

        $this->deleteJson("/api/notificaciones/{$borrado->id}")->assertOk();

        $this->assertDatabaseMissing('notificaciones', ['id' => $borrado->id]);
        $this->assertDatabaseHas('notificaciones', ['id' => $queda->id]);
    }

    /**
     * Lo importante de la tarjeta: la bandeja es de cada quien. Responde 404
     * y no 403, para no decirle a nadie que ese aviso existe.
     */
    public function test_nadie_puede_borrar_el_aviso_de_otra_persona(): void
    {
        $maria = $this->como(self::MARIA);
        $suyo = $this->avisoDe($maria);

        $this->como(self::JOSE);

        $this->deleteJson("/api/notificaciones/{$suyo->id}")->assertStatus(404);

        $this->assertDatabaseHas('notificaciones', ['id' => $suyo->id]);
    }

    public function test_un_aviso_que_no_existe_responde_404(): void
    {
        $this->como(self::MARIA);

        $this->deleteJson('/api/notificaciones/999999')->assertStatus(404);
    }

    public function test_sin_sesion_no_se_borra_nada(): void
    {
        $maria = Usuario::where('email', self::MARIA)->firstOrFail();
        $aviso = $this->avisoDe($maria);

        $this->deleteJson("/api/notificaciones/{$aviso->id}")->assertStatus(401);

        $this->assertDatabaseHas('notificaciones', ['id' => $aviso->id]);
    }

    public function test_el_aviso_borrado_ya_no_sale_en_la_bandeja(): void
    {
        $maria = $this->como(self::MARIA);
        $borrado = $this->avisoDe($maria, 'Este se va');
        $this->avisoDe($maria, 'Este se queda');

        $this->deleteJson("/api/notificaciones/{$borrado->id}")->assertOk();

        $this->getJson('/api/notificaciones')
            ->assertOk()
            ->assertJsonMissing(['titulo' => 'Este se va'])
            ->assertJsonFragment(['titulo' => 'Este se queda']);
    }
}
