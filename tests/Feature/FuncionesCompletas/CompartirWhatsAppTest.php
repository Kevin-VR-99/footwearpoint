<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\ProductoCampana;
use App\Support\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Compartir catálogo, producto y pedido por WhatsApp (wa.me con texto). */
class CompartirWhatsAppTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_el_enlace_normaliza_el_telefono_y_codifica_el_texto(): void
    {
        $this->assertSame('https://wa.me/529631234567?text=Hola%20t%C3%BA%0A%26', WhatsApp::enlace("Hola tú\n&", '963 123 4567'));
        $this->assertSame('https://wa.me/?text=Hola', WhatsApp::enlace('Hola', 'no es teléfono'));
        $this->assertSame('5219631234567', WhatsApp::normalizarTelefono('+52 1 963 123 4567'));
    }

    public function test_la_tienda_y_la_ficha_tienen_boton_de_compartir_con_su_url(): void
    {
        $this->get('/tienda/calzados-ramirez')->assertOk()
            ->assertSee('https://wa.me/?text=', false)
            ->assertSee('Compartir catálogo');

        $pc = ProductoCampana::query()->whereRelation('campana', 'estado', 'activa')
            ->whereRelation('producto', 'modelo', 'like', '%')->get()
            ->first(fn ($p) => $this->get('/tienda/calzados-ramirez/productos/'.$p->id)->status() === 200);
        $this->assertNotNull($pc);

        $html = $this->get('/tienda/calzados-ramirez/productos/'.$pc->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-compartir="whatsapp"', $html);
        $this->assertStringContainsString(rawurlencode('/tienda/calzados-ramirez/productos/'.$pc->id), $html);
    }

    public function test_el_pedido_se_comparte_al_telefono_del_cliente_desde_panel_y_api(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $texto = $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data.compartir_texto');
        $this->assertStringContainsString('te comparto tu pedido', $texto);
        $this->assertStringContainsString('Total: $', $texto);

        $this->comoPanel(self::EMPLEADO);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('Enviar por WhatsApp')
            ->assertSeeHtml('https://wa.me/52');
    }

    public function test_escribir_por_whatsapp_solo_aparece_si_el_perfil_lo_activa(): void
    {
        $tienda = $this->get('/tienda/calzados-ramirez')->assertOk();
        $tienda->assertDontSee('data-contacto-whatsapp', false);
        $tienda->assertDontSee('>Escríbenos<', false);
        $tienda->assertSee('9631234567');

        $this->comoPanel(self::ADMIN);
        Livewire::test('distribuidora.perfil')
            ->assertSee('¿Este número es solo para llamadas')
            ->assertSet('uso_telefono', 'llamadas')
            ->set('uso_telefono', 'whatsapp')
            ->call('guardarPerfil')
            ->assertHasNoErrors()
            ->assertSet('uso_telefono', 'whatsapp');

        $this->assertTrue($this->ramirez()->fresh()->telefono_es_whatsapp);

        $this->get('/tienda/calzados-ramirez')->assertOk()
            ->assertSee('data-contacto-whatsapp', false)
            ->assertSee('Escríbenos')
            ->assertSee('https://wa.me/529631234567', false);

        $pc = ProductoCampana::query()->whereRelation('campana', 'estado', 'activa')->get()
            ->first(fn ($p) => $this->get('/tienda/calzados-ramirez/productos/'.$p->id)->status() === 200);
        $this->assertNotNull($pc);
        $this->get('/tienda/calzados-ramirez/productos/'.$pc->id)
            ->assertOk()
            ->assertSee('Preguntar por WhatsApp')
            ->assertSee('data-contacto-whatsapp', false);

        Livewire::test('distribuidora.perfil')
            ->set('uso_telefono', 'llamadas')
            ->call('guardarPerfil')
            ->assertHasNoErrors();

        $this->assertFalse($this->ramirez()->fresh()->telefono_es_whatsapp);
        $this->get('/tienda/calzados-ramirez')->assertOk()
            ->assertDontSee('data-contacto-whatsapp', false)
            ->assertDontSee('>Escríbenos<', false);
        $this->get('/tienda/calzados-ramirez/productos/'.$pc->id)
            ->assertDontSee('Preguntar por WhatsApp');
    }

    public function test_un_numero_que_whatsapp_no_abre_no_prende_el_boton(): void
    {
        $this->comoPanel(self::ADMIN);

        Livewire::test('distribuidora.perfil')
            ->set('telefono_publico', '123')
            ->set('uso_telefono', 'whatsapp')
            ->call('guardarPerfil')
            ->assertHasErrors('uso_telefono');

        $this->assertFalse($this->ramirez()->fresh()->telefono_es_whatsapp);

        Livewire::test('distribuidora.perfil')
            ->set('telefono_publico', '')
            ->set('uso_telefono', 'whatsapp')
            ->call('guardarPerfil')
            ->assertHasNoErrors()
            ->assertDontSee('¿Este número es solo para llamadas');

        $fresca = $this->ramirez()->fresh();
        $this->assertNull($fresca->telefono_publico);
        $this->assertFalse($fresca->telefono_es_whatsapp);
    }

    public function test_el_catalogo_de_la_app_trae_la_url_publica_de_cada_producto(): void
    {
        $this->comoApi(self::MARIA);

        $primero = $this->getJson('/api/catalogo')->assertOk()->json('data.0');
        $this->assertNotNull($primero);
        $this->assertStringContainsString('/productos/'.$primero['id'], (string) $primero['url_publica']);
    }
}
