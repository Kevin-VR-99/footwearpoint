<?php

namespace Tests\Feature\Sprint5;

use App\Models\Auditoria;
use App\Models\ComprobanteTransferencia;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Support\Clabe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * S5-40 a S5-46: transferencia SPEI y el bloque de Openpay.
 * El comprobante no cuenta como pago. La tienda pública no tiene pedido.
 */
class TransferenciaYOpenpayTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLABE = '012180001234567899';

    public function test_la_clabe_exige_18_digitos_y_digito_verificador(): void
    {
        $this->assertTrue(Clabe::esValida('032180000118359719'));
        $this->assertTrue(Clabe::esValida(self::CLABE));
        $this->assertFalse(Clabe::esValida('012180001234567897'));
        $this->assertFalse(Clabe::esValida('123'));
        $this->assertFalse(Clabe::esValida('ABCDEFGHIJKLMNOPQR'));
    }

    public function test_el_admin_guarda_la_clabe_audita_y_avisa(): void
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
            ->set('banco', 'BBVA')
            ->set('clabe', self::CLABE)
            ->set('titular', 'Calzados Ramírez')
            ->call('guardar')
            ->assertSet('errorMsg', '');

        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $this->assertSame(self::CLABE, $config->clabe);
        $this->assertSame('BBVA', $config->banco);

        $this->assertDatabaseHas('auditorias', [
            'accion' => 'datos_bancarios.actualizados',
            'entidad_tipo' => 'configuracion_distribuidora',
            'entidad_id' => $config->id,
        ]);

        $auditoria = Auditoria::query()->where('accion', 'datos_bancarios.actualizados')->firstOrFail();
        $this->assertNull($auditoria->datos_previos['clabe']);
        $this->assertSame(self::CLABE, $auditoria->datos_nuevos['clabe']);

        Mail::assertSent(\App\Mail\CambioClabeMail::class, function ($mail) {
            return $mail->hasTo(self::ADMIN)
                && str_contains($mail->mensaje, '7899')
                && ! str_contains($mail->mensaje, self::CLABE);
        });
    }

    public function test_el_empleado_no_entra_a_metodos_de_pago(): void
    {
        $this->comoPanel(self::EMPLEADO);

        $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertForbidden();
    }

    public function test_sin_datos_no_se_muestra_la_cuenta_y_con_datos_si(): void
    {
        $pedidoId = $this->pedidoDeMaria();

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")
            ->assertOk()
            ->assertJsonPath('data.transferencia', null);

        $this->guardarCuenta();

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")
            ->assertOk()
            ->assertJsonPath('data.transferencia.clabe', self::CLABE)
            ->assertJsonPath('data.transferencia.banco', 'BBVA')
            ->assertJsonPath('data.transferencia.titular', 'Calzados Ramírez')
            ->assertJsonPath('data.transferencia.concepto', Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->folio)
            ->assertJsonPath('data.transferencia.aviso', 'FootwearPoint no recibe ni custodia estos pagos')
            ->assertJsonPath('data.transferencia.comprobante', null);

        $this->comoApi(self::JOSE);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertNotFound();

        $this->comoPanel(self::ADMIN);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee(self::CLABE)
            ->assertSee('FootwearPoint no recibe ni custodia estos pagos');
    }

    public function test_el_comprobante_no_cuenta_como_pago_y_solo_lo_ven_la_distribuidora_y_su_cliente(): void
    {
        Storage::fake(ComprobanteTransferencia::DISCO);
        $pedidoId = $this->pedidoDeMaria();
        $this->guardarCuenta();

        $this->comoApi(self::MARIA);
        $saldoAntes = (float) $this->getJson("/api/pedidos/{$pedidoId}")->json('data.saldo');

        $this->comoApi(self::JOSE);
        $this->post('/api/pedidos/'.$pedidoId.'/comprobante-transferencia', [
            'comprobante' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf'),
        ])->assertNotFound();

        $this->comoApi(self::EMPLEADO);
        $this->post('/api/pedidos/'.$pedidoId.'/comprobante-transferencia', [
            'comprobante' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf'),
        ])->assertForbidden();

        $this->comoApi(self::MARIA);
        $this->post('/api/pedidos/'.$pedidoId.'/comprobante-transferencia', [
            'comprobante' => UploadedFile::fake()->create('nota.html', 10, 'text/html'),
        ])->assertStatus(422);

        $this->post('/api/pedidos/'.$pedidoId.'/comprobante-transferencia', [
            'comprobante' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf'),
        ])->assertStatus(422);

        $this->post('/api/pedidos/'.$pedidoId.'/comprobante-transferencia', [
            'comprobante' => UploadedFile::fake()->create('recibo.pdf', 40, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('message', 'Comprobante recibido. El pago cuenta cuando la distribuidora lo confirma.');

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
        $this->assertEqualsWithDelta(
            $saldoAntes,
            (float) $this->getJson("/api/pedidos/{$pedidoId}")->json('data.saldo'),
            0.001
        );
        $this->assertSame(1, ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());

        $this->get('/api/pedidos/'.$pedidoId.'/comprobante-transferencia')->assertOk();

        $this->comoApi(self::JOSE);
        $this->get('/api/pedidos/'.$pedidoId.'/comprobante-transferencia')->assertNotFound();

        $this->comoApi(self::OTRA_ADMIN);
        $this->get('/api/pedidos/'.$pedidoId.'/comprobante-transferencia')->assertNotFound();

        $this->comoPanel(self::EMPLEADO);
        $this->get(route('pedidos.comprobante-transferencia', $pedidoId))->assertOk();

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'saldo_pedido',
            'metodo' => 'transferencia',
            'monto' => $saldoAntes,
            'referencia' => 'SPEI-123',
        ])->assertCreated();

        $this->assertSame(1, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->where('metodo', 'transferencia')->count());
        $this->comoApi(self::MARIA);
        $this->assertEqualsWithDelta(0, (float) $this->getJson("/api/pedidos/{$pedidoId}")->json('data.saldo'), 0.001);
    }

    public function test_la_pestana_se_llama_metodos_de_pago_y_openpay_queda_informativo(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = $this->get(route('distribuidora.configuracion', ['pestana' => 'mercado-pago']))
            ->assertOk()
            ->assertSee('Métodos de pago')
            ->assertSee('Anticipos, métodos de pago y suscripción.')
            ->assertSee('¿Quieres recibir tus cobros en otro banco?')
            ->assertSee('Conoce Openpay')
            ->assertSee('Próximamente')
            ->getContent();

        $this->assertStringContainsString('data-pestana-config="mercado-pago"', $html);
        $this->assertStringContainsString('aria-label="Mercado Pago"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('data-solicitar-openpay', $html);

        Livewire::test('distribuidora.mercado-pago')
            ->call('abrirOpenpay')
            ->assertSee('el contrato es entre tu distribuidora y Openpay', false)
            ->assertSee('FootwearPoint no recibe ni custodia tu dinero')
            ->assertSee('no ofrece asesoría fiscal')
            ->assertSee('Los detalles están por definir.');
    }

    public function test_el_aviso_y_los_terminos_mencionan_la_transferencia(): void
    {
        $this->get(route('aviso-privacidad'))
            ->assertOk()
            ->assertSee('la CLABE y el titular')
            ->assertSee('FootwearPoint no recibe ni custodia ese dinero');

        $this->get(route('terminos'))
            ->assertOk()
            // TG-321: redacción de los términos 1.3.
            ->assertSee('FootwearPoint no recibe ni guarda el dinero');
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

    private function pedidoDeMaria(): int
    {
        $this->comoApi(self::MARIA);
        $maria = $this->usuario(self::MARIA);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $this->ramirez()->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }
}
