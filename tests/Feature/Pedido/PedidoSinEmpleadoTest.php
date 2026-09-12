<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TG-138 — Un cliente directo o revendedor que opera desde la app móvil no
 * es empleado de la distribuidora, así que no hay staff que registrar como
 * autor de la operación.
 *
 * Antes de este cambio, CrearPedidoBorradorAction abortaba con 403 y las
 * columnas *_staff_id eran NOT NULL: era imposible que el cliente hiciera
 * su propio pedido.
 */
class PedidoSinEmpleadoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function sucursalPrincipal(int $distribuidoraId): Sucursal
    {
        return Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('es_principal', true)
            ->firstOrFail();
    }

    private function crearClienteDirectoConCuenta(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Cliente ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'Cliente ' . $email,
            'email'            => $email,
            'estado'           => 'activo',
        ]);

        return $usuario;
    }

    private function columnaAceptaNulo(string $tabla, string $columna): bool
    {
        foreach (Schema::getColumns($tabla) as $info) {
            if ($info['name'] === $columna) {
                return (bool) $info['nullable'];
            }
        }

        $this->fail("No existe la columna {$tabla}.{$columna}.");
    }

    // ------------------------------------------------------------------
    // La migración
    // ------------------------------------------------------------------

    public function test_las_columnas_de_autor_aceptan_nulo(): void
    {
        $columnas = [
            'pedidos'                  => 'capturado_por_staff_id',
            'pagos'                    => 'registrado_por_staff_id',
            'historial_estados_pedido' => 'cambiado_por_staff_id',
            'vale_movimientos'         => 'registrado_por_staff_id',
        ];

        foreach ($columnas as $tabla => $columna) {
            $this->assertTrue(
                $this->columnaAceptaNulo($tabla, $columna),
                "{$tabla}.{$columna} debería aceptar nulo para operaciones hechas desde la app."
            );
        }
    }

    /**
     * Decisión deliberada: emitir un vale es entregar saldo, y eso solo lo
     * hace la distribuidora. Un cliente únicamente aplica vales que ya le
     * dieron. Si algún día esta prueba se pone roja, alguien aflojó esa
     * regla sin querer.
     */
    public function test_emitir_un_vale_sigue_exigiendo_empleado(): void
    {
        $this->assertFalse(
            $this->columnaAceptaNulo('vales', 'creado_por_staff_id'),
            'vales.creado_por_staff_id NO debe aceptar nulo: emitir vales es solo de la distribuidora.'
        );
    }

    // ------------------------------------------------------------------
    // El comportamiento real
    // ------------------------------------------------------------------

    public function test_un_cliente_directo_crea_su_propio_pedido_sin_empleado(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearClienteDirectoConCuenta($distribuidoraA->id, 'cli.pedido@cliente.test');

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $cliente = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->firstOrFail();

        $pedido = app(CrearPedidoBorradorAction::class)->ejecutar([
            'tipo'           => 'cliente_directo',
            'propietario_id' => $cliente->id,
            'sucursal_id'    => $this->sucursalPrincipal($distribuidoraA->id)->id,
        ]);

        // Nadie lo capturó: lo hizo el propio cliente desde la app.
        $this->assertNull($pedido->capturado_por_staff_id);

        // Pero de quién es el pedido no se perdió.
        $this->assertSame($cliente->id, $pedido->cliente_directo_id);
        $this->assertSame($distribuidoraA->id, $pedido->distribuidora_id);
        $this->assertSame('borrador', $pedido->estado);
    }

    /**
     * Regresión: el mostrador sigue funcionando igual. Cuando es el empleado
     * quien captura el pedido, tiene que quedar registrado como siempre.
     */
    public function test_un_empleado_sigue_quedando_registrado_al_capturar_un_pedido(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        $this->actingAs($empleado);
        Tenant::olvidarCache();

        $cliente = ClienteDirecto::query()->firstOrFail();

        $pedido = app(CrearPedidoBorradorAction::class)->ejecutar([
            'tipo'           => 'cliente_directo',
            'propietario_id' => $cliente->id,
            'sucursal_id'    => $this->sucursalPrincipal($distribuidoraA->id)->id,
        ]);

        $this->assertNotNull($pedido->capturado_por_staff_id);
    }
}
