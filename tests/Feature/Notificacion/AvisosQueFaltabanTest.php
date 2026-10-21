<?php

namespace Tests\Feature\Notificacion;

use App\Models\ClienteDirecto;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CambiarEstadoPedidoService;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Support\Estados;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * TG-281 — Avisos que faltaban, encontrados al revisar la app entera.
 *
 * De trece momentos en que algo le pasa al cliente, solo siete le avisaban.
 * Aquí van los dos que de verdad dejaban a alguien esperando algo que nunca
 * llegaba, más el texto de los avisos del personal.
 */
class AvisosQueFaltabanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));
    }

    private function comoEmpleado(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Un pedido de José, recién colocado. */
    private function pedidoDeJose(): Pedido
    {
        $cuenta = Usuario::where('email', self::JOSE)->firstOrFail();
        $cliente = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $cuenta->id)
            ->firstOrFail();

        $sucursal = Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $cliente->distribuidora_id)
            ->orderBy('id')
            ->firstOrFail();

        return Pedido::withoutGlobalScopes()->create([
            'distribuidora_id'   => $cliente->distribuidora_id,
            'sucursal_id'        => $sucursal->id,
            'cliente_directo_id' => $cliente->id,
            'tipo'               => 'cliente_directo',
            'folio'              => 'PED-AVISOS-01',
            'estado'             => 'colocado',
            'subtotal'           => 1200,
            'total'              => 1200,
            'fecha_colocacion'   => now(),
        ]);
    }

    private function avisosDeJose(string $tipo): int
    {
        $cuenta = Usuario::where('email', self::JOSE)->firstOrFail();

        return Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $cuenta->id)
            ->where('tipo', $tipo)
            ->count();
    }

    /**
     * El que más dolía: el cliente perdía el plazo y su anticipo quedaba en
     * el aire sin que nadie se lo dijera. Es dinero suyo.
     */
    public function test_al_cliente_se_le_avisa_que_se_le_vencio_el_plazo_para_recoger(): void
    {
        $this->comoEmpleado();
        $pedido = $this->pedidoDeJose();

        app(CambiarEstadoPedidoService::class)->cambiar($pedido, 'vencido_recoleccion');

        $this->assertSame(1, $this->avisosDeJose('recoleccion_vencida'));

        $cuenta = Usuario::where('email', self::JOSE)->firstOrFail();
        $aviso = Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $cuenta->id)
            ->where('tipo', 'recoleccion_vencida')
            ->firstOrFail();

        $this->assertStringContainsString($pedido->folio, $aviso->titulo);
        $this->assertStringContainsString('anticipo', $aviso->mensaje);
    }

    public function test_al_cliente_se_le_avisa_si_le_rechazan_el_pedido(): void
    {
        $this->comoEmpleado();
        $pedido = $this->pedidoDeJose();

        app(CambiarEstadoPedidoService::class)->cambiar($pedido, 'rechazado');

        $this->assertSame(1, $this->avisosDeJose('pedido_rechazado'));
    }

    /**
     * Los avisos del personal decían «Pedido PED-015 → listo_entrega» y
     * «cambió de «colocado» a «listo_entrega»», con guion bajo. El personal
     * lee esto todos los días.
     */
    public function test_los_avisos_del_personal_usan_los_nombres_como_se_leen(): void
    {
        $this->comoEmpleado();
        $pedido = $this->pedidoDeJose();
        $empleado = Usuario::where('email', self::EMPLEADO)->firstOrFail();

        app(CambiarEstadoPedidoService::class)->cambiar($pedido, 'listo_entrega');

        $aviso = Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $empleado->id)
            ->where('tipo', 'pedido_estado')
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('Listo entrega', $aviso->titulo);
        $this->assertStringNotContainsString('listo_entrega', $aviso->titulo);
        $this->assertStringNotContainsString('_', $aviso->mensaje);
    }

    public function test_el_mapa_de_estados_es_el_mismo_que_usa_la_insignia_de_la_web(): void
    {
        // Si alguien agrega un estado y olvida la etiqueta, esto lo dice.
        $this->assertSame('Listo entrega', Estados::etiqueta('listo_entrega'));
        $this->assertSame('Recolección vencida', Estados::etiqueta('vencido_recoleccion'));
        $this->assertSame('danger', Estados::variante('rechazado'));

        // Un estado desconocido se lee, aunque feo, en vez de romper.
        $this->assertSame('algo raro', Estados::etiqueta('algo_raro'));
        $this->assertSame('—', Estados::etiqueta(null));
    }
}
