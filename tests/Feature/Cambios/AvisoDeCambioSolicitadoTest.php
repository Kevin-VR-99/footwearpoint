<?php

namespace Tests\Feature\Cambios;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Cambio\SolicitarCambioAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TG-281 — Avisarle al personal que alguien pidió un cambio.
 *
 * Faltaba: la persona tocaba «Solicitar cambio» en la app, la app le decía
 * que quedó registrado, y no se enteraba nadie. La solicitud esperaba a que
 * a alguien se le ocurriera entrar a mirar la pantalla correcta, y el plazo
 * para gestionarla con la fábrica corre igual.
 *
 * Nota para quien venga después: los callbacks de DB::afterCommit SÍ corren
 * con RefreshDatabase. Laravel instala su propio gestor de transacciones en
 * las pruebas, que los ejecuta cuando la transacción de la aplicación hace
 * commit dentro de la del test. No hace falta DatabaseTruncation, y es mejor
 * no usarla: esa limpia al EMPEZAR, no al terminar, así que lo que crea se
 * queda y contamina las pruebas que corren después.
 */
class AvisoDeCambioSolicitadoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 12:00:00');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function clienteConCuenta(): ClienteDirecto
    {
        $cliente = ClienteDirecto::query()->whereNotNull('usuario_id')->orderBy('id')->first();
        $this->assertNotNull($cliente, 'El seeder no dejó clientes con cuenta de la app.');

        return $cliente;
    }

    private function como(Usuario $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function lineaEntregada(ClienteDirecto $cliente): PedidoDetalle
    {
        $pedido = Pedido::create([
            'tipo'                   => 'cliente_directo',
            'cliente_directo_id'     => $cliente->id,
            'distribuidora_id'       => Tenant::id(),
            'sucursal_id'            => Sucursal::query()->value('id'),
            'folio'                  => 'CAM-'.uniqid(),
            'estado'                 => 'entregado',
            'subtotal'               => 900,
            'total'                  => 900,
            'fecha_colocacion'       => '2026-09-01 10:00:00',
            'fecha_entrega'          => '2026-10-01 10:00:00',
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);

        $pc = ProductoCampana::query()->firstOrFail();

        return PedidoDetalle::query()->forceCreate([
            'distribuidora_id'    => Tenant::id(),
            'pedido_id'           => $pedido->id,
            'producto_campana_id' => $pc->id,
            'variante_id'         => Variante::query()->where('producto_id', $pc->producto_id)->value('id')
                ?? Variante::query()->value('id'),
            'producto_nombre'     => 'Bota Cambio',
            'modelo'              => 'BC-1',
            'talla'               => '25',
            'color'               => 'Café',
            'cantidad'            => 2,
            'precio_unitario'     => 450,
            'subtotal'            => 900,
        ]);
    }

    public function test_al_personal_le_llega_el_aviso_de_la_solicitud(): void
    {
        $cliente = $this->clienteConCuenta();
        $this->como(Usuario::findOrFail($cliente->usuario_id));
        $linea = $this->lineaEntregada($cliente);

        $solicitud = app(SolicitarCambioAction::class)->ejecutar([
            'pedido_detalle_id' => $linea->id,
            'motivo'            => 'No me quedó la talla',
        ]);

        $staffs = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $solicitud->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id');

        $this->assertNotEmpty($staffs, 'El seeder no dejó personal activo.');

        $avisos = Notificacion::withoutGlobalScopes()
            ->where('tipo', 'cambio_solicitado')
            ->whereIn('usuario_id', $staffs)
            ->get();

        // A todo el personal, como el resto de avisos del panel.
        $this->assertCount($staffs->count(), $avisos);

        $aviso = $avisos->first();
        $this->assertStringContainsString('Bota Cambio', $aviso->titulo);
        $this->assertStringContainsString('No me quedó la talla', $aviso->mensaje);
        // Con la fecha límite, que es lo que apura.
        $this->assertStringContainsString('25/10/2026', $aviso->mensaje);
        // Para poder abrir la solicitud desde el aviso.
        $this->assertSame('solicitud_cambio', $aviso->entidad_tipo);
        $this->assertSame((int) $solicitud->id, (int) $aviso->entidad_id);
    }

    public function test_el_aviso_dice_quien_lo_pidio(): void
    {
        $cliente = $this->clienteConCuenta();
        $this->como(Usuario::findOrFail($cliente->usuario_id));
        $linea = $this->lineaEntregada($cliente);

        app(SolicitarCambioAction::class)->ejecutar([
            'pedido_detalle_id' => $linea->id,
            'motivo'            => 'Salió defectuoso',
        ]);

        $aviso = Notificacion::withoutGlobalScopes()
            ->where('tipo', 'cambio_solicitado')
            ->firstOrFail();

        $this->assertStringContainsString($cliente->nombre, $aviso->mensaje);
    }

    /**
     * Al cliente no se le avisa: acaba de pedirlo y ya vio la confirmación
     * en pantalla. Un aviso de algo que uno mismo hizo hace dos segundos es
     * ruido, y una bandeja con ruido se deja de mirar.
     */
    public function test_al_cliente_no_se_le_avisa_de_su_propia_solicitud(): void
    {
        $cliente = $this->clienteConCuenta();
        $this->como(Usuario::findOrFail($cliente->usuario_id));
        $linea = $this->lineaEntregada($cliente);

        app(SolicitarCambioAction::class)->ejecutar([
            'pedido_detalle_id' => $linea->id,
            'motivo'            => 'No me quedó la talla',
        ]);

        $this->assertSame(
            0,
            Notificacion::withoutGlobalScopes()
                ->where('usuario_id', $cliente->usuario_id)
                ->where('tipo', 'cambio_solicitado')
                ->count(),
        );
    }
}
