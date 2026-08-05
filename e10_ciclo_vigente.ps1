# ============================================================
#  FootwearPoint - Paquete C / E10: agrega GET /api/ciclos/vigente
#  Reescribe 3 archivos ya existentes. No toca nada mas.
#
#  USO: guardar en C:\xampp\htdocs\footwearpoint y correr:
#     powershell -ExecutionPolicy Bypass -File .\e10_ciclo_vigente.ps1
# ============================================================

if (-not (Test-Path "artisan")) {
    Write-Host "ERROR: corre este script desde la raiz del proyecto (donde esta 'artisan')." -ForegroundColor Red
    exit 1
}

function Escribir($ruta, $texto) {
    $dir = Split-Path -Parent $ruta
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Join-Path (Get-Location) $ruta), $texto, $utf8SinBom)
    Write-Host "actualizado   $ruta" -ForegroundColor Green
}

Escribir "routes\api\ciclos.php" @'
<?php

use App\Http\Controllers\Api\CicloCompraController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Ciclos de compra (E10)
| Mismo stack de middleware que routes/api/stock.php y distribuidora.php.
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/ciclos.php';
*/

Route::prefix('ciclos')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        // Va ANTES de {id} para que 'vigente' no se lea como un identificador.
        Route::get('vigente', [CicloCompraController::class, 'vigente']);

        Route::get('{id}', [CicloCompraController::class, 'show'])->whereNumber('id');

        Route::post('{id}/cerrar', [CicloCompraController::class, 'cerrar'])->whereNumber('id');
        Route::post('{id}/solicitar-fabrica', [CicloCompraController::class, 'solicitarFabrica'])->whereNumber('id');
        Route::post('{id}/marcar-transito', [CicloCompraController::class, 'marcarTransito'])->whereNumber('id');
        Route::post('{id}/marcar-recibido', [CicloCompraController::class, 'marcarRecibido'])->whereNumber('id');
        Route::post('{id}/finalizar', [CicloCompraController::class, 'finalizar'])->whereNumber('id');
    });
'@

Escribir "app\Http\Controllers\Api\CicloCompraController.php" @'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CicloCompraResource;
use App\Models\CicloCompra;
use App\Services\Ciclo\AsignarCicloVigenteService;
use App\Services\Ciclo\ResultadoCiclo;
use App\Services\Ciclo\TransicionCicloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * E10 - Ciclos de compra.
 *
 * Ningun endpoint de aqui recibe cuerpo: la transicion la determina la ruta,
 * no un campo que mande el cliente. Por eso no hay Form Requests (la
 * convencion 1.6 pide uno "por cada endpoint que reciba datos").
 */
class CicloCompraController extends Controller
{
    public function __construct(private TransicionCicloService $ciclos)
    {
    }

    /**
     * Punto de entrada de la pantalla "Ciclo de Compra Actual": el frontend
     * no tiene otra forma de conocer el id del ciclo vigente.
     *
     * OJO: si la distribuidora todavia no tiene ciclo abierto, esta llamada
     * lo crea (es el mismo servicio que usa el Paquete D al confirmar un
     * pedido). Es un GET con efecto de escritura; se hizo asi porque el
     * lider pidio exponer AsignarCicloVigente tal cual. Si el equipo
     * prefiere semantica estricta, se cambia la ruta a POST y ya.
     */
    public function vigente(AsignarCicloVigenteService $asignar): JsonResponse
    {
        Gate::authorize('view', CicloCompra::class);

        $ciclo = $asignar->paraDistribuidoraActual();

        return $this->responder(
            $this->ciclos->ver($ciclo->id),
            'Ciclo vigente de la distribuidora.'
        );
    }

    public function show(int $id): JsonResponse
    {
        Gate::authorize('view', CicloCompra::class);

        return $this->responder($this->ciclos->ver($id), 'Ciclo de compra.');
    }

    public function cerrar(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->cerrar($id),
            'Ciclo cerrado: ya no acepta pedidos nuevos.'
        );
    }

    public function solicitarFabrica(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->solicitarFabrica($id),
            'Ciclo solicitado a fabrica.'
        );
    }

    public function marcarTransito(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->marcarTransito($id),
            'Ciclo marcado en transito.'
        );
    }

    public function marcarRecibido(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->marcarRecibido($id),
            'Ciclo recibido en la distribuidora.'
        );
    }

    public function finalizar(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->finalizar($id),
            'Ciclo finalizado.'
        );
    }

    private function responder(ResultadoCiclo $resultado, string $mensaje): JsonResponse
    {
        return (new CicloCompraResource($resultado))
            ->additional(['message' => $mensaje])
            ->response();
    }
}
'@

Escribir "tests\Feature\Ciclo\CicloCompraTest.php" @'
<?php

namespace Tests\Feature\Ciclo;

use App\Models\CicloCompra;
use App\Models\ConfiguracionCiclo;
use App\Models\Usuario;
use App\Services\Ciclo\AsignarCicloVigenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CicloCompraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoEmpleado(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    private function distribuidoraId(): int
    {
        return (int) ConfiguracionCiclo::withoutGlobalScopes()
            ->where('activa', true)
            ->orderBy('id')
            ->firstOrFail()
            ->distribuidora_id;
    }

    /** Deja la distribuidora sin ciclos, para partir de un estado conocido. */
    private function sinCiclos(): void
    {
        CicloCompra::withoutGlobalScopes()->delete();
    }

    private function asignar(): CicloCompra
    {
        return app(AsignarCicloVigenteService::class)->para($this->distribuidoraId());
    }

    private function cicloListo(): CicloCompra
    {
        $this->autenticarComoEmpleado();
        $this->sinCiclos();

        return $this->asignar();
    }

    public function test_crea_un_ciclo_si_la_distribuidora_no_tiene_ninguno(): void
    {
        $ciclo = $this->cicloListo();

        $this->assertSame('abierto', $ciclo->estado);
        $this->assertNotEmpty($ciclo->nombre);
        $this->assertTrue(Carbon::parse($ciclo->fecha_cierre)->isFuture());
        $this->assertTrue(
            Carbon::parse($ciclo->fecha_cierre)
                ->greaterThanOrEqualTo(Carbon::parse($ciclo->fecha_apertura)),
            'chk_ciclo_fechas exige fecha_cierre >= fecha_apertura.'
        );
    }

    public function test_reutiliza_el_mismo_ciclo_mientras_no_pase_la_hora_de_cierre(): void
    {
        $primero = $this->cicloListo();
        $segundo = $this->asignar();

        $this->assertSame($primero->id, $segundo->id);
    }

    public function test_despues_de_la_hora_de_cierre_se_asigna_el_ciclo_siguiente(): void
    {
        $vigente = $this->cicloListo();

        // Un minuto despues del cierre, un pedido nuevo ya no entra a este ciclo.
        $this->travelTo(Carbon::parse($vigente->fecha_cierre)->addMinute());

        $siguiente = $this->asignar();

        $this->assertNotSame($vigente->id, $siguiente->id);
        $this->assertTrue(
            Carbon::parse($siguiente->fecha_cierre)
                ->greaterThan(Carbon::parse($vigente->fecha_cierre))
        );

        $this->travelBack();
    }

    public function test_el_cierre_cae_en_lunes_1_domingo_7(): void
    {
        $ciclo = $this->cicloListo();

        $config = ConfiguracionCiclo::withoutGlobalScopes()
            ->whereKey($ciclo->configuracion_ciclo_id)
            ->firstOrFail();

        // Convencion ISO-8601 confirmada por el equipo: 1 = lunes, 7 = domingo,
        // igual que en el DemoDistribuidoraSeeder de la Fase 0.
        $this->assertSame(
            (int) $config->dia_cierre,
            Carbon::parse($ciclo->fecha_cierre)->dayOfWeekIso
        );
    }

    public function test_el_endpoint_vigente_devuelve_el_ciclo_actual(): void
    {
        $this->autenticarComoEmpleado();
        $this->sinCiclos();

        $respuesta = $this->getJson('/api/ciclos/vigente')
            ->assertOk()
            ->assertJsonPath('data.estado', 'abierto')
            ->assertJsonStructure([
                'data' => ['id', 'nombre', 'fecha_cierre', 'pedidos', 'consolidado'],
                'message',
            ]);

        $this->assertDatabaseHas('ciclos_compra', [
            'id' => $respuesta->json('data.id'),
            'estado' => 'abierto',
        ]);
    }

    public function test_el_endpoint_vigente_no_crea_un_ciclo_nuevo_cada_vez(): void
    {
        $this->autenticarComoEmpleado();
        $this->sinCiclos();

        $primera = $this->getJson('/api/ciclos/vigente')->assertOk()->json('data.id');
        $segunda = $this->getJson('/api/ciclos/vigente')->assertOk()->json('data.id');

        $this->assertSame($primera, $segunda);
        $this->assertSame(1, CicloCompra::withoutGlobalScopes()->count());
    }

    public function test_la_cadena_completa_de_transiciones(): void
    {
        $ciclo = $this->cicloListo();

        $this->postJson("/api/ciclos/{$ciclo->id}/cerrar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrado');

        $this->postJson("/api/ciclos/{$ciclo->id}/solicitar-fabrica")
            ->assertOk()
            ->assertJsonPath('data.estado', 'solicitado')
            ->assertJsonStructure(['data' => ['consolidado', 'pedidos', 'total_pedidos']]);

        $this->postJson("/api/ciclos/{$ciclo->id}/marcar-transito")
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_transito');

        $this->postJson("/api/ciclos/{$ciclo->id}/marcar-recibido")
            ->assertOk()
            ->assertJsonPath('data.estado', 'recibido');

        $this->postJson("/api/ciclos/{$ciclo->id}/finalizar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'finalizado');

        $this->assertDatabaseHas('ciclos_compra', [
            'id' => $ciclo->id,
            'estado' => 'finalizado',
        ]);
    }

    public function test_solicitar_fabrica_guarda_sus_fechas(): void
    {
        $ciclo = $this->cicloListo();

        $this->postJson("/api/ciclos/{$ciclo->id}/cerrar")->assertOk();

        $respuesta = $this->postJson("/api/ciclos/{$ciclo->id}/solicitar-fabrica")->assertOk();

        $this->assertNotNull($respuesta->json('data.fecha_solicitud_fabrica'));
        $this->assertNotNull($respuesta->json('data.fecha_estimada_llegada'));
    }

    public function test_marcar_recibido_guarda_la_fecha_de_recepcion(): void
    {
        $ciclo = $this->cicloListo();

        $this->postJson("/api/ciclos/{$ciclo->id}/cerrar")->assertOk();
        $this->postJson("/api/ciclos/{$ciclo->id}/solicitar-fabrica")->assertOk();
        $this->postJson("/api/ciclos/{$ciclo->id}/marcar-transito")->assertOk();

        $respuesta = $this->postJson("/api/ciclos/{$ciclo->id}/marcar-recibido")->assertOk();

        $this->assertNotNull($respuesta->json('data.fecha_recepcion'));
    }

    public function test_una_transicion_fuera_de_orden_es_rechazada(): void
    {
        $ciclo = $this->cicloListo();

        // El ciclo esta 'abierto': no se puede saltar directo a fabrica.
        $this->postJson("/api/ciclos/{$ciclo->id}/solicitar-fabrica")
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->postJson("/api/ciclos/{$ciclo->id}/finalizar")->assertStatus(409);

        // Y cerrar dos veces tampoco.
        $this->postJson("/api/ciclos/{$ciclo->id}/cerrar")->assertOk();
        $this->postJson("/api/ciclos/{$ciclo->id}/cerrar")->assertStatus(409);
    }

    public function test_el_detalle_del_ciclo_trae_fechas_pedidos_y_consolidado(): void
    {
        $ciclo = $this->cicloListo();

        $this->getJson("/api/ciclos/{$ciclo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ciclo->id)
            ->assertJsonStructure([
                'data' => [
                    'nombre', 'estado', 'fecha_apertura', 'fecha_cierre',
                    'fecha_estimada_llegada', 'total_pedidos', 'pedidos', 'consolidado',
                ],
                'message',
            ]);
    }

    public function test_un_ciclo_de_otra_distribuidora_responde_404(): void
    {
        $this->autenticarComoEmpleado();

        $this->getJson('/api/ciclos/999999')->assertStatus(404);
        $this->postJson('/api/ciclos/999999/cerrar')->assertStatus(404);
    }

    public function test_sin_autenticar_no_se_puede_ver_ni_mover_un_ciclo(): void
    {
        $this->getJson('/api/ciclos/vigente')->assertStatus(401);
        $this->getJson('/api/ciclos/1')->assertStatus(401);
        $this->postJson('/api/ciclos/1/cerrar')->assertStatus(401);
    }
}
'@

Write-Host ""
Write-Host "Listo. Ahora corre:" -ForegroundColor Cyan
Write-Host "  php artisan optimize:clear"
Write-Host "  php artisan route:list --path=api/ciclos"
Write-Host "  php artisan test --filter=CicloCompraTest"
Write-Host "  git add . ; git commit -m 'E10: agrega GET /api/ciclos/vigente'"
