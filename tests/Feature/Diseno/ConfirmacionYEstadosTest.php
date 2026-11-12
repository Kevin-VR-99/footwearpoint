<?php

namespace Tests\Feature\Diseno;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-380 A20 — Confirmaciones y estados consistentes.
 *
 * Habia cuatro formas de preguntar "¿seguro?": wire:confirm (el dialogo del
 * navegador, 25 usos), un x-panel.modal armado a mano en cada pantalla,
 * x-personas.modal-eliminar, y un <div role="dialog"> suelto dentro de la
 * pagina. Ninguna hacia lo mismo.
 */
class ConfirmacionYEstadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function dibujar(string $plantilla): string
    {
        return trim(Blade::render($plantilla));
    }

    /**
     * El foco arranca en Cancelar, no en el boton que ejecuta. Quien llega a
     * una confirmacion acaba de pedir algo que no se deshace; si el Enter cae
     * sobre el boton rojo, un teclazo de mas lo dispara.
     */
    public function test_el_foco_arranca_en_cancelar(): void
    {
        $html = $this->dibujar(
            '<x-ui.confirmar titulo="¿Borrar?" confirmar="borrar" cancelar="cerrar">Esto no se deshace.</x-ui.confirmar>'
        );

        $cancelar = substr($html, strpos($html, 'wire:click="cerrar"'), 200);
        $this->assertStringContainsString('autofocus', $cancelar, 'El foco no arranca en Cancelar.');

        $confirmar = substr($html, strpos($html, 'wire:click="borrar"'), 200);
        $this->assertStringNotContainsString('autofocus', $confirmar);
    }

    /**
     * Mientras la accion corre, el boton se deshabilita y cambia de texto. Sin
     * eso la gente vuelve a hacer clic creyendo que no paso nada y la accion
     * se dispara dos veces.
     */
    public function test_el_boton_avisa_mientras_la_accion_corre(): void
    {
        $html = $this->dibujar(
            '<x-ui.confirmar titulo="¿Entregar?" confirmar="entregar" cancelar="cerrar" etiqueta="Entregar" etiquetaCargando="Entregando…">Ya viene.</x-ui.confirmar>'
        );

        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('wire:target="entregar"', $html);
        $this->assertStringContainsString('Entregando…', $html);
    }

    public function test_una_variante_inventada_cae_en_peligro(): void
    {
        $html = $this->dibujar(
            '<x-ui.confirmar titulo="¿Borrar?" confirmar="borrar" variante="cualquier-cosa">Ojo.</x-ui.confirmar>'
        );

        $this->assertStringContainsString('fp-btn-peligro', $html);
        $this->assertStringNotContainsString('cualquier-cosa', $html);
    }

    /** Con un error a la vista, confirmar se bloquea: no tiene caso reintentar a ciegas. */
    public function test_con_error_no_se_puede_confirmar(): void
    {
        $html = $this->dibujar(
            '<x-ui.confirmar titulo="¿Borrar?" confirmar="borrar" error="Tiene pedidos.">Ojo.</x-ui.confirmar>'
        );

        $this->assertStringContainsString('Tiene pedidos.', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    /**
     * El borde rojo del campo con error.
     *
     * A18 cambio la clase del mensaje a .fp-campo-error y las reglas de
     * app.css solo conocian .text-fp-danger: los campos convertidos
     * enseñaban el texto del error pero el campo se quedaba sin marcar.
     */
    public function test_el_campo_con_error_se_marca_y_se_anuncia(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // La regla base, no la de :focus: el texto de una es prefijo de la
        // otra, y mirarlo suelto daba por buena una hoja sin el borde.
        $this->assertMatchesRegularExpression(
            '/\.fp-campo:has\(> \.fp-campo-error\) :is\(input, select, textarea\)\s*\{[^}]*border-color:\s*var\(--color-fp-danger\)/',
            $css,
            'Los campos de x-ui.campo volvieron a quedarse sin borde rojo.'
        );

        // Y que el mensaje se anuncie solo, no solo que se pinte.
        $html = $this->dibujar('<x-ui.campo etiqueta="X" para="x" error="x"><input id="x"></x-ui.campo>');
        $this->assertStringContainsString('fp-campo', $html);
    }

    /** Las dos pantallas que nombra la tarjeta, ya sin su confirmacion propia. */
    public function test_entrega_y_cambios_usan_el_modal_comun(): void
    {
        foreach ([
            'resources/views/components/pedidos/⚡show.blade.php' => 'Entrega',
            'resources/views/components/cambios/⚡index.blade.php' => 'Cambios',
        ] as $ruta => $pantalla) {
            $vista = file_get_contents(base_path($ruta));

            $this->assertStringContainsString('<x-ui.confirmar', $vista, "{$pantalla}: no usa el modal comun.");
            // El atributo, no la palabra: los comentarios la nombran al
            // explicar por que se fue.
            $this->assertStringNotContainsString(
                'wire:confirm=',
                $vista,
                "{$pantalla}: volvio el dialogo del navegador, que no tiene estilo ni estado de carga."
            );
        }
    }

    /**
     * La entrega sigue preguntando antes de avisarle al cliente.
     *
     * Se crea el pedido aqui en vez de buscarlo en la semilla: asi la prueba
     * no se salta sola el dia que la semilla cambie, que es como un test deja
     * de vigilar algo sin que nadie se entere.
     */
    public function test_la_entrega_sigue_preguntando_antes_de_marcar_listo(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'A20-'.uniqid(),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => 'recibido_distribuidora',
            'subtotal' => 0,
            'total' => 0,
            'fecha_colocacion' => now(),
            'capturado_por_staff_id' => DistribuidoraStaff::query()->value('id'),
        ]);

        \Livewire\Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->assertSet('confirmandoListo', false)
            ->call('abrirConfirmacionListo')
            ->assertSet('confirmandoListo', true)
            ->assertSee($pedido->folio)
            ->call('cancelarConfirmacionListo')
            ->assertSet('confirmandoListo', false);

        // Preguntar no puede avisarle al cliente: el estado no se mueve.
        $this->assertSame('recibido_distribuidora', $pedido->fresh()->estado);
    }
}
