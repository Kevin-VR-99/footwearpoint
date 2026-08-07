<?php

namespace Tests\Feature\Catalogo;

use App\Models\DistribuidoraStaff;
use App\Models\Marca;
use App\Models\Suscripcion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarcaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoAdmin(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    private function distribuidoraId(): int
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();

        return (int) DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->firstOrFail()
            ->distribuidora_id;
    }

    /**
     * El DemoDistribuidoraSeeder de Fase 0 NO crea ninguna suscripción para
     * la distribuidora demo (lo confirmamos a mano con Tinker durante las
     * pruebas manuales) — por eso cada prueba aquí crea la suya con un
     * límite conocido, en vez de asumir que ya existe una.
     */
    private function crearSuscripcion(int $limite): void
    {
        Suscripcion::create([
            'distribuidora_id'              => $this->distribuidoraId(),
            'plan_id'                       => 1,
            'fecha_inicio'                  => now()->toDateString(),
            'estado'                        => 'activa',
            'precio_base_contratado'        => 299,
            'lineas_incluidas_contratadas'  => $limite,
            'precio_linea_extra_contratado' => 99,
            'lineas_extra_contratadas'      => 0,
        ]);
    }

    public function test_crear_una_marca_dentro_del_limite_del_plan_se_permite(): void
    {
        $this->autenticarComoAdmin();
        $activas = Marca::where('activa', true)->count();
        $this->crearSuscripcion($activas + 1); // un cupo libre

        $this->postJson('/api/marcas', ['nombre' => 'Marca Nueva Dentro Del Limite'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Marca Nueva Dentro Del Limite')
            ->assertJsonPath('data.activa', true);
    }

    /**
     * La prueba explícita que pide el documento de tareas (sección 4,
     * Paquete B): "Crear la marca número (límite + 1) es rechazada con un
     * mensaje claro."
     */
    public function test_crear_una_marca_que_excede_el_limite_del_plan_es_rechazada(): void
    {
        $this->autenticarComoAdmin();
        $activas = Marca::where('activa', true)->count();
        $this->crearSuscripcion($activas); // límite ya alcanzado, sin cupo

        $respuesta = $this->postJson('/api/marcas', ['nombre' => 'Marca De Mas'])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertStringContainsString('límite', $respuesta->json('message'));
        $this->assertDatabaseMissing('marcas', ['nombre' => 'Marca De Mas']);
    }

    public function test_crear_marca_sin_ninguna_suscripcion_activa_es_rechazada(): void
    {
        $this->autenticarComoAdmin();
        // A propósito, NO se crea ninguna suscripción aquí.

        $this->postJson('/api/marcas', ['nombre' => 'Marca Sin Plan'])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_sin_autenticar_no_se_puede_crear_una_marca(): void
    {
        $this->postJson('/api/marcas', ['nombre' => 'Intento Sin Sesion'])
            ->assertStatus(401);
    }
}
