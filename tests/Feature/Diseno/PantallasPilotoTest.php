<?php

namespace Tests\Feature\Diseno;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-378 A18 — Las tres pantallas piloto.
 *
 * El "listo cuando" de la tarjeta es "mismos componentes en >= 3 pantallas
 * piloto". Esto lo fija: si alguien deshace una pantalla y vuelve a escribir
 * el campo a mano, la prueba lo dice en vez de que se descubra meses despues
 * al ver que los formularios volvieron a verse distintos entre si.
 *
 * Se mira el HTML dibujado y no el archivo: lo que importa es que al usuario
 * le llegue el mismo campo, no como este escrito.
 */
class PantallasPilotoTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Las marcas que deja el sistema de diseno en cualquier pantalla que lo use. */
    private function assertUsaElSistema(string $html, string $pantalla): void
    {
        $this->assertStringContainsString('fp-campo-apilado', $html, "{$pantalla}: no usa x-ui.campo.");
        $this->assertStringContainsString('fp-control', $html, "{$pantalla}: no usa x-ui.entrada ni x-ui.selector.");
    }

    public function test_el_catalogo_maestro_usa_el_sistema(): void
    {
        Tenant::olvidarCache();
        $this->actingAs($this->adminGeneral());
        Tenant::olvidarCache();

        $html = Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->html();

        $this->assertUsaElSistema($html, 'Catálogo maestro');
    }

    public function test_solicitar_alta_usa_el_sistema(): void
    {
        // Publica, sin sesion: es por donde entra una distribuidora nueva.
        $html = $this->get('/solicitar-alta')->assertOk()->getContent();

        $this->assertUsaElSistema($html, 'Solicitar alta');

        // Esta pantalla pintaba el error con text-red-600, que ni siquiera es
        // el rojo de la casa. Que no vuelva.
        $this->assertStringNotContainsString('text-red-600', $html, 'Volvió un rojo que no es de la casa.');
    }

    public function test_los_usuarios_de_la_distribuidora_usan_el_sistema(): void
    {
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        // El formulario esta oculto hasta que se pide: sin abrirlo no se
        // dibuja ningun campo y la prueba no miraria nada.
        $html = Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioInvitarEmpleado')
            ->html();

        $this->assertUsaElSistema($html, 'Usuarios de la distribuidora');
    }
}
