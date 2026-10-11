<?php

namespace Tests\Feature\Publico;

use App\Models\AceptacionLegal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-219 (E15-04) — El aviso de privacidad y los términos se pueden leer sin
 * cuenta, y la pantalla de aceptar sigue funcionando igual.
 *
 * El texto de los dos documentos vive ahora en los parciales x-legales.*, que
 * usan tanto las páginas públicas como la pantalla de aceptar. Lo que estas
 * pruebas cuidan es que no haya dos versiones del texto y que la versión 1.0
 * no se haya movido: las aceptaciones ya guardadas apuntan a ella.
 */
class DocumentosLegalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Un pedazo de cada documento, para reconocerlo en el HTML. */
    private const DEL_AVISO = 'No se venden datos a terceros.';

    private const DE_LOS_TERMINOS = 'FootwearPoint no es el vendedor final del calzado';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function empleado(): Usuario
    {
        return Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Las dos páginas, sin sesión
    // ------------------------------------------------------------------

    public function test_el_aviso_de_privacidad_abre_sin_sesion(): void
    {
        $this->assertGuest();

        $this->get(route('aviso-privacidad'))
            ->assertOk()
            ->assertSee('Aviso de privacidad')
            ->assertSee('Versión 1.0')
            ->assertSee(self::DEL_AVISO)
            ->assertSee('Click & Collect', false);
    }

    public function test_los_terminos_abren_sin_sesion(): void
    {
        $this->assertGuest();

        $this->get(route('terminos'))
            ->assertOk()
            ->assertSee('Términos y condiciones')
            ->assertSee('Versión 1.0')
            ->assertSee(self::DE_LOS_TERMINOS);
    }

    public function test_cada_documento_enlaza_al_otro_y_al_inicio(): void
    {
        $this->get(route('aviso-privacidad'))->assertOk()
            ->assertSee(route('terminos'), false)
            ->assertSee(route('inicio'), false);

        $this->get(route('terminos'))->assertOk()
            ->assertSee(route('aviso-privacidad'), false)
            ->assertSee(route('inicio'), false);
    }

    /** Cada página muestra lo suyo, no el otro documento. */
    public function test_cada_pagina_muestra_solo_su_documento(): void
    {
        $this->get(route('aviso-privacidad'))->assertOk()->assertDontSee(self::DE_LOS_TERMINOS);
        $this->get(route('terminos'))->assertOk()->assertDontSee(self::DEL_AVISO);
    }

    // ------------------------------------------------------------------
    // La pantalla de aceptar se comporta igual que antes
    // ------------------------------------------------------------------

    public function test_la_pantalla_de_aceptar_sigue_mostrando_los_dos_textos(): void
    {
        $this->actingAs($this->empleado());

        Livewire::test('auth.aceptar-legales')
            ->assertSee(self::DEL_AVISO)
            ->assertSee(self::DE_LOS_TERMINOS);
    }

    public function test_aceptar_los_dos_documentos_sigue_guardando_la_version_1_0(): void
    {
        $empleado = $this->empleado();
        $this->actingAs($empleado);

        Livewire::test('auth.aceptar-legales')
            ->set('aviso', true)
            ->set('terminos', true)
            ->call('aceptar')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Aceptaste: Aviso de privacidad, Términos y condiciones.');

        foreach (['aviso_privacidad', 'terminos_condiciones'] as $tipo) {
            $this->assertDatabaseHas('aceptaciones_legales', [
                'usuario_id' => $empleado->id,
                'tipo_documento' => $tipo,
                'version' => '1.0',
            ]);
        }
    }

    public function test_aceptar_solo_uno_guarda_solo_ese(): void
    {
        $empleado = $this->empleado();
        $this->actingAs($empleado);

        Livewire::test('auth.aceptar-legales')
            ->set('aviso', true)
            ->call('aceptar')
            ->assertSet('mensaje', 'Aceptaste: Aviso de privacidad.');

        $this->assertDatabaseHas('aceptaciones_legales', [
            'usuario_id' => $empleado->id,
            'tipo_documento' => 'aviso_privacidad',
        ]);
        $this->assertDatabaseMissing('aceptaciones_legales', [
            'usuario_id' => $empleado->id,
            'tipo_documento' => 'terminos_condiciones',
        ]);
    }

    public function test_sin_marcar_nada_sigue_avisando_y_no_guarda(): void
    {
        $empleado = $this->empleado();
        $this->actingAs($empleado);

        Livewire::test('auth.aceptar-legales')
            ->call('aceptar')
            ->assertHasErrors('aviso');

        $this->assertSame(0, AceptacionLegal::where('usuario_id', $empleado->id)->count());
    }

    public function test_aceptar_dos_veces_no_duplica_la_aceptacion(): void
    {
        $empleado = $this->empleado();
        $this->actingAs($empleado);

        foreach ([1, 2] as $vez) {
            Livewire::test('auth.aceptar-legales')
                ->set('aviso', true)
                ->call('aceptar')
                ->assertHasNoErrors();
        }

        $this->assertSame(
            1,
            AceptacionLegal::where('usuario_id', $empleado->id)
                ->where('tipo_documento', 'aviso_privacidad')
                ->count()
        );
    }
}
