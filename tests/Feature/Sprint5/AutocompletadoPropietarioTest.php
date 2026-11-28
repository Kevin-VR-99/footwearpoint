<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Models\Vale;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * El buscador de cliente o propietario debe mostrar sugerencias al escribir
 * (nombre o teléfono) y unificar la selección en el mismo campo.
 */
class AutocompletadoPropietarioTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comoPanel(self::ADMIN);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_en_vales_unas_letras_o_cifras_abren_sugerencias_y_ya_no_hay_select_aparte(): void
    {
        $cliente = $this->clienteUnico('Valeria Autocompletar Vales', '5588710001');
        $mayorista = $this->mayoristaUnico('Hugo Autocompletar Vales', '5588710002');

        $directo = Livewire::test('vales.index')
            ->assertDontSee('— Seleccionar cliente —', false)
            ->set('busquedaCliente', 'valer');
        $this->assertTrue($directo->instance()->clientesFiltrados->pluck('id')->contains($cliente->id));
        $directo->assertSee('data-sugerencias-cliente', false)
            ->assertSee('Valeria Autocompletar Vales')
            ->assertSee('5588710001');

        $porTelefono = Livewire::test('vales.index')->set('busquedaCliente', '8871 0001');
        $this->assertEquals([$cliente->id], $porTelefono->instance()->clientesFiltrados->pluck('id')->all());
        $porTelefono->assertSee('data-sugerencias-cliente', false);

        $mayor = Livewire::test('vales.index')
            ->set('propietario_tipo', 'revendedor')
            ->assertDontSee('— Seleccionar cliente mayorista —', false)
            ->set('busquedaMayorista', 'hugo auto');
        $this->assertTrue($mayor->instance()->revendedoresFiltrados->pluck('id')->contains($mayorista->id));
        $mayor->assertSee('data-sugerencias-cliente', false)
            ->assertSee('Hugo Autocompletar Vales');

        $mayorTel = Livewire::test('vales.index')
            ->set('propietario_tipo', 'revendedor')
            ->set('busquedaMayorista', '88710002');
        $this->assertEquals([$mayorista->id], $mayorTel->instance()->revendedoresFiltrados->pluck('id')->all());
    }

    public function test_en_vales_elegir_una_sugerencia_deja_el_cliente_y_permite_emitir(): void
    {
        $cliente = $this->clienteUnico('Selena Emision Vales', '5588710003');

        $pantalla = Livewire::test('vales.index')
            ->set('busquedaCliente', 'selena emision')
            ->call('seleccionarPropietario', $cliente->id)
            ->assertSet('propietario_id', (string) $cliente->id)
            ->assertSet('busquedaCliente', '')
            ->assertSee('data-cliente-elegido', false)
            ->assertSee('Selena Emision Vales')
            ->assertDontSee('data-sugerencias-cliente', false)
            ->set('monto_original', '250.50')
            ->set('motivo', 'Prueba de emisión con autocompletado')
            ->call('emitir')
            ->assertSet('errorMsg', '')
            ->assertSee('emitido correctamente');

        $vale = Vale::query()->latest('id')->firstOrFail();
        $this->assertSame($cliente->id, $vale->cliente_directo_id);
        $this->assertEquals(250.50, (float) $vale->monto_original);
    }

    public function test_en_vales_cambiar_el_tipo_limpia_la_seleccion_y_el_listado_filtra_por_telefono(): void
    {
        $cliente = $this->clienteUnico('Lidia Listado Vales', '5588710004');

        Livewire::test('vales.index')
            ->set('busquedaCliente', 'lidia')
            ->call('seleccionarPropietario', $cliente->id)
            ->set('propietario_tipo', 'revendedor')
            ->assertSet('propietario_id', '')
            ->assertSet('busquedaCliente', '')
            ->assertSet('busquedaMayorista', '')
            ->assertDontSee('data-cliente-elegido', false);

        Livewire::test('vales.index')
            ->set('busquedaCliente', 'lidia')
            ->call('seleccionarPropietario', $cliente->id)
            ->set('monto_original', '100')
            ->call('emitir')
            ->assertSet('errorMsg', '');

        $listado = Livewire::test('vales.index')->set('busqueda', '8871 0004');
        $this->assertTrue($listado->instance()->vales->contains(fn ($vale) => $vale->cliente_directo_id === $cliente->id));
        $listado->assertSee('Lidia Listado Vales');

        $sinCoincidencia = Livewire::test('vales.index')->set('busqueda', '0000000000');
        $this->assertCount(0, $sinCoincidencia->instance()->vales);
    }

    public function test_en_nuevo_pedido_el_autocompletado_respeta_el_tipo_y_crea_el_borrador(): void
    {
        $cliente = $this->clienteUnico('Patricia Pedido Auto', '5588710005');
        $mayorista = $this->mayoristaUnico('Bruno Pedido Auto', '5588710006');

        $pantalla = Livewire::test('pedidos.create')
            ->assertSee('Buscar propietario del pedido')
            ->assertDontSee('id="create-propietario-id"', false)
            ->set('busquedaPropietario', 'patri')
            ->assertSee('data-sugerencias-cliente', false)
            ->assertSee('Patricia Pedido Auto')
            ->call('seleccionarPropietario', $cliente->id)
            ->assertSet('propietario_id', (string) $cliente->id)
            ->assertSee('data-cliente-elegido', false)
            ->call('crearBorrador')
            ->assertSet('errorMsg', '');

        $this->assertNotNull($pantalla->instance()->pedidoId);
        $this->assertSame($cliente->id, \App\Models\Pedido::findOrFail($pantalla->instance()->pedidoId)->cliente_directo_id);

        $soloTipo = Livewire::test('pedidos.create')
            ->set('tipo', 'revendedor')
            ->set('busquedaPropietario', 'patricia pedido');
        $this->assertCount(0, $soloTipo->instance()->propietariosFiltrados);
        $soloTipo->assertSee('No hay propietarios que coincidan con la búsqueda.');

        $porMayorista = Livewire::test('pedidos.create')
            ->set('tipo', 'revendedor')
            ->set('busquedaPropietario', '88710006')
            ->call('seleccionarPropietario', $mayorista->id)
            ->assertSet('propietario_id', (string) $mayorista->id)
            ->assertSee('Bruno Pedido Auto');
    }

    public function test_en_venta_local_el_buscador_sugiere_y_permite_elegir_o_quitar(): void
    {
        $cliente = $this->clienteUnico('Nuria Venta Auto', '5588710007');
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon);

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('¿Primera compra?')
            ->assertDontSee('— Venta de mostrador —', false)
            ->set('busquedaCliente', 'nuria ven')
            ->assertSee('data-sugerencias-cliente', false)
            ->assertSee('Nuria Venta Auto')
            ->call('seleccionarClienteDeBusqueda', $cliente->id)
            ->assertSet('cliente_directo_id', (string) $cliente->id)
            ->assertSee('data-cliente-elegido', false)
            ->call('quitarClienteDeBusqueda')
            ->assertSet('cliente_directo_id', '')
            ->assertDontSee('data-cliente-elegido', false);
    }

    public function test_en_cambios_el_alta_de_cliente_de_una_venta_usa_el_mismo_autocompletado(): void
    {
        $this->assertStringContainsString('cliente.selector-alta', $this->contenidoVolt('cambios'));
        $this->assertStringContainsString('asignarCliente', $this->contenidoVolt('cambios'));
        $this->assertStringContainsString('x-cliente.autocompletado', file_get_contents(resource_path('views/cliente/selector-alta.blade.php')));
    }

    public function test_los_listados_de_pedidos_ventas_y_anticipos_filtran_por_telefono_parcial(): void
    {
        $this->assertStringContainsString('BusquedaDeCliente::acotar', file_get_contents(app_path('Services/Exportar/ExportarPedidos.php')));
        $this->assertStringContainsString('BusquedaDeCliente::acotar', $this->contenidoVolt('ventas'));
        $this->assertStringContainsString('BusquedaDeCliente::acotar', $this->contenidoVolt('compras'));
    }

    private function clienteUnico(string $nombre, string $telefono): ClienteDirecto
    {
        return ClienteDirecto::create([
            'distribuidora_id' => Tenant::id(),
            'nombre' => $nombre,
            'telefono' => $telefono,
            'estado' => 'activo',
        ]);
    }

    private function mayoristaUnico(string $nombre, string $telefono): RevendedorDistribuidora
    {
        $revendedor = Revendedor::create([
            'nombre' => $nombre,
            'telefono' => $telefono,
            'estado' => 'activo',
        ]);

        return RevendedorDistribuidora::create([
            'distribuidora_id' => Tenant::id(),
            'revendedor_id' => $revendedor->id,
            'codigo_interno' => 'AUTO-'.substr($telefono, -4),
            'estado' => 'activo',
            'fecha_alta' => now(),
        ]);
    }

    private function rutaVolt(string $carpetaArchivo): string
    {
        $directorio = resource_path('views/components/'.$carpetaArchivo);
        if (is_dir($directorio)) {
            foreach (scandir($directorio) as $nombre) {
                if (str_ends_with($nombre, '.blade.php') && (str_contains($nombre, 'index') || str_contains($nombre, 'anticipos'))) {
                    return $directorio.DIRECTORY_SEPARATOR.$nombre;
                }
            }
        }

        $this->fail('No se encontró la vista Volt de '.$carpetaArchivo);
    }

    private function contenidoVolt(string $carpeta): string
    {
        return (string) file_get_contents($this->rutaVolt($carpeta));
    }
}
