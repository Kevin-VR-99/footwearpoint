<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\SolicitudCambio;
use App\Models\SolicitudCambioHistorial;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\VentaDirecta;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TG354BuscarPedidoCambiosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->como('admin@calzadosramirez.test');
    }

    private function como(string $email): void
    {
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function pedido(string $folio = 'PED-TG354-0001'): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio, 'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::value('id'),
            'estado' => 'borrador', 'subtotal' => 0, 'total' => 0,
            'capturado_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);
    }

    private function venta(string $folio = 'VD-TG354-0001'): VentaDirecta
    {
        return VentaDirecta::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio, 'cliente_directo_id' => ClienteDirecto::value('id'),
            'subtotal' => 0, 'total' => 0, 'registrada_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);
    }

    private function datos(): array
    {
        $modelos = [Pedido::class, VentaDirecta::class, SolicitudCambio::class,
            SolicitudCambioHistorial::class, Vale::class,
            ValeMovimiento::class, Pago::class,
            StockLocal::class, MovimientoStock::class];
        $datos = [];
        foreach ($modelos as $modelo) {
            $tabla = (new $modelo)->getTable();
            $datos[$tabla] = DB::table($tabla)->get()->map(fn ($fila) => json_encode($fila))->sort()->values()->all();
        }

        return $datos;
    }

    public function test_busca_folio_completo_recortado_y_enlaza_detalle(): void
    {
        $pedido = $this->pedido();
        Livewire::test('cambios.index')->set('folioPedido', '  '.$pedido->folio.'  ')->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', $pedido->id)->assertSet('errorBusquedaPedido', '')
            ->assertSee('Pedido '.$pedido->folio)
            ->assertSee(route('pedidos.show', $pedido->id), false)
            ->assertSee('Ver detalle del pedido');
    }

    public function test_vacio_y_no_encontrado_limpian_resultado_anterior(): void
    {
        $pedido = $this->pedido();
        $pantalla = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido');
        $pantalla->set('folioPedido', '   ')->call('buscarPedido')->assertSet('pedidoEncontradoId', null)
            ->assertSet('errorBusquedaPedido', 'Escribe el folio del pedido que quieres buscar.')
            ->assertDontSee('Ver detalle del pedido');
        $pantalla->set('folioPedido', $pedido->folio)->call('buscarPedido');
        $pantalla->set('folioPedido', 'NO-EXISTE-TG354')->call('buscarPedido')->assertSet('pedidoEncontradoId', null)
            ->assertSet('errorBusquedaPedido', 'No se encontró un pedido con ese folio.')
            ->assertDontSee('Ver detalle del pedido');
    }

    public function test_no_busca_por_fragmento_ni_comodin(): void
    {
        $pedido = $this->pedido();
        foreach (['PED-TG354', '%', $pedido->folio.'X'] as $folio) {
            Livewire::test('cambios.index')->set('folioPedido', $folio)->call('buscarPedido')
                ->assertSet('pedidoEncontradoId', null)
                ->assertSet('errorBusquedaPedido', 'No se encontró un pedido con ese folio.');
        }
    }

    public function test_pedido_ajeno_es_indistinguible_de_uno_inexistente(): void
    {
        $pedido = $this->pedido();
        $this->como('admin@boutiquedelcalzado.test');
        $pantalla = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', null)->assertDontSee('Ver detalle del pedido');
        $errorAjeno = $pantalla->instance()->errorBusquedaPedido;
        $pantalla->set('folioPedido', 'NO-EXISTE-TG354')->call('buscarPedido')->assertSet('pedidoEncontradoId', null);
        $this->assertSame($errorAjeno, $pantalla->instance()->errorBusquedaPedido);
        $this->assertSame('No se encontró un pedido con ese folio.', $errorAjeno);
    }

    public function test_busqueda_de_pedido_no_sustituye_ni_modifica_busqueda_de_venta(): void
    {
        $venta = $this->venta();
        $pedido = $this->pedido();
        Livewire::test('cambios.index')->set('folioVenta', $venta->folio)->call('buscarVenta')
            ->assertSet('ventaId', $venta->id)
            ->set('folioPedido', $venta->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', null)->assertSet('ventaId', $venta->id)
            ->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', $pedido->id)->assertSet('ventaId', $venta->id)
            ->assertSet('folioVenta', $venta->folio);
    }

    public function test_folios_iguales_en_pedido_y_venta_no_confunden_los_resultados(): void
    {
        $pedido = $this->pedido('FOLIO-COMPARTIDO-TG354');
        $venta = $this->venta($pedido->folio);
        Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', $pedido->id)
            ->set('folioVenta', $venta->folio)->call('buscarVenta')
            ->assertSet('ventaId', $venta->id)->assertSet('pedidoEncontradoId', $pedido->id);
    }

    public function test_buscar_no_modifica_pedidos_cambios_vales_pagos_ni_inventario(): void
    {
        $pedido = $this->pedido();
        $antes = $this->datos();
        Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->set('folioPedido', 'NO-EXISTE-TG354')->call('buscarPedido')
            ->set('folioPedido', '')->call('buscarPedido');
        $this->assertSame($antes, $this->datos());
    }

    public function test_ruta_admite_personal_y_rechaza_cliente_autenticado(): void
    {
        $this->get(route('cambios.index'))->assertOk()->assertSee('Buscar pedido por folio');
        $this->como('empleado@calzadosramirez.test');
        $this->get(route('cambios.index'))->assertOk();
        $this->como('maria.lopez@revendedor.test');
        $this->get(route('cambios.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_ruta_sin_sesion_redirige_al_login(): void
    {
        Auth::logout();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->get(route('cambios.index'))->assertRedirect(route('login'));
    }
}
