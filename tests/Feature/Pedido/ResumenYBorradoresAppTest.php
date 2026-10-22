<?php

namespace Tests\Feature\Pedido;

use App\Models\Pedido;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * El inicio de la app no puede contar solo los primeros 100 pedidos, y una
 * página de "Mis pedidos" no puede quedar vacía porque los borradores se
 * escondían en el teléfono.
 */
class ResumenYBorradoresAppTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_el_resumen_cuenta_mas_de_cien_y_deja_fuera_los_terminados(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $base = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);

        Pedido::withoutGlobalScopes()->create($this->copia($base, 'borrador', 40, 'BORR'));
        Pedido::withoutGlobalScopes()->create($this->copia($base, 'entregado', 70, 'ENTR'));
        Pedido::withoutGlobalScopes()->create($this->copia($base, 'rechazado', 15, 'RECH'));

        for ($i = 0; $i < 101; $i++) {
            Pedido::withoutGlobalScopes()->create($this->copia($base, 'colocado', 10, 'CUR'.$i));
        }

        $this->comoApi(self::MARIA);

        $respuesta = $this->getJson('/api/pedidos/resumen')->assertOk();
        $totalEnviado = (float) $base->total;

        $this->assertSame(102, $respuesta->json('data.en_curso'));
        $this->assertEqualsWithDelta($totalEnviado + 1010, (float) $respuesta->json('data.por_pagar'), 0.01);
        $this->assertCount(3, $respuesta->json('data.ultimos'));
        $this->assertNotContains('borrador', collect($respuesta->json('data.ultimos'))->pluck('estado'));
    }

    public function test_excluir_borrador_deja_la_pagina_completa_y_sin_el_parametro_sigue_el_borrador(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $base = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
        $borrador = Pedido::withoutGlobalScopes()->create($this->copia($base, 'borrador', 20, 'BORR2'));

        $this->comoApi(self::MARIA);

        $conFlag = collect($this->getJson('/api/pedidos?excluir_borrador=1&per_page=25')->assertOk()->json('data'));
        $this->assertFalse($conFlag->contains(fn ($p) => $p['estado'] === 'borrador'));
        $this->assertTrue($conFlag->contains(fn ($p) => (int) $p['id'] === $pedidoId));

        $sinFlag = collect($this->getJson('/api/pedidos?per_page=25')->assertOk()->json('data'));
        $this->assertTrue($sinFlag->contains(fn ($p) => (int) $p['id'] === $borrador->id));
    }

    public function test_el_texto_de_whatsapp_trae_el_enlace_que_abre_el_pedido(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $texto = $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data.compartir_texto');

        $this->assertStringContainsString('https://footwearpoint.app/abrir/pedido/'.$pedidoId, $texto);
    }

    /** @return array<string, mixed> */
    private function copia(Pedido $base, string $estado, float $total, string $folio): array
    {
        return [
            'distribuidora_id'            => $base->distribuidora_id,
            'sucursal_id'                 => $base->sucursal_id,
            'folio'                       => $folio.'-'.$base->id,
            'tipo'                        => $base->tipo,
            'revendedor_distribuidora_id' => $base->revendedor_distribuidora_id,
            'estado'                      => $estado,
            'subtotal'                    => $total,
            'total'                       => $total,
            'fecha_colocacion'            => now(),
        ];
    }
}
