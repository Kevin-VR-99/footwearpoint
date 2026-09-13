<?php

namespace Tests\Feature\Seguridad;

use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AislamientoPedidosMultiTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarEmpleadoA(): void
    {
        Tenant::olvidarCache();
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
    }

    private function pedidoDeOtraDistribuidora(): Pedido
    {
        $distribuidoraB = Distribuidora::create([
            'nombre_comercial' => 'Rival Pedidos',
            'razon_social'     => 'Rival Pedidos S.A. de C.V.',
            'rfc'              => 'RPD010101XXX',
            'slug'             => 'rival-pedidos-' . uniqid(),
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);

        return Tenant::forzar($distribuidoraB->id, function () use ($distribuidoraB) {
            $sucursal = Sucursal::create([
                'nombre'       => 'Sucursal Rival',
                'direccion'    => 'Calle Rival 1, CDMX',
                'es_principal' => true,
                'activa'       => true,
            ]);

            return Pedido::create([
                'distribuidora_id'       => $distribuidoraB->id,
                'sucursal_id'            => $sucursal->id,
                'folio'                  => 'PED-RIVAL-0001',
                'tipo'                   => 'cliente_directo',
                'estado'                 => 'colocado',
                'subtotal'               => 100,
                'total'                  => 100,
                'capturado_por_staff_id' => null,
            ]);
        });
    }

    public function test_no_se_puede_leer_un_pedido_de_otra_distribuidora(): void
    {
        $pedidoAjeno = $this->pedidoDeOtraDistribuidora();
        $this->autenticarEmpleadoA();

        $this->getJson('/api/pedidos/' . $pedidoAjeno->id)->assertStatus(404);
    }

    public function test_no_se_puede_registrar_pago_en_un_pedido_de_otra_distribuidora(): void
    {
        $pedidoAjeno = $this->pedidoDeOtraDistribuidora();
        $this->autenticarEmpleadoA();

        $this->postJson('/api/pedidos/' . $pedidoAjeno->id . '/pagos', [
            'tipo'   => 'anticipo',
            'metodo' => 'efectivo',
            'monto'  => 10,
        ])->assertStatus(404);
    }

    public function test_el_listado_de_pedidos_no_mezcla_otra_distribuidora(): void
    {
        $pedidoAjeno = $this->pedidoDeOtraDistribuidora();
        $this->autenticarEmpleadoA();

        $ids = collect($this->getJson('/api/pedidos')->assertOk()->json('data'))->pluck('id');

        $this->assertNotContains($pedidoAjeno->id, $ids);
    }
}
