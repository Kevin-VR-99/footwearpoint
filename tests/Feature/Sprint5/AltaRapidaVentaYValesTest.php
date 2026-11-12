<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** S5-58 / Taiga 359: buscador y alta rápida en Venta local y en Vales. */
class AltaRapidaVentaYValesTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_la_venta_busca_por_nombre_o_telefono_y_el_repetido_no_crea_otro(): void
    {
        $this->comoPanel(self::ADMIN);
        $existente = ClienteDirecto::query()->whereNotNull('telefono')->firstOrFail();
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();

        $porNombre = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('¿Primera compra?')
            ->set('busquedaCliente', $existente->nombre);
        $this->assertTrue($porNombre->instance()->clientesFiltrados->pluck('id')->contains($existente->id));

        $porTelefono = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('busquedaCliente', substr($existente->telefono, 0, 3).' '.substr($existente->telefono, 3));
        $this->assertEquals([$existente->id], $porTelefono->instance()->clientesFiltrados->pluck('id')->all());

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('primeraCompra', 'si')
            ->set('altaNombre', 'Mostrador nuevo')
            ->set('altaTelefono', $existente->telefono)
            ->call('crearAltaRapida')
            ->assertSet('cliente_directo_id', (string) $existente->id)
            ->assertSet('primeraCompra', 'no')
            ->assertSee('Se usará a '.$existente->nombre);

        $this->assertSame(1, ClienteDirecto::query()->where('telefono', $existente->telefono)->count());

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('primeraCompra', 'si')
            ->set('altaNombre', 'Cliente de venta')
            ->set('altaTelefono', '55 8888-0001')
            ->call('crearAltaRapida')
            ->assertSet('errorMsg', '');

        $nuevo = ClienteDirecto::query()->where('nombre', 'Cliente de venta')->firstOrFail();
        $this->assertSame('5588880001', $nuevo->telefono);
    }

    public function test_el_vale_busca_y_da_de_alta_igual_y_el_mayorista_solo_se_busca(): void
    {
        $this->comoPanel(self::ADMIN);
        $cliente = ClienteDirecto::query()->whereNotNull('telefono')->firstOrFail();
        $mayorista = RevendedorDistribuidora::query()->with('revendedor')->firstOrFail();

        $pantalla = Livewire::test('vales.index')
            ->assertSee('¿Primera compra?')
            ->set('busquedaCliente', $cliente->telefono);
        $this->assertTrue($pantalla->instance()->clientesFiltrados->pluck('id')->contains($cliente->id));

        Livewire::test('vales.index')
            ->set('primeraCompra', 'si')
            ->set('altaNombre', '')
            ->set('altaTelefono', '')
            ->call('crearAltaRapida')
            ->assertHasErrors(['altaNombre', 'altaTelefono']);

        Livewire::test('vales.index')
            ->set('primeraCompra', 'si')
            ->set('altaNombre', 'Otro vale')
            ->set('altaTelefono', '+52 '.$cliente->telefono)
            ->call('crearAltaRapida')
            ->assertSet('propietario_id', (string) $cliente->id)
            ->assertSee('Se usará a '.$cliente->nombre);

        $this->assertSame(1, ClienteDirecto::query()->where('telefono', $cliente->telefono)->count());

        $busqueda = Livewire::test('vales.index')
            ->set('propietario_tipo', 'revendedor')
            ->assertDontSee('data-alta-rapida', false)
            ->set('busquedaMayorista', $mayorista->revendedor->nombre);
        $this->assertTrue($busqueda->instance()->revendedoresFiltrados->pluck('id')->contains($mayorista->id));
    }
}
