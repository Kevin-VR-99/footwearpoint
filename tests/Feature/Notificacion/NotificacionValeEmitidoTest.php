<?php

namespace Tests\Feature\Notificacion;

use App\Models\ClienteDirecto;
use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Complemento de AvisoDeValeEmitidoTest (TG-281): dueño revendedor por
 * afiliación, revendedor sin cuenta, un solo aviso por emisión y tokens inválidos.
 */
class NotificacionValeEmitidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private EnviadorPushFalso $push;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->push = new EnviadorPushFalso();
        $this->app->instance(EnviadorPush::class, $this->push);
    }

    private function cuenta(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    private function celular(Usuario $u, string $token): void
    {
        DispositivoFcm::create(['usuario_id' => $u->id, 'token' => $token, 'plataforma' => 'android', 'ultimo_uso_at' => now()]);
    }

    private function emitir(string $tipo, int $propietarioId, float $monto = 250)
    {
        $this->actingAs($this->cuenta('admin@calzadosramirez.test'));
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $this->postJson('/api/vales', [
            'propietario_tipo' => $tipo,
            'propietario_id'   => $propietarioId,
            'monto_original'   => $monto,
            'motivo'           => 'Prueba',
        ]);
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        $maria = $this->cuenta('maria.lopez@revendedor.test');
        $revendedor = Revendedor::withoutGlobalScopes()->where('usuario_id', $maria->id)->firstOrFail();
        $admin = $this->cuenta('admin@calzadosramirez.test');
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->where('distribuidora_id', Tenant::id())
            ->firstOrFail();
    }

    public function test_revendedor_recibe_aviso_y_push(): void
    {
        $maria = $this->cuenta('maria.lopez@revendedor.test');
        $this->celular($maria, 'token-maria');
        $afiliacion = $this->afiliacionDeMaria();

        $this->emitir('revendedor', $afiliacion->id)->assertCreated();

        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('usuario_id', $maria->id)->where('tipo', 'vale_emitido')->count());
        // Un solo aviso y un solo push por emisión.
        $this->assertCount(1, $this->push->envios);
        $this->assertSame(['token-maria'], $this->push->envios[0]['tokens']);
    }

    public function test_revendedor_sin_cuenta_no_truena_ni_notifica(): void
    {
        $afiliacion = $this->afiliacionDeMaria();
        Revendedor::withoutGlobalScopes()->whereKey($afiliacion->revendedor_id)->update(['usuario_id' => null]);

        $antes = Notificacion::withoutGlobalScopes()->where('tipo', 'vale_emitido')->count();
        $this->emitir('revendedor', $afiliacion->id)->assertCreated();

        $this->assertSame($antes, Notificacion::withoutGlobalScopes()->where('tipo', 'vale_emitido')->count());
        $this->assertSame([], $this->push->envios);
    }

    public function test_token_invalido_se_borra(): void
    {
        $jose = $this->cuenta('jose.hernandez@cliente.test');
        $this->celular($jose, 'token-viejo');
        $this->push->tokensInvalidos = ['token-viejo'];
        $cliente = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $jose->id)->firstOrFail();

        $this->emitir('cliente_directo', $cliente->id)->assertCreated();

        $this->assertFalse(DispositivoFcm::where('token', 'token-viejo')->exists());
    }
}
