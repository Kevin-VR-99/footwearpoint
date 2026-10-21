<?php

namespace Tests\Feature\Vale;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Vale\EmitirValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * TG-281 — Avisar cuando se emite un vale.
 *
 * Faltaba: emitir un vale desde el panel no avisaba nada, ni en la campanita
 * de la app ni por push. El vale aparecía solo si a la persona se le ocurría
 * entrar a mirar, y nadie entra a mirar algo que no sabe que existe.
 *
 * Va con DatabaseTruncation y no con RefreshDatabase a propósito: el aviso
 * se manda con DB::afterCommit, y RefreshDatabase mantiene abierta una
 * transacción que al final se revierte, así que esos callbacks nunca corren.
 * Con RefreshDatabase estas pruebas pasarían sin probar nada.
 */
class AvisoDeValeEmitidoTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoEmpleado(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        $cuenta = Usuario::where('email', self::MARIA)->firstOrFail();
        $revendedor = Revendedor::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->firstOrFail();
    }

    private function emitir(array $extra = []): Vale
    {
        return app(EmitirValeAction::class)->ejecutar([
            'propietario_tipo' => 'revendedor',
            'propietario_id'   => $this->afiliacionDeMaria()->id,
            'monto_original'   => 500.0,
            'motivo'           => 'Devolución de mercancía',
            ...$extra,
        ]);
    }

    public function test_al_emitir_un_vale_le_queda_el_aviso_en_su_bandeja(): void
    {
        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));
        $this->comoEmpleado();

        $cuenta = Usuario::where('email', self::MARIA)->firstOrFail();
        $vale = $this->emitir();

        $aviso = Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $cuenta->id)
            ->where('tipo', 'vale_emitido')
            ->latest('id')
            ->first();

        $this->assertNotNull($aviso, 'No le llegó ningún aviso del vale.');
        $this->assertSame('Te emitieron un vale', $aviso->titulo);
        $this->assertStringContainsString($vale->folio, $aviso->mensaje);
        $this->assertStringContainsString('500.00', $aviso->mensaje);
        // El motivo explica de dónde salió el vale.
        $this->assertStringContainsString('Devolución de mercancía', $aviso->mensaje);
        // Para que al tocarlo la app pueda abrir sus vales.
        $this->assertSame('vale', $aviso->entidad_tipo);
        $this->assertSame($vale->id, (int) $aviso->entidad_id);
        $this->assertNull($aviso->leida_at);
    }

    public function test_tambien_le_llega_el_push_a_sus_telefonos(): void
    {
        $cuenta = Usuario::where('email', self::MARIA)->firstOrFail();

        DispositivoFcm::create([
            'usuario_id' => $cuenta->id,
            'token'      => 'token-de-prueba',
            'plataforma' => 'android',
        ]);

        $enviador = Mockery::mock(EnviadorPush::class);
        $enviador->shouldReceive('enviar')
            ->once()
            ->withArgs(function (array $tokens, string $titulo, string $mensaje, array $datos) {
                return $tokens === ['token-de-prueba']
                    && $titulo === 'Te emitieron un vale'
                    && $datos['tipo'] === 'vale_emitido'
                    && $datos['entidad_tipo'] === 'vale';
            })
            ->andReturn([]);

        $this->app->instance(EnviadorPush::class, $enviador);
        $this->comoEmpleado();

        $this->emitir();
    }

    /**
     * Si Firebase falla, el vale se emite igual y el aviso de la bandeja
     * queda: la persona lo ve al entrar a la app.
     */
    public function test_si_el_push_falla_el_vale_se_emite_y_el_aviso_queda(): void
    {
        $cuenta = Usuario::where('email', self::MARIA)->firstOrFail();

        DispositivoFcm::create([
            'usuario_id' => $cuenta->id,
            'token'      => 'token-de-prueba',
            'plataforma' => 'android',
        ]);

        $enviador = Mockery::mock(EnviadorPush::class);
        $enviador->shouldReceive('enviar')->andThrow(new \RuntimeException('Firebase caído'));

        $this->app->instance(EnviadorPush::class, $enviador);
        $this->comoEmpleado();

        $vale = $this->emitir();

        $this->assertDatabaseHas('vales', ['id' => $vale->id]);
        $this->assertDatabaseHas('notificaciones', [
            'usuario_id' => $cuenta->id,
            'tipo'       => 'vale_emitido',
        ]);
    }

    /**
     * Un vale puede ser de alguien que todavía no tiene cuenta en la app: el
     * panel registra el contacto antes de darle acceso. Ahí no hay a quién
     * avisarle, y no es un error.
     */
    public function test_a_quien_no_tiene_cuenta_no_se_le_avisa_y_el_vale_se_emite(): void
    {
        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));
        $this->comoEmpleado();

        $sinCuenta = Revendedor::withoutGlobalScopes()->whereNull('usuario_id')->firstOrFail();
        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $sinCuenta->id)
            ->firstOrFail();

        $antes = Notificacion::withoutGlobalScopes()->count();

        $vale = app(EmitirValeAction::class)->ejecutar([
            'propietario_tipo' => 'revendedor',
            'propietario_id'   => $afiliacion->id,
            'monto_original'   => 300.0,
            'motivo'           => 'Ajuste',
        ]);

        $this->assertDatabaseHas('vales', ['id' => $vale->id]);
        $this->assertSame($antes, Notificacion::withoutGlobalScopes()->count());
    }
}
