<?php

namespace Tests\Feature\Notificacion;

use App\Models\Notificacion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Marcar y borrar toda la bandeja, y no perder avisos por el tope de 50. */
class BandejaCompletaTest extends TestCase
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

    public function test_marca_todas_y_borra_solo_las_suyas(): void
    {
        $maria = $this->como(self::MARIA);
        $this->avisoDe($maria, 'Una');
        $this->avisoDe($maria, 'Otra');
        $ajena = $this->avisoDe(Usuario::where('email', self::JOSE)->firstOrFail(), 'De José');

        $this->como(self::MARIA);
        $this->getJson('/api/notificaciones?per_page=10')
            ->assertOk()
            ->assertJsonPath('no_leidas', 2);

        $this->postJson('/api/notificaciones/marcar-todas-leidas')
            ->assertOk()
            ->assertJsonPath('marcadas', 2);

        $this->assertSame(0, Notificacion::where('usuario_id', $maria->id)->whereNull('leida_at')->count());
        $this->assertNull($ajena->fresh()->leida_at);

        $this->deleteJson('/api/notificaciones')->assertOk()->assertJsonPath('borradas', 2);
        $this->assertSame(0, Notificacion::where('usuario_id', $maria->id)->count());
        $this->assertNotNull($ajena->fresh());
    }

    public function test_la_segunda_pagina_trae_los_avisos_viejos_y_el_etag_ahorra_el_cuerpo(): void
    {
        $maria = $this->como(self::MARIA);

        for ($i = 0; $i < 30; $i++) {
            $this->avisoDe($maria, 'Aviso '.$i);
        }

        $pagina = $this->getJson('/api/notificaciones?page=2&per_page=25')->assertOk();
        $this->assertCount(5, $pagina->json('data'));
        $this->assertSame(30, $pagina->json('meta.total'));
        $this->assertSame(30, $pagina->json('no_leidas'));

        $etag = $pagina->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/notificaciones?page=2&per_page=25')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
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

    private function avisoDe(Usuario $usuario, string $titulo): Notificacion
    {
        return Notificacion::create([
            'usuario_id'       => $usuario->id,
            'distribuidora_id' => 1,
            'tipo'             => 'pedido_estado',
            'titulo'           => $titulo,
            'mensaje'          => 'Aviso de prueba.',
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => 1,
        ]);
    }
}
