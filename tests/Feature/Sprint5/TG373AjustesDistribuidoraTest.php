<?php

namespace Tests\Feature\Sprint5;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\SolicitudCambio;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;
use App\Services\Auth\PermisoModuloService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * TG-373 [G50] — Ajustes del rol distribuidora:
 * 1) Permisos coherentes del empleado (links de admin solo para admin).
 * 2) Permiso fino y auditoría al emitir vales.
 * 3) Buscador y filtro de estado en lista de vales.
 * 4) Acciones y tarjetas operativas en Inicio (Ventas de hoy, Cambios por resolver, Stock bajo).
 */
class TG373AjustesDistribuidoraTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_empleado_con_configuracion_administrar_no_ve_links_a_rutas_solo_admin_y_admin_si(): void
    {
        $empleado = $this->comoPanel(self::EMPLEADO);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id());
        $empleado->givePermissionTo('configuracion.administrar');

        $htmlEmpleado = $this->get(route('dashboard'))->assertOk()->getContent();

        // El empleado no ve Sistema ni las rutas exclusivas de admin_distribuidora
        $this->assertStringNotContainsString(route('distribuidora.configuracion'), $htmlEmpleado);
        $this->assertStringNotContainsString(route('distribuidora.perfil'), $htmlEmpleado);
        $this->assertStringNotContainsString(route('distribuidora.empleados'), $htmlEmpleado);
        $this->assertStringNotContainsString(route('distribuidora.mayoristas'), $htmlEmpleado);
        $this->assertStringNotContainsString(route('distribuidora.clientes-directos'), $htmlEmpleado);

        // El admin sí ve todas las rutas administrativas
        $this->comoPanel(self::ADMIN);
        $htmlAdmin = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('distribuidora.configuracion'), $htmlAdmin);
        $this->assertStringContainsString(route('distribuidora.perfil'), $htmlAdmin);
        $this->assertStringContainsString(route('distribuidora.empleados'), $htmlAdmin);
        $this->assertStringContainsString(route('distribuidora.mayoristas'), $htmlAdmin);
        $this->assertStringContainsString(route('distribuidora.clientes-directos'), $htmlAdmin);
    }

    public function test_pantalla_usuarios_no_muestra_casilla_usuarios_y_muestra_minimos_de_stock_y_anticipos(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = Livewire::test('distribuidora.usuarios')->html();

        // No mostrar la casilla usuarios.administrar
        $this->assertStringNotContainsString('value="usuarios.administrar"', $html);

        // Mostrar Configuración como Mínimos de stock y anticipos
        $this->assertStringContainsString('Mínimos de stock y anticipos', $html);

        // Permiso fino de vales emitir
        $this->assertStringContainsString('Emitir vales manualmente', $html);
    }

    public function test_empleado_con_vales_emitir_apagado_no_puede_emitir_y_con_default_si_con_auditoria(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $cliente = ClienteDirecto::query()->firstOrFail();

        // 1. Con el valor por omisión (true), el empleado puede emitir vales
        Livewire::test('vales.index')
            ->assertSee('Emitir vale')
            ->set('propietario_tipo', 'cliente_directo')
            ->set('propietario_id', (string) $cliente->id)
            ->set('monto_original', '325.50')
            ->set('motivo', 'Compensación autorizada')
            ->call('emitir')
            ->assertHasNoErrors();

        $vale = Vale::query()->where('monto_original', 325.50)->firstOrFail();
        $this->assertSame($cliente->id, $vale->cliente_directo_id);

        // Se registra auditoría vale.emitido
        $this->assertTrue(
            Auditoria::query()
                ->where('accion', 'vale.emitido')
                ->where('entidad_tipo', 'vale')
                ->where('entidad_id', $vale->id)
                ->exists()
        );

        // 2. Apagamos el permiso fino vales.emitir para el empleado
        $staff = DistribuidoraStaff::query()->where('usuario_id', $this->usuario(self::EMPLEADO)->id)->firstOrFail();
        $staff->forceFill([
            'permisos_finos' => [PermisoModuloService::VALES_EMITIR => false],
        ])->save();

        $this->comoPanel(self::EMPLEADO);

        // El formulario no se muestra y emitir() lanza 403
        Livewire::test('vales.index')
            ->assertDontSee('Emitir vale')
            ->set('propietario_tipo', 'cliente_directo')
            ->set('propietario_id', (string) $cliente->id)
            ->set('monto_original', '800.00')
            ->call('emitir')
            ->assertForbidden();

        $this->assertFalse(Vale::query()->where('monto_original', 800.00)->exists());
    }

    public function test_lista_vales_busqueda_filtro_estado_y_mensaje_vacio(): void
    {
        $this->comoPanel(self::ADMIN);

        $cliente1 = ClienteDirecto::create([
            'distribuidora_id' => Tenant::id(),
            'nombre' => 'Ana Busqueda Vales',
            'telefono' => '5544332211',
            'estado' => 'activo',
        ]);
        $cliente2 = ClienteDirecto::create([
            'distribuidora_id' => Tenant::id(),
            'nombre' => 'Carlos Otro Propietario',
            'telefono' => '5588776655',
            'estado' => 'activo',
        ]);

        $staffId = DistribuidoraStaff::value('id');

        $vale1 = Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente1->id,
            'folio' => 'VAL-TG373-01',
            'monto_original' => 150,
            'saldo_actual' => 150,
            'fecha_emision' => now(),
            'fecha_vencimiento' => now()->addDays(90),
            'estado' => 'activo',
            'creado_por_staff_id' => $staffId,
        ]);

        $vale2 = Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente2->id,
            'folio' => 'VAL-TG373-02',
            'monto_original' => 200,
            'saldo_actual' => 0,
            'fecha_emision' => now()->subDays(10),
            'fecha_vencimiento' => now()->addDays(80),
            'estado' => 'agotado',
            'creado_por_staff_id' => $staffId,
        ]);

        $pantalla = Livewire::test('vales.index');

        // Ambos vales son visibles inicialmente
        $pantalla->assertSee('VAL-TG373-01')
            ->assertSee('VAL-TG373-02')
            ->assertSee('Ana Busqueda Vales')
            ->assertSee('Carlos Otro Propietario');

        // Búsqueda por nombre de cliente
        $pantalla->set('busqueda', 'Ana Busqueda')
            ->assertSee('VAL-TG373-01')
            ->assertDontSee('VAL-TG373-02');

        // Búsqueda por teléfono normalizado
        $pantalla->set('busqueda', '55 8877 6655')
            ->assertSee('VAL-TG373-02')
            ->assertDontSee('VAL-TG373-01');

        // Filtro por estado
        $pantalla->set('busqueda', '')
            ->set('estado', 'agotado')
            ->assertSee('VAL-TG373-02')
            ->assertDontSee('VAL-TG373-01');

        // Filtro sin resultados muestra mensaje específico
        $pantalla->set('busqueda', 'Nombre Inexistente XYZ')
            ->assertSee('No hay vales con estos filtros.')
            ->assertDontSee('No hay vales emitidos todavía.');
    }

    public function test_dashboard_muestra_acciones_y_metricas_aisladas_por_tenant(): void
    {
        $this->comoPanel(self::ADMIN);

        // 1. Crear venta completada hoy en el tenant actual
        $ventaPropia = VentaDirecta::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::value('id'),
            'folio' => 'VD-TG373-01',
            'subtotal' => 500,
            'total' => 500,
            'estado' => 'completada',
            'fecha_venta' => now(),
            'registrada_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);

        // 2. Crear solicitud de cambio solicitada en el tenant actual
        $solicitudPropia = SolicitudCambio::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => ClienteDirecto::value('id'),
            'estado' => 'solicitada',
            'fecha_solicitud' => now(),
            'fecha_entrega_original' => now()->subDay(),
            'fecha_limite_solicitud' => now()->addDays(5),
            'dias_solicitud_aplicados' => 8,
            'dias_gestion_fabrica_aplicados' => 20,
            'fecha_limite_gestion_fabrica' => now()->addDays(20),
            'motivo' => 'Cambio talla TG373',
        ]);

        // 3. Crear datos en OTRA distribuidora (no deben sumarse)
        $otraDistribuidoraId = \App\Models\Distribuidora::query()
            ->where('id', '!=', Tenant::id())
            ->value('id');

        $otraSucursalId = Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $otraDistribuidoraId)
            ->value('id');

        if (! $otraSucursalId && $otraDistribuidoraId) {
            $otraSucursalId = Sucursal::withoutGlobalScopes()->create([
                'distribuidora_id' => $otraDistribuidoraId,
                'nombre' => 'Sucursal Otra',
                'es_principal' => true,
            ])->id;
        }

        if ($otraDistribuidoraId && $otraSucursalId) {
            $staffOtra = DistribuidoraStaff::withoutGlobalScopes()->where('distribuidora_id', $otraDistribuidoraId)->value('id');
            if (! $staffOtra) {
                $staffOtra = DistribuidoraStaff::withoutGlobalScopes()->create([
                    'distribuidora_id' => $otraDistribuidoraId,
                    'usuario_id' => $this->usuario(self::OTRA_ADMIN)->id,
                    'cargo' => 'Admin',
                    'activo' => true,
                ])->id;
            }

            $clienteOtra = ClienteDirecto::withoutGlobalScopes()->where('distribuidora_id', $otraDistribuidoraId)->value('id');
            if (! $clienteOtra) {
                $clienteOtra = ClienteDirecto::withoutGlobalScopes()->create([
                    'distribuidora_id' => $otraDistribuidoraId,
                    'nombre' => 'Cliente Otra Distribuidora',
                    'telefono' => '5500998877',
                    'estado' => 'activo',
                ])->id;
            }

            VentaDirecta::create([
                'distribuidora_id' => $otraDistribuidoraId,
                'sucursal_id' => $otraSucursalId,
                'folio' => 'VD-TG373-OTRA',
                'subtotal' => 9999,
                'total' => 9999,
                'estado' => 'completada',
                'fecha_venta' => now(),
                'registrada_por_staff_id' => $staffOtra,
            ]);

            SolicitudCambio::create([
                'distribuidora_id' => $otraDistribuidoraId,
                'cliente_directo_id' => $clienteOtra,
                'estado' => 'solicitada',
                'fecha_solicitud' => now(),
                'fecha_entrega_original' => now()->subDay(),
                'fecha_limite_solicitud' => now()->addDays(5),
                'dias_solicitud_aplicados' => 8,
                'dias_gestion_fabrica_aplicados' => 20,
                'fecha_limite_gestion_fabrica' => now()->addDays(20),
                'motivo' => 'Cambio ajeno TG373',
            ]);

            // Stock bajo en la otra distribuidora (no debe contarse en el inicio del tenant actual)
            $varianteOtra = Variante::first();
            if ($varianteOtra) {
                StockLocal::withoutGlobalScopes()->create([
                    'distribuidora_id' => $otraDistribuidoraId,
                    'sucursal_id' => $otraSucursalId,
                    'variante_id' => $varianteOtra->id,
                    'cantidad_disponible' => 0,
                    'cantidad_comprometida' => 0,
                    'stock_minimo' => 50,
                ]);
            }
        }

        $pantalla = Livewire::test('dashboard.index');

        // Verifica que la barra w-3/4 no esté en el HTML
        $this->assertStringNotContainsString('w-3/4 rounded-full bg-fp-primary', $pantalla->html());

        // Acción Nueva venta
        $pantalla->assertSee('Nueva venta')
            ->assertSee(route('punto-venta.index'));

        // Tarjetas operativas
        $pantalla->assertSee('Ventas de hoy')
            ->assertSee(route('punto-venta.index', ['pestana' => 'historial']))
            ->assertSee('Cambios por resolver')
            ->assertSee(route('cambios.index'))
            ->assertSee('Stock bajo')
            ->assertSee(route('stock.index'));

        $resumen = $pantalla->instance()->resumen;
        $this->assertGreaterThanOrEqual(1, $resumen['ventas_hoy_cantidad']);
        $this->assertGreaterThanOrEqual(500.0, $resumen['ventas_hoy_total']);
        $this->assertGreaterThanOrEqual(1, $resumen['cambios_por_resolver']);
        $this->assertIsInt($resumen['stock_bajo']);

        // Comprobamos que el total de ventas de hoy no incluya los 9999 de la otra distribuidora
        $this->assertLessThan(9000.0, $resumen['ventas_hoy_total']);

        // Comprobamos que stock_bajo no cuenta el stock de la otra distribuidora
        if ($otraDistribuidoraId) {
            $minimoGeneral = (int) (ConfiguracionDistribuidora::query()->value('stock_minimo_general') ?? 0);
            $stockBajoPropio = StockLocal::query()
                ->where('distribuidora_id', Tenant::id())
                ->whereRaw('cantidad_disponible < (CASE WHEN stock_minimo > 0 THEN stock_minimo ELSE ? END)', [$minimoGeneral])
                ->count();
            $this->assertSame($stockBajoPropio, $resumen['stock_bajo']);

            $stockBajoTotal = StockLocal::withoutGlobalScopes()
                ->whereRaw('cantidad_disponible < (CASE WHEN stock_minimo > 0 THEN stock_minimo ELSE ? END)', [$minimoGeneral])
                ->count();
            $this->assertLessThan($stockBajoTotal, $resumen['stock_bajo']);
        }
    }

    public function test_lista_vales_muestra_insignias_con_color_y_texto_capitalizado(): void
    {
        $this->comoPanel(self::ADMIN);
        $cliente = ClienteDirecto::query()->firstOrFail();
        $staffId = DistribuidoraStaff::value('id');

        Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente->id,
            'folio' => 'VAL-COL-01',
            'monto_original' => 100,
            'saldo_actual' => 100,
            'fecha_emision' => now(),
            'fecha_vencimiento' => now()->addDays(30),
            'estado' => 'activo',
            'creado_por_staff_id' => $staffId,
        ]);

        Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente->id,
            'folio' => 'VAL-COL-02',
            'monto_original' => 100,
            'saldo_actual' => 0,
            'fecha_emision' => now()->subDays(10),
            'fecha_vencimiento' => now()->addDays(20),
            'estado' => 'agotado',
            'creado_por_staff_id' => $staffId,
        ]);

        Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente->id,
            'folio' => 'VAL-COL-03',
            'monto_original' => 100,
            'saldo_actual' => 50,
            'fecha_emision' => now()->subDays(40),
            'fecha_vencimiento' => now()->subDays(10),
            'estado' => 'vencido',
            'creado_por_staff_id' => $staffId,
        ]);

        Vale::create([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente->id,
            'folio' => 'VAL-COL-04',
            'monto_original' => 100,
            'saldo_actual' => 50,
            'fecha_emision' => now()->subDays(5),
            'fecha_vencimiento' => now()->addDays(25),
            'estado' => 'bloqueado',
            'creado_por_staff_id' => $staffId,
        ]);

        $html = Livewire::test('vales.index')->html();

        // 1. Activo con success
        $this->assertStringContainsString('Activo', $html);
        $this->assertStringContainsString('fp-badge-success', $html);

        // 2. Agotado con neutral
        $this->assertStringContainsString('Agotado', $html);
        $this->assertStringContainsString('fp-badge-neutral', $html);

        // 3. Vencido con warning
        $this->assertStringContainsString('Vencido', $html);
        $this->assertStringContainsString('fp-badge-warning', $html);

        // 4. Bloqueado con danger
        $this->assertStringContainsString('Bloqueado', $html);
        $this->assertStringContainsString('fp-badge-danger', $html);
    }
}
