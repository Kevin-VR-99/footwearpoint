<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Auditoría 21-oct-2026: pedidos y vales paginados en web y API, sin el tope de 100. */
class PaginacionListadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function crearPedidos(int $cuantos): void
    {
        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        $base = [
            'distribuidora_id'       => Tenant::id(),
            'sucursal_id'            => Sucursal::query()->value('id'),
            'tipo'                   => 'cliente_directo',
            'cliente_directo_id'     => ClienteDirecto::query()->value('id'),
            'estado'                 => 'colocado',
            'subtotal'               => 100,
            'total'                  => 100,
            'fecha_colocacion'       => now(),
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ];

        for ($i = 1; $i <= $cuantos; $i++) {
            Pedido::create($base + ['folio' => sprintf('PAG-%04d', $i)]);
        }
    }

    public function test_la_api_de_pedidos_pagina_y_conserva_data(): void
    {
        $this->crearPedidos(130);
        $total = Pedido::count();
        Sanctum::actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());

        // Sin parámetros: como antes, los 100 más nuevos en "data".
        $primera = $this->getJson('/api/pedidos')->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', $total)
            ->assertJsonPath('meta.last_page', (int) ceil($total / 100))
            ->assertJsonPath('links.prev', null);
        $this->assertStringContainsString('page=2', (string) $primera->json('links.next'));

        // Los más viejos ya se pueden alcanzar.
        $segunda = $this->getJson('/api/pedidos?page=2')->assertOk();
        $this->assertCount($total - 100, $segunda->json('data'));
        $this->assertContains('PAG-0001', array_column($segunda->json('data'), 'folio'));

        // per_page se respeta y no pasa de 100.
        $this->getJson('/api/pedidos?per_page=10&page=3')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 3);
        $this->getJson('/api/pedidos?per_page=5000')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);

        // Los filtros se mantienen en los enlaces.
        $this->assertStringContainsString('estado=colocado', (string) $this->getJson('/api/pedidos?estado=colocado&per_page=10')->json('links.next'));
    }

    public function test_la_api_de_vales_trae_meta_y_links(): void
    {
        Sanctum::actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());

        $this->getJson('/api/vales?per_page=2')->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links' => ['next', 'prev']])
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_la_pantalla_de_pedidos_pagina_de_25_en_25(): void
    {
        $this->crearPedidos(60);

        $componente = Livewire::test('pedidos.index');
        $this->assertCount(25, $componente->instance()->pedidos->items());
        $this->assertSame(Pedido::count(), $componente->instance()->pedidos->total());

        $componente->call('gotoPage', $componente->instance()->pedidos->lastPage())
            ->assertSee('PAG-0001');

        // Al filtrar se regresa a la página 1.
        $componente->set('filtro_estado', 'colocado');
        $this->assertSame(1, $componente->instance()->pedidos->currentPage());
    }

    public function test_vales_y_stock_ya_no_traen_todo(): void
    {
        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());

        $this->assertSame(25, Livewire::test('vales.index')->instance()->vales->perPage());
        $this->assertSame(25, Livewire::test('stock.index')->instance()->grupos->perPage());
    }
}
