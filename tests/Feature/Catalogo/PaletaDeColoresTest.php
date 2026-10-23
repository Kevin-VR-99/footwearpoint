<?php

namespace Tests\Feature\Catalogo;

use App\Models\Color;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Catalogo\GestionarColorAction;
use App\Services\Ia\LectorDeCatalogosClaude;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-282 — La paleta de colores.
 *
 * El hueco que cerró esto: al leer el Cklass completo quedaron 12 nombres
 * comerciales sin resolver (MALVA, MAQUILLAJE, MARINO...). No era culpa de la
 * IA: la instrucción le dice «elige uno exactamente de esta lista», y la
 * lista eran ocho colores sin rosa, sin morado, sin dorado y sin marino.
 * Encima no había ninguna pantalla para agregar uno.
 *
 * Lo que de verdad importa aquí es que la paleta y la instrucción de la IA
 * son la misma lista: un color nuevo tiene que llegar hasta allá solo.
 */
class PaletaDeColoresTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    }

    private function panel()
    {
        return Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.catalogo-maestro');
    }

    // ------------------------------------------------------------------
    // Lo que trae la migración
    // ------------------------------------------------------------------

    public function test_la_paleta_trae_los_colores_que_le_faltaban_al_calzado(): void
    {
        $paleta = Color::where('activo', true)->pluck('nombre');

        // Los que dejaron 12 nombres sin resolver en el Cklass.
        foreach (['Marino', 'Rosa', 'Morado', 'Dorado', 'Multicolor'] as $color) {
            $this->assertContains($color, $paleta->all(), "Falta {$color} en la paleta.");
        }

        // Y los de siempre siguen ahí.
        foreach (['Negro', 'Blanco', 'Café', 'Beige'] as $color) {
            $this->assertContains($color, $paleta->all());
        }
    }

    // ------------------------------------------------------------------
    // Lo importante: la paleta ES la lista que ve la IA
    // ------------------------------------------------------------------

    public function test_un_color_nuevo_llega_solo_a_la_instruccion_de_la_ia(): void
    {
        $this->assertStringNotContainsString('Turquesa', LectorDeCatalogosClaude::instruccion(1, 10));

        app(GestionarColorAction::class)->crear(['nombre' => 'Turquesa', 'codigo_hex' => '#14B8A6']);

        // Sin esto, agregar colores al panel no serviría de nada para la
        // lectura: la IA seguiría eligiendo de la lista vieja.
        $this->assertStringContainsString('Turquesa', LectorDeCatalogosClaude::instruccion(1, 10));
    }

    public function test_un_color_desactivado_deja_de_ofrecersele_a_la_ia(): void
    {
        $color = Color::where('nombre', 'Verde')->firstOrFail();

        $this->assertStringContainsString('Verde', LectorDeCatalogosClaude::instruccion(1, 10));

        app(GestionarColorAction::class)->actualizar($color, ['activo' => false]);

        $this->assertStringNotContainsString('Verde', LectorDeCatalogosClaude::instruccion(1, 10));
    }

    // ------------------------------------------------------------------
    // La pantalla
    // ------------------------------------------------------------------

    public function test_el_admin_general_puede_agregar_un_color_desde_el_panel(): void
    {
        $this->panel()
            ->set('pestana', 'colores')
            ->set('color_nombre', 'Coral')
            ->set('color_hex', '#FF7F50')
            ->call('guardarColor')
            ->assertHasNoErrors();

        $color = Color::where('nombre', 'Coral')->first();

        $this->assertNotNull($color);
        $this->assertSame('#FF7F50', $color->codigo_hex);
        $this->assertTrue($color->activo);
    }

    public function test_no_se_puede_repetir_un_color_de_la_paleta(): void
    {
        $this->panel()
            ->set('pestana', 'colores')
            ->set('color_nombre', 'Negro')
            ->call('guardarColor')
            ->assertHasErrors('color_nombre');
    }

    public function test_el_hexadecimal_es_opcional_y_lo_que_no_sirve_no_se_guarda(): void
    {
        app(GestionarColorAction::class)->crear(['nombre' => 'Sin color', 'codigo_hex' => 'no es un hex']);

        $this->assertNull(Color::where('nombre', 'Sin color')->firstOrFail()->codigo_hex);

        // Y se acepta sin el gato adelante.
        app(GestionarColorAction::class)->crear(['nombre' => 'Con gato', 'codigo_hex' => '7e22ce']);

        $this->assertSame('#7E22CE', Color::where('nombre', 'Con gato')->firstOrFail()->codigo_hex);
    }

    public function test_la_pantalla_enseña_la_pestaña_de_colores(): void
    {
        $this->panel()
            ->set('pestana', 'colores')
            ->assertSee('Marino')
            ->assertSee('Morado')
            ->assertSeeHtml('wire:click="editar(\'color\'');
    }

    // ------------------------------------------------------------------
    // Borrar y desactivar
    // ------------------------------------------------------------------

    public function test_un_color_en_uso_no_se_borra_pero_si_se_desactiva(): void
    {
        $variante = Variante::withoutGlobalScopes()->whereNotNull('color_id')->firstOrFail();
        $color = Color::findOrFail($variante->color_id);

        $this->panel()->call('pedirEliminar', 'color', $color->id)->call('confirmarEliminar');

        $this->assertDatabaseHas('colores', ['id' => $color->id]);

        // La salida buena: desactivarlo. Deja de ofrecerse, y lo que ya se
        // vendió con ese color se queda como está.
        $this->panel()->call('alternarActivo', 'color', $color->id);

        $this->assertFalse($color->refresh()->activo);
    }

    public function test_un_color_que_nadie_usa_si_se_borra(): void
    {
        $color = app(GestionarColorAction::class)->crear(['nombre' => 'Fucsia']);

        $this->panel()->call('pedirEliminar', 'color', $color->id)->call('confirmarEliminar');

        $this->assertDatabaseMissing('colores', ['id' => $color->id]);
    }

    public function test_quien_no_es_admin_general_no_entra(): void
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Livewire::actingAs($empleado)
            ->test('admin.catalogo-maestro')
            ->assertForbidden();
    }
}
