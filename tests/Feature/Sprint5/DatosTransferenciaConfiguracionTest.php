<?php

namespace Tests\Feature\Sprint5;

use App\Models\Auditoria;
use App\Models\ComprobanteTransferencia;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Pago;
use App\Models\VentaDirecta;
use App\Support\BancosMexico;
use App\Support\Clabe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * Datos de transferencia en Configuración: sección editable, interruptor
 * «Aceptar transferencias» y select de banco con la lista de México.
 */
class DatosTransferenciaConfiguracionTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLABE = '012180001234567899';

    private function config(): ConfiguracionDistribuidora
    {
        return ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $this->ramirez()->id)
            ->firstOrFail();
    }

    private function guardarCuenta(): void
    {
        Mail::fake();
        $this->comoPanel(self::ADMIN);

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'BBVA')
            ->set('clabe', self::CLABE)
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', '');
    }

    private function apagar(): void
    {
        $this->comoPanel(self::ADMIN);

        Livewire::test('distribuidora.datos-bancarios')
            ->assertSet('aceptaTransferencias', true)
            ->call('alternarTransferencias')
            ->assertSet('errorMsg', '')
            ->assertSet('aceptaTransferencias', false);
    }

    /** @return array{0: int, 1: float} */
    private function pedidoConSaldo(): array
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $this->comoApi(self::MARIA);
        $saldo = (float) $this->getJson("/api/pedidos/{$pedidoId}")->json('data.saldo');
        $this->assertGreaterThan(20, $saldo);

        return [$pedidoId, $saldo];
    }

    public function test_la_seccion_muestra_los_datos_guardados_junto_a_mercado_pago(): void
    {
        $this->guardarCuenta();

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertOk()
            ->assertSee('Transferencia bancaria')
            ->assertSee('Aceptar transferencias')
            ->assertSee('Disponible')
            ->assertSee('data-interruptor-transferencias', false)
            ->assertSee('data-datos-bancarios', false);

        Livewire::test('distribuidora.datos-bancarios')
            ->assertSet('banco', 'BBVA')
            ->assertSet('clabe', self::CLABE)
            ->assertSet('titular', 'Calzados Ramírez')
            ->assertSet('aceptaTransferencias', true);
    }

    public function test_el_admin_edita_con_las_mismas_validaciones_y_el_banco_sale_de_la_lista(): void
    {
        Mail::fake();
        $this->comoPanel(self::ADMIN);

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'BBVA')
            ->set('clabe', '012180001234567897')
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', Clabe::MENSAJE);

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'Banco inventado')
            ->set('clabe', self::CLABE)
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', 'Elige el banco de la lista.');

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', '')
            ->set('clabe', self::CLABE)
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', 'Elige el banco de la lista.');

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'BBVA')
            ->set('clabe', self::CLABE)
            ->set('titular', '')
            ->call('guardar')
            ->assertSet('errorMsg', 'Escribe el titular de la cuenta, con 150 caracteres como máximo.');

        $this->assertNull($this->config()->banco);

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'Spin by OXXO')
            ->set('clabe', self::CLABE)
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', '');

        $this->assertSame('Spin by OXXO', $this->config()->banco);
    }

    public function test_un_banco_escrito_a_mano_antes_de_la_lista_se_conserva_pero_uno_nuevo_no(): void
    {
        Mail::fake();
        $this->comoPanel(self::ADMIN);

        $this->config()->update([
            'banco' => 'Mi banco de antes',
            'clabe' => self::CLABE,
            'titular' => null,
            'titular_cuenta' => 'Calzados Ramírez',
        ]);

        Livewire::test('distribuidora.datos-bancarios')
            ->assertSet('banco', 'Mi banco de antes')
            ->set('titular', 'Calzados Ramírez SA')
            ->call('guardar')
            ->assertSet('errorMsg', '');

        $this->assertSame('Mi banco de antes', $this->config()->banco);

        Livewire::test('distribuidora.datos-bancarios')
            ->set('banco', 'Otro banco escrito')
            ->call('guardar')
            ->assertSet('errorMsg', 'Elige el banco de la lista.');
    }

    public function test_el_empleado_no_puede_apagar_ni_encender_las_transferencias(): void
    {
        $this->comoPanel(self::EMPLEADO);

        Livewire::test('distribuidora.datos-bancarios')
            ->call('alternarTransferencias')
            ->assertSet('aceptaTransferencias', true);

        $this->assertTrue($this->config()->acepta_transferencias);

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))->assertForbidden();
    }

    public function test_por_omision_acepta_transferencias_y_apagar_conserva_los_datos_y_deja_bitacora(): void
    {
        $this->assertTrue($this->config()->acepta_transferencias);

        $this->guardarCuenta();
        $this->apagar();

        $config = $this->config();
        $this->assertFalse($config->acepta_transferencias);
        $this->assertSame('BBVA', $config->banco);
        $this->assertSame(self::CLABE, $config->clabe);
        $this->assertSame('Calzados Ramírez', $config->titular_cuenta);

        $auditoria = Auditoria::query()->where('accion', 'datos_bancarios.disponibilidad')->firstOrFail();
        $this->assertTrue($auditoria->datos_previos['acepta_transferencias']);
        $this->assertFalse($auditoria->datos_nuevos['acepta_transferencias']);

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertOk()
            ->assertSee('No disponible por el momento');

        Livewire::test('distribuidora.datos-bancarios')
            ->assertSet('aceptaTransferencias', false)
            ->assertSet('clabe', self::CLABE)
            ->call('alternarTransferencias')
            ->assertSet('aceptaTransferencias', true);

        $this->assertTrue($this->config()->acepta_transferencias);
    }

    public function test_apagada_la_opcion_no_aparece_al_cobrar_un_pedido_ni_en_el_mostrador(): void
    {
        $this->guardarCuenta();
        [$pedidoId] = $this->pedidoConSaldo();

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('<option value="transferencia">', false)
            ->assertSee('data-transferencia-spei', false);
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('<option value="transferencia">', false);

        $this->apagar();

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('<option value="efectivo">', false)
            ->assertDontSee('<option value="transferencia">', false)
            ->assertDontSee('data-transferencia-spei', false);
        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('<option value="efectivo">', false)
            ->assertDontSee('<option value="transferencia">', false);
    }

    public function test_apagada_el_cliente_y_la_api_no_reciben_los_datos(): void
    {
        $this->guardarCuenta();
        [$pedidoId] = $this->pedidoConSaldo();

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->assertJsonPath('data.transferencia.clabe', self::CLABE);

        $this->apagar();

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->assertJsonPath('data.transferencia', null);

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])->assertDontSee(self::CLABE);

        // Encendida otra vez, vuelven los mismos datos.
        Livewire::test('distribuidora.datos-bancarios')->call('alternarTransferencias');
        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->assertJsonPath('data.transferencia.clabe', self::CLABE);
    }

    public function test_el_backend_rechaza_cobrar_un_pedido_por_transferencia_mientras_esta_apagada(): void
    {
        $this->guardarCuenta();
        [$pedidoId] = $this->pedidoConSaldo();
        $this->apagar();

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'saldo_pedido',
            'metodo' => 'transferencia',
            'monto' => 10,
        ])->assertStatus(422)
            ->assertJsonPath('errors.metodo.0', 'Esta distribuidora no acepta transferencias por el momento.');

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());

        // Las demás formas de pago siguen funcionando.
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'saldo_pedido',
            'metodo' => 'efectivo',
            'monto' => 10,
        ])->assertCreated();

        // Desde el panel también.
        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoTipo', 'saldo_pedido')
            ->set('pagoMetodo', 'transferencia')
            ->set('pagoMonto', '10')
            ->call('registrarPago')
            ->assertSet('errorMsg', 'Esta distribuidora no acepta transferencias por el momento.');

        $this->assertSame(1, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());

        // Encendida, se puede cobrar por transferencia como siempre.
        Livewire::test('distribuidora.datos-bancarios')->call('alternarTransferencias');
        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'saldo_pedido',
            'metodo' => 'transferencia',
            'monto' => 10,
        ])->assertCreated();
    }

    public function test_el_mostrador_y_la_api_de_ventas_rechazan_la_transferencia_apagada(): void
    {
        $this->guardarCuenta();
        $this->apagar();

        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon);

        $antes = VentaDirecta::withoutGlobalScopes()->count();

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('metodo_pago', 'transferencia')
            ->call('cobrar')
            ->assertSet('errorMsg', 'Esta distribuidora no acepta transferencias por el momento.');

        $this->comoApi(self::EMPLEADO);
        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'transferencia',
            'lineas' => [[
                'variante_id' => $renglon['variante_id'],
                'producto_campana_id' => $renglon['producto_campana_id'],
                'cantidad' => 1,
            ]],
        ])->assertStatus(422);

        $this->assertSame($antes, VentaDirecta::withoutGlobalScopes()->count());

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [[
                'variante_id' => $renglon['variante_id'],
                'producto_campana_id' => $renglon['producto_campana_id'],
                'cantidad' => 1,
            ]],
        ])->assertCreated();
    }

    public function test_apagada_tampoco_se_sube_un_comprobante_nuevo(): void
    {
        Storage::fake(ComprobanteTransferencia::disco());
        $this->guardarCuenta();
        [$pedidoId] = $this->pedidoConSaldo();
        $this->apagar();

        $this->comoApi(self::MARIA);
        $this->post("/api/pedidos/{$pedidoId}/comprobante-transferencia", [
            'comprobante' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
    }

    public function test_la_lista_de_bancos_de_mexico_y_la_deteccion_por_clabe(): void
    {
        $nombres = BancosMexico::nombres();

        foreach ([
            'BBVA', 'Santander', 'Banorte', 'Banamex', 'HSBC', 'Scotiabank', 'Inbursa', 'Banco Azteca', 'BanCoppel',
            'Afirme', 'Banregio', 'Bajío', 'Banjercito', 'Multiva', 'Mifel', 'Monex', 'Intercam', 'Actinver', 'Invex',
            'Compartamos', 'Bancrea', 'Hey Banco', 'Nu México', 'Spin by OXXO', 'Mercado Pago W', 'STP', 'Klar', 'Albo',
            'Banco del Bienestar',
        ] as $banco) {
            $this->assertContains($banco, $nombres, "Falta {$banco} en la lista.");
        }

        $this->assertGreaterThan(60, count($nombres));
        $this->assertSame($nombres, array_values(array_unique($nombres)));

        foreach ($nombres as $nombre) {
            $this->assertLessThanOrEqual(80, mb_strlen($nombre));
        }

        $this->assertSame('BBVA', BancosMexico::detectarPorClabe('012180001234567899'));
        $this->assertSame('Mercado Pago W', BancosMexico::detectarPorClabe('722 969 0000 000000 01'));
        $this->assertSame('Banorte', BancosMexico::detectarPorClabe('072'));
        $this->assertNull(BancosMexico::detectarPorClabe('99'));
        $this->assertNull(BancosMexico::detectarPorClabe('999180001234567899'));
        $this->assertNull(BancosMexico::detectarPorClabe('abc'));
        $this->assertTrue(BancosMexico::existe('Klar'));
        $this->assertFalse(BancosMexico::existe('klar'));
    }

    public function test_el_select_con_buscador_lleva_la_lista_y_no_usa_js_en_linea(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = Livewire::test('distribuidora.datos-bancarios')->html();

        $this->assertStringContainsString('data-select-banco', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('aria-autocomplete="list"', $html);
        $this->assertStringContainsString('fpSelectBanco(', $html);
        $this->assertStringContainsString("clabeId:'clabe-transferencia'", str_replace(' ', '', html_entity_decode($html)));
        // La clave de la CLABE viaja como texto de tres dígitos, no como número.
        $this->assertStringContainsString('\u0022clave\u0022:\u0022141\u0022', $html);
        $this->assertStringContainsString('\u0022clave\u0022:\u0022002\u0022', $html);

        // La lista completa viaja con el componente (Alpine la pinta y filtra).
        $plano = html_entity_decode($html);
        foreach (['Spin by OXXO', 'Mercado Pago W', 'Banco del Bienestar', 'Hey Banco', 'BanCoppel'] as $banco) {
            $this->assertStringContainsString($banco, $plano);
        }

        // La CSP solo permite script-src 'self': el select no trae <script>.
        $this->assertStringNotContainsString('<script', strtolower($html));

        // El código vive en un archivo de resources/js y se registra en app.js.
        $this->assertFileExists(resource_path('js/select-banco.js'));
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import fpSelectBanco from './select-banco';", $app);
        $this->assertStringContainsString("Alpine.data('fpSelectBanco', fpSelectBanco)", $app);
    }

    public function test_el_banco_guardado_que_no_esta_en_la_lista_llega_al_select_como_opcion(): void
    {
        $this->comoPanel(self::ADMIN);
        $this->config()->update(['banco' => 'Mi banco de antes']);

        $this->assertStringContainsString(
            'Mi banco de antes',
            html_entity_decode(Livewire::test('distribuidora.datos-bancarios')->html())
        );
    }
    public function test_sin_datos_la_seccion_pide_capturarlos_y_al_guardarlos_la_nota_se_quita(): void
    {
        $this->comoPanel(self::ADMIN);

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertOk()
            ->assertSee('Captura tus datos bancarios para aceptar transferencias.')
            ->assertSee('Faltan datos')
            ->assertSee('data-aviso-faltan-datos', false);

        $this->guardarCuenta();

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertOk()
            ->assertDontSee('Captura tus datos bancarios para aceptar transferencias.')
            ->assertDontSee('data-aviso-faltan-datos', false)
            ->assertSee('Disponible');
    }

    public function test_sin_datos_la_opcion_no_aparece_al_cobrar_un_pedido_ni_en_el_mostrador(): void
    {
        $this->assertTrue($this->config()->acepta_transferencias);
        $this->assertNull($this->config()->clabe);

        [$pedidoId] = $this->pedidoConSaldo();

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('<option value="efectivo">', false)
            ->assertDontSee('<option value="transferencia">', false)
            ->assertDontSee('data-transferencia-spei', false);

        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('<option value="efectivo">', false)
            ->assertDontSee('<option value="transferencia">', false);

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->assertJsonPath('data.transferencia', null);

        // Con datos incompletos tampoco.
        $this->config()->update(['banco' => 'BBVA', 'clabe' => self::CLABE, 'titular_cuenta' => null]);

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertDontSee('<option value="transferencia">', false);
        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertDontSee('<option value="transferencia">', false);
    }

    public function test_sin_datos_el_backend_rechaza_el_cobro_por_transferencia(): void
    {
        [$pedidoId] = $this->pedidoConSaldo();

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'saldo_pedido',
            'metodo' => 'transferencia',
            'monto' => 10,
        ])->assertStatus(422)
            ->assertJsonPath('errors.metodo.0', 'Esta distribuidora no acepta transferencias por el momento.');

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoTipo', 'saldo_pedido')
            ->set('pagoMetodo', 'transferencia')
            ->set('pagoMonto', '10')
            ->call('registrarPago')
            ->assertSet('errorMsg', 'Esta distribuidora no acepta transferencias por el momento.');

        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $antes = VentaDirecta::withoutGlobalScopes()->count();

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('metodo_pago', 'transferencia')
            ->call('cobrar')
            ->assertSet('errorMsg', 'Esta distribuidora no acepta transferencias por el momento.');

        $this->comoApi(self::EMPLEADO);
        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'transferencia',
            'lineas' => [[
                'variante_id' => $renglon['variante_id'],
                'producto_campana_id' => $renglon['producto_campana_id'],
                'cantidad' => 1,
            ]],
        ])->assertStatus(422);

        $this->assertSame($antes, VentaDirecta::withoutGlobalScopes()->count());
        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
    }

}
