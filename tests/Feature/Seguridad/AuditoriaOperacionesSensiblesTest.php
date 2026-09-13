<?php

namespace Tests\Feature\Seguridad;

use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\CambiarEstadoPedidoService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditoriaOperacionesSensiblesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_action_escribe_una_fila_de_auditoria(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $fila = app(RegistrarAuditoriaAction::class)->ejecutar(
            'prueba.manual',
            'pedido',
            6,
            ['estado' => 'colocado'],
            ['estado' => 'listo_entrega']
        );

        $this->assertNotNull($fila->id);
        $this->assertDatabaseHas('auditorias', [
            'id'           => $fila->id,
            'accion'       => 'prueba.manual',
            'entidad_tipo' => 'pedido',
            'entidad_id'   => 6,
            'usuario_id'   => $usuario->id,
        ]);
    }

    public function test_cambiar_estado_deja_rastro_en_auditorias(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();

        $pedido = Pedido::withoutGlobalScopes()->first();

        if (! $pedido) {
            $sucursal = Sucursal::query()->firstOrFail();
            $pedido = Pedido::create([
                'sucursal_id'            => $sucursal->id,
                'folio'                  => 'PED-AUD-0001',
                'tipo'                   => 'cliente_directo',
                'estado'                 => 'colocado',
                'subtotal'               => 100,
                'total'                  => 100,
                'capturado_por_staff_id' => null,
            ]);
        }

        $despues = $pedido->estado === 'borrador' ? 'colocado' : 'borrador';

        app(CambiarEstadoPedidoService::class)->cambiar(
            $pedido,
            $despues,
            null,
            'Prueba E17-03 TG-63'
        );

        $this->assertDatabaseHas('auditorias', [
            'accion'       => 'pedido.cambio_estado',
            'entidad_tipo' => 'pedido',
            'entidad_id'   => $pedido->id,
        ]);
    }
}