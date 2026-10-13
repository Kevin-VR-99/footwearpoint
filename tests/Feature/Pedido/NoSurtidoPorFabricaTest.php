<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Pedido\MarcarNoSurtidoAction;
use App\Services\Pedido\ResolverNoSurtidoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * TG-241 (A12 / E8-05) — La fábrica confirmó un producto y no lo surtió.
 *
 * Lo delicado de esta tarjeta es el dinero: según el backlog, es la ÚNICA
 * situación de todo el sistema donde sale efectivo (en lo demás se da un
 * vale), porque la persona nunca llegó a recibir el producto.
 */
class NoSurtidoPorFabricaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Un pedido de José enviado y con su anticipo pagado en efectivo. */
    private function pedidoConAnticipo(float $anticipo = 200.0): Pedido
    {
        $this->como(self::JOSE);

        $fichaId = (int) ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->value('id');

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => $fichaId,
            'sucursal_id'    => Sucursal::withoutGlobalScopes()->where('es_principal', true)->orderBy('id')->value('id'),
        ])->assertCreated()->json('data.id');

        $variantes = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('activo', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->take(2)
            ->get();

        foreach ($variantes as $v) {
            $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
                'producto_campana_id' => $v->producto_campana_id,
                'variante_id'         => $v->variante_id,
                'cantidad'            => 1,
            ])->assertCreated();
        }

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        // El personal cobra el anticipo en mostrador.
        $this->como(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => 'anticipo',
            'metodo' => 'efectivo',
            'monto'  => $anticipo,
        ])->assertCreated();

        $pedido = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
        // La fábrica ya confirmó: es cuando puede pasar que al final no surta.
        $pedido->update(['estado' => 'solicitado_fabrica']);

        return $pedido->fresh();
    }

    private function primeraLinea(Pedido $pedido): PedidoDetalle
    {
        return $pedido->detalle()->orderBy('id')->firstOrFail();
    }

    private function marcar(PedidoDetalle $linea, string $motivo = 'La fábrica canceló el color'): Pedido
    {
        $this->como(self::EMPLEADO);

        return app(MarcarNoSurtidoAction::class)->ejecutar($linea, $motivo);
    }

    // ------------------------------------------------------------------
    // Marcar
    // ------------------------------------------------------------------

    public function test_marcar_un_renglon_deja_el_motivo_y_la_decision_pendiente(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->marcar($linea);

        $linea->refresh();
        $this->assertSame('no_surtido', $linea->estado_surtido);
        $this->assertSame('La fábrica canceló el color', $linea->motivo_no_surtido);
        $this->assertSame('pendiente', $linea->resolucion_no_surtido);
    }

    public function test_si_algo_si_llego_el_pedido_sigue_su_curso(): void
    {
        // Casi siempre falta una talla, no el pedido completo.
        $pedido = $this->pedidoConAnticipo();

        $this->marcar($this->primeraLinea($pedido));

        $this->assertSame('solicitado_fabrica', $pedido->fresh()->estado);
    }

    public function test_si_no_queda_nada_que_entregar_el_pedido_se_cierra(): void
    {
        $pedido = $this->pedidoConAnticipo();

        foreach ($pedido->detalle as $linea) {
            $this->marcar($linea);
        }

        $this->assertSame('no_surtido', $pedido->fresh()->estado);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $pedido = $this->pedidoConAnticipo();

        $this->expectException(ValidationException::class);

        $this->marcar($this->primeraLinea($pedido), '   ');
    }

    public function test_no_se_puede_marcar_dos_veces(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->marcar($linea);

        $this->expectException(ValidationException::class);
        $this->marcar($linea->fresh());
    }

    public function test_no_se_puede_marcar_antes_de_que_la_fabrica_confirme(): void
    {
        // Todavía no hay nada que surtir.
        $pedido = $this->pedidoConAnticipo();
        $pedido->update(['estado' => 'colocado']);

        $this->expectException(ValidationException::class);

        $this->marcar($this->primeraLinea($pedido->fresh()));
    }

    public function test_al_cliente_le_llega_el_aviso_con_el_motivo(): void
    {
        // El criterio lo pide: "el cliente recibe una notificación".
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->marcar($linea, 'Se agotó la piel');

        $aviso = Notificacion::withoutGlobalScopes()
            ->where('tipo', 'pedido_no_surtido')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((int) Usuario::where('email', self::JOSE)->value('id'), (int) $aviso->usuario_id);
        $this->assertStringContainsString($linea->producto_nombre, $aviso->mensaje);
        $this->assertStringContainsString('Se agotó la piel', $aviso->mensaje);
        // Y le dice que tiene que elegir.
        $this->assertStringContainsString('cambiemos', $aviso->mensaje);
        $this->assertStringContainsString('anticipo', $aviso->mensaje);
    }

    // ------------------------------------------------------------------
    // La decisión del cliente
    // ------------------------------------------------------------------

    public function test_el_cliente_elige_desde_la_app_que_se_lo_cambien(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'sustitucion',
        ])->assertOk();

        $this->assertSame('sustitucion', $linea->fresh()->resolucion_no_surtido);
    }

    public function test_el_cliente_elige_desde_la_app_que_le_devuelvan_el_anticipo(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'reembolso_anticipo',
        ])->assertOk();

        $this->assertSame('reembolso_anticipo', $linea->fresh()->resolucion_no_surtido);

        // Elegir NO mueve dinero: el efectivo lo entrega el personal.
        $this->assertSame(0, Pago::withoutGlobalScopes()->where('direccion', 'salida')->count());
    }

    /**
     * Sin estos tres campos la app no puede decir que algo no llegó: no sabe
     * cuál renglón fue, ni por qué, ni si todavía falta que la persona elija.
     * La notificación la manda a esta pantalla, así que si el detalle no los
     * trae, la decisión nunca se puede tomar.
     */
    public function test_el_detalle_del_pedido_le_dice_a_la_app_que_no_se_surtio(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea, 'La fábrica ya no tiene esa talla');

        $this->como(self::JOSE);

        $this->getJson("/api/pedidos/{$pedido->id}")
            ->assertOk()
            ->assertJsonPath('data.lineas.0.estado_surtido', 'no_surtido')
            ->assertJsonPath('data.lineas.0.motivo_no_surtido', 'La fábrica ya no tiene esa talla')
            ->assertJsonPath('data.lineas.0.resolucion_no_surtido', 'pendiente');
    }

    public function test_el_detalle_del_pedido_le_dice_a_la_app_que_ya_se_eligio(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'reembolso_anticipo',
        ])->assertOk();

        $this->getJson("/api/pedidos/{$pedido->id}")
            ->assertOk()
            ->assertJsonPath('data.lineas.0.resolucion_no_surtido', 'reembolso_anticipo');
    }

    public function test_desde_la_app_no_se_acepta_cualquier_cosa(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'que_me_den_un_vale',
        ])->assertStatus(422);
    }

    public function test_nadie_puede_decidir_sobre_el_pedido_de_otra_persona(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        // María es cliente mayorista de la misma distribuidora.
        $this->como('maria.lopez@revendedor.test');

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'sustitucion',
        ])->assertStatus(404);

        $this->assertSame('pendiente', $linea->fresh()->resolucion_no_surtido);
    }

    public function test_no_se_puede_decidir_sobre_un_renglon_que_si_llego(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", [
            'resolucion' => 'sustitucion',
        ])->assertStatus(422);
    }

    public function test_no_se_puede_cambiar_la_decision_despues(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", ['resolucion' => 'sustitucion'])->assertOk();
        $this->postJson("/api/pedidos/{$pedido->id}/detalle/{$linea->id}/resolucion", ['resolucion' => 'reembolso_anticipo'])->assertStatus(422);

        $this->assertSame('sustitucion', $linea->fresh()->resolucion_no_surtido);
    }

    // ------------------------------------------------------------------
    // El dinero: la única salida de efectivo del sistema
    // ------------------------------------------------------------------

    public function test_el_reembolso_crea_un_pago_de_salida_con_su_folio(): void
    {
        $pedido = $this->pedidoConAnticipo(200.0);
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);
        $accion->registrarDecision($linea->fresh(), 'reembolso_anticipo');

        $pago = $accion->entregarReembolso($linea->fresh());

        $this->assertSame('reembolso_anticipo', $pago->tipo);
        $this->assertSame('salida', $pago->direccion);
        $this->assertSame('efectivo', $pago->metodo);
        $this->assertSame('aplicado', $pago->estado);
        $this->assertNotEmpty($pago->folio);
        $this->assertSame((int) $pedido->id, (int) $pago->pedido_id);
    }

    public function test_nunca_se_devuelve_mas_de_lo_que_la_persona_pago(): void
    {
        // Pagó 50 de anticipo aunque el renglón pidiera más.
        $pedido = $this->pedidoConAnticipo(50.0);
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);
        $accion->registrarDecision($linea->fresh(), 'reembolso_anticipo');

        $pago = $accion->entregarReembolso($linea->fresh());

        $this->assertLessThanOrEqual(50.0, (float) $pago->monto);
    }

    public function test_no_se_devuelve_dos_veces_el_mismo_dinero(): void
    {
        $pedido = $this->pedidoConAnticipo(200.0);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);

        foreach ($pedido->detalle as $linea) {
            $this->marcar($linea);
            $accion->registrarDecision($linea->fresh(), 'reembolso_anticipo');
        }

        $devuelto = 0.0;

        foreach ($pedido->detalle as $linea) {
            try {
                $devuelto += (float) $accion->entregarReembolso($linea->fresh())->monto;
            } catch (ValidationException $e) {
                // Ya no queda dinero que devolver: es lo correcto.
            }
        }

        $this->assertLessThanOrEqual(200.0, $devuelto);
        $this->assertSame(
            $devuelto,
            round((float) Pago::withoutGlobalScopes()->where('direccion', 'salida')->sum('monto'), 2)
        );
    }

    public function test_sin_elegir_primero_no_se_entrega_nada(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);

        $this->expectException(ValidationException::class);
        app(ResolverNoSurtidoAction::class)->entregarReembolso($linea->fresh());
    }

    public function test_si_eligio_sustitucion_no_sale_dinero(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);
        $accion->registrarDecision($linea->fresh(), 'sustitucion');

        $this->expectException(ValidationException::class);
        $accion->entregarReembolso($linea->fresh());
    }

    public function test_un_pedido_pagado_con_vale_no_se_devuelve_en_efectivo(): void
    {
        // Devolver efectivo por un vale convertiría el vale en dinero, que es
        // justo lo que el negocio evita. Los vales no viven en la tabla de
        // pagos, así que no cuentan como dinero recibido.
        $pedido = $this->pedidoConAnticipo(200.0);

        Pago::withoutGlobalScopes()->where('pedido_id', $pedido->id)->delete();

        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);
        $accion->registrarDecision($linea->fresh(), 'reembolso_anticipo');

        $this->expectException(ValidationException::class);
        $accion->entregarReembolso($linea->fresh());
    }

    // ------------------------------------------------------------------
    // El panel
    // ------------------------------------------------------------------

    public function test_el_personal_marca_y_resuelve_desde_el_panel(): void
    {
        $pedido = $this->pedidoConAnticipo(200.0);
        $linea = $this->primeraLinea($pedido);

        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $pantalla = Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirNoSurtido', $linea->id)
            ->set('noSurtidoMotivo', 'La fábrica no lo mandó')
            ->call('marcarNoSurtido');

        $this->assertSame('no_surtido', $linea->fresh()->estado_surtido);

        $pantalla->call('registrarDecision', $linea->id, 'reembolso_anticipo');
        $this->assertSame('reembolso_anticipo', $linea->fresh()->resolucion_no_surtido);

        $pantalla->call('entregarReembolso', $linea->id);

        $this->assertSame(1, Pago::withoutGlobalScopes()->where('direccion', 'salida')->count());
    }

    public function test_el_panel_pide_el_motivo(): void
    {
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirNoSurtido', $linea->id)
            ->set('noSurtidoMotivo', '')
            ->call('marcarNoSurtido')
            ->assertSet('errorMsg', fn ($m) => $m !== '');

        $this->assertSame('pendiente', $linea->fresh()->estado_surtido);
    }

    public function test_queda_todo_en_la_bitacora_de_auditoria(): void
    {
        $pedido = $this->pedidoConAnticipo(200.0);
        $linea = $this->primeraLinea($pedido);
        $this->marcar($linea);

        $this->como(self::EMPLEADO);
        $accion = app(ResolverNoSurtidoAction::class);
        $accion->registrarDecision($linea->fresh(), 'reembolso_anticipo');
        $accion->entregarReembolso($linea->fresh());

        foreach (['pedido.no_surtido', 'pedido.no_surtido_resuelto', 'pedido.reembolso_anticipo'] as $accionEsperada) {
            $this->assertTrue(
                \App\Models\Auditoria::withoutGlobalScopes()->where('accion', $accionEsperada)->exists(),
                "Falta en la bitácora: {$accionEsperada}"
            );
        }
    }

    public function test_la_pantalla_se_actualiza_sola_al_marcar(): void
    {
        // Si no, el empleado marca, ve el aviso, la fila sigue igual y vuelve
        // a darle: entonces le sale "ya estaba marcado" y parece un error.
        $pedido = $this->pedidoConAnticipo();
        $linea = $this->primeraLinea($pedido);

        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirNoSurtido', $linea->id)
            ->set('noSurtidoMotivo', 'No llegó')
            ->call('marcarNoSurtido')
            // Ya se ven los botones para elegir, sin recargar la página.
            ->assertSee('¿Qué prefiere el cliente?')
            ->assertSee('Devolverle su anticipo');
    }
}
