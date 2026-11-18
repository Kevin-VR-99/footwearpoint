<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Sucursal;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** S5-60: Anticipos en Compras reutiliza la configuración y el anticipo pendiente del pedido. */
class AnticiposComprasTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_admin_guarda_anticipo_y_plazos_sin_tocar_vale_ni_stock(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->comoPanel(self::ADMIN);
        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $vale = (int) $config->dias_vigencia_vale;
        $stock = (int) $config->stock_minimo_general;

        Livewire::test('compras.anticipos')
            ->set('anticipo_por_producto', '175.50')
            ->set('dias_maximos_recoleccion', 8)
            ->set('dias_solicitud_cambio', 9)
            ->set('dias_gestion_devolucion', 11)
            ->call('guardar')
            ->assertHasNoErrors();

        $config->refresh();
        $this->assertSame('175.50', $config->getRawOriginal('anticipo_por_producto'));
        $this->assertSame(8, (int) $config->dias_maximos_recoleccion);
        $this->assertSame(9, (int) $config->dias_solicitud_cambio);
        $this->assertSame(11, (int) $config->dias_gestion_devolucion);
        $this->assertSame($vale, (int) $config->dias_vigencia_vale);
        $this->assertSame($stock, (int) $config->stock_minimo_general);
    }

    public function test_lista_solo_anticipos_pendientes_de_cliente_directo_de_esta_casa(): void
    {
        $this->comoPanel(self::ADMIN);
        $propio = $this->pedido('cliente_directo', 'confirmado', 80);
        $pagado = $this->pedido('cliente_directo', 'confirmado', 0);
        $mayorista = $this->pedido('revendedor', 'confirmado', 80);
        $borrador = $this->pedido('cliente_directo', 'borrador', 80);

        $html = Livewire::test('compras.anticipos')->html();
        $this->assertStringContainsString('data-anticipo-pedido="'.$propio.'"', $html);
        $this->assertStringNotContainsString('data-anticipo-pedido="'.$pagado.'"', $html);
        $this->assertStringNotContainsString('data-anticipo-pedido="'.$mayorista.'"', $html);
        $this->assertStringNotContainsString('data-anticipo-pedido="'.$borrador.'"', $html);

        $this->comoPanel(self::OTRA_ADMIN);
        $this->assertStringNotContainsString('data-anticipo-pedido="'.$propio.'"', Livewire::test('compras.anticipos')->html());
    }

    public function test_el_empleado_ve_la_lista_y_no_guarda_la_configuracion(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $pedido = $this->pedido('cliente_directo', 'confirmado', 40);
        $antes = ConfiguracionDistribuidora::query()->firstOrFail()->getRawOriginal('anticipo_por_producto');

        Livewire::test('compras.anticipos')
            ->assertSee('data-anticipo-pedido="'.$pedido.'"', false)
            ->assertDontSee('data-anticipos-config', false)
            ->set('anticipo_por_producto', '999')
            ->call('guardar')
            ->assertForbidden();

        $this->assertSame($antes, ConfiguracionDistribuidora::query()->firstOrFail()->getRawOriginal('anticipo_por_producto'));
    }

    public function test_buscador_filtra_por_folio_o_cliente(): void
    {
        $this->comoPanel(self::ADMIN);

        $clienteA = ClienteDirecto::create([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'nombre' => 'Cliente Buscador Uno',
            'telefono' => '5511223344',
            'estado' => 'activo',
        ]);
        $clienteB = ClienteDirecto::create([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'nombre' => 'Cliente Buscador Dos',
            'telefono' => '5566778899',
            'estado' => 'activo',
        ]);

        $pedido1 = $this->crearPedidoConCliente($clienteA->id, 'FOL-ANT-001', 50);
        $pedido2 = $this->crearPedidoConCliente($clienteB->id, 'FOL-ANT-002', 50);

        Livewire::test('compras.anticipos')
            ->assertSee('FOL-ANT-001')
            ->assertSee('FOL-ANT-002')
            ->set('busqueda', 'FOL-ANT-001')
            ->assertSee('FOL-ANT-001')
            ->assertDontSee('FOL-ANT-002')
            ->set('busqueda', 'Buscador Dos')
            ->assertSee('FOL-ANT-002')
            ->assertDontSee('FOL-ANT-001')
            ->set('busqueda', 'Inexistente XYZ')
            ->assertSee('No se encontraron pedidos con anticipo pendiente');
    }

    public function test_buscador_escapa_caracteres_especiales_like(): void
    {
        $this->comoPanel(self::ADMIN);

        $cliente = ClienteDirecto::create([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'nombre' => 'Cliente_Especial%Prueba',
            'telefono' => '5500112233',
            'estado' => 'activo',
        ]);

        $this->crearPedidoConCliente($cliente->id, 'FOL_100%ESP', 60);

        Livewire::test('compras.anticipos')
            ->set('busqueda', 'FOL_100%ESP')
            ->assertSee('FOL_100%ESP')
            ->set('busqueda', 'Especial%Prueba')
            ->assertSee('Cliente_Especial%Prueba')
            ->set('busqueda', 'FOL_999')
            ->assertSee('No se encontraron pedidos con anticipo pendiente');
    }

    private function crearPedidoConCliente(int $clienteId, string $folio, float $anticipo): int
    {
        $pedido = Pedido::create([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => $folio,
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => $clienteId,
            'estado' => 'confirmado',
            'subtotal' => 200,
            'total' => 200,
            'capturado_por_staff_id' => DistribuidoraStaff::query()->value('id'),
        ]);
        $pc = ProductoCampana::query()->firstOrFail();
        PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'pedido_id' => $pedido->id,
            'producto_campana_id' => $pc->id,
            'variante_id' => Variante::query()->value('id'),
            'producto_nombre' => 'Par',
            'modelo' => 'M',
            'talla' => '25',
            'color' => 'Negro',
            'cantidad' => 1,
            'precio_unitario' => 200,
            'subtotal' => 200,
            'anticipo_requerido' => $anticipo,
            'estado_surtido' => 'pendiente',
        ]);

        return (int) $pedido->id;
    }

    private function pedido(string $tipo, string $estado, float $anticipo): int
    {
        $cliente = $tipo === 'cliente_directo' ? ClienteDirecto::query()->value('id') : null;
        $pedido = Pedido::create([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'ANT-'.uniqid(),
            'tipo' => $tipo,
            'cliente_directo_id' => $cliente,
            'estado' => $estado,
            'subtotal' => 200,
            'total' => 200,
            'capturado_por_staff_id' => DistribuidoraStaff::query()->value('id'),
        ]);
        $pc = ProductoCampana::query()->firstOrFail();
        PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => \App\Support\Tenant::id(),
            'pedido_id' => $pedido->id,
            'producto_campana_id' => $pc->id,
            'variante_id' => Variante::query()->value('id'),
            'producto_nombre' => 'Par',
            'modelo' => 'M',
            'talla' => '25',
            'color' => 'Negro',
            'cantidad' => 1,
            'precio_unitario' => 200,
            'subtotal' => 200,
            'anticipo_requerido' => $anticipo,
            'estado_surtido' => 'pendiente',
        ]);

        return (int) $pedido->id;
    }
}
