<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Bajas: bloquean login, revocan tokens, conservan historial y quedan en bitácora. */
class BajaAccesoTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        $usuarioId = $this->usuario(self::MARIA)->id;

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $usuarioId))
            ->where('distribuidora_id', $this->ramirez()->id)->firstOrFail();
    }

    public function test_dar_de_baja_a_un_cliente_mayorista_revoca_tokens_y_bloquea_el_login(): void
    {
        $maria = $this->usuario(self::MARIA);
        $maria->createToken('app');
        $this->assertGreaterThan(0, $maria->tokens()->count());

        $this->comoPanel(self::ADMIN);
        $afiliacion = $this->afiliacionDeMaria();
        Livewire::test('distribuidora.usuarios')->call('darDeBaja', 'revendedor', $afiliacion->id);

        $this->assertSame('inactivo', $afiliacion->fresh()->estado);
        $this->assertSame(0, $maria->tokens()->count());
        $this->assertNotNull($afiliacion->fresh(), 'el historial se conserva');

        $baja = Auditoria::withoutGlobalScopes()->where('accion', 'acceso.baja')->latest('id')->firstOrFail();
        $this->assertSame($afiliacion->id, (int) $baja->entidad_id);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])
            ->assertStatus(422)->assertJsonFragment(['Tu acceso fue dado de baja. Comunícate con tu distribuidora.']);
    }

    public function test_reactivar_devuelve_el_acceso(): void
    {
        $this->comoPanel(self::ADMIN);
        $afiliacion = $this->afiliacionDeMaria();
        Livewire::test('distribuidora.usuarios')->call('darDeBaja', 'revendedor', $afiliacion->id)
            ->call('reactivar', 'revendedor', $afiliacion->id);

        $this->assertSame('activo', $afiliacion->fresh()->estado);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])->assertOk();
    }

    public function test_dar_de_baja_a_cliente_directo_por_api_revoca_su_token(): void
    {
        $jose = $this->usuario(self::JOSE);
        $token = $jose->createToken('app')->plainTextToken;
        $cliente = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $jose->id)->firstOrFail();

        $this->comoApi(self::ADMIN);
        $this->postJson("/api/distribuidora/accesos/cliente/{$cliente->id}/baja", ['motivo' => 'Ya no compra'])->assertOk();

        $this->assertSame('inactivo', $cliente->fresh()->estado);
        $this->assertSame(0, $jose->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_dar_de_baja_a_un_empleado_y_no_a_si_mismo(): void
    {
        $empleado = $this->usuario(self::EMPLEADO);
        $empleado->createToken('app');
        $this->comoPanel(self::ADMIN);
        $staff = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $empleado->id)->firstOrFail();

        Livewire::test('distribuidora.usuarios')->call('toggleEstadoEmpleado', $staff->id);
        $this->assertSame('inactivo', $staff->fresh()->estado);
        $this->assertSame(0, $empleado->tokens()->count());
    }

    public function test_no_se_puede_dar_de_baja_a_alguien_de_otra_distribuidora(): void
    {
        $afiliacion = $this->afiliacionDeMaria();
        $this->comoApi(self::OTRA_ADMIN);
        $this->postJson("/api/distribuidora/accesos/revendedor/{$afiliacion->id}/baja")->assertNotFound();
        $this->assertSame('activo', $afiliacion->fresh()->estado);
    }

    public function test_un_empleado_no_puede_dar_bajas_por_api(): void
    {
        $afiliacion = $this->afiliacionDeMaria();
        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/distribuidora/accesos/revendedor/{$afiliacion->id}/baja")->assertForbidden();
    }
}
