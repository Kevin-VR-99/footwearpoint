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
 * pruebas cuidan es que no haya dos versiones del texto y que la versión
 * vigente (1.1, config/legal.php) sea la que se muestra y se guarda. Las
 * aceptaciones de la 1.0 se conservan y no obligan a aceptar otra vez.
 */
class DocumentosLegalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Un pedazo de cada documento, para reconocerlo en el HTML. */
    private const DEL_AVISO = 'No vendemos ni rentamos tus datos.';

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
            ->assertSee('Versión 1.1')
            ->assertSee('24 de octubre de 2026')
            ->assertSee(self::DEL_AVISO)
            ->assertSee('Click & Collect', false);
    }

    public function test_los_terminos_abren_sin_sesion(): void
    {
        $this->assertGuest();

        $this->get(route('terminos'))
            ->assertOk()
            ->assertSee('Términos y condiciones')
            ->assertSee('Versión 1.1')
            ->assertSee('24 de octubre de 2026')
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

    public function test_aceptar_los_dos_documentos_guarda_la_version_1_1(): void
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
                'version' => '1.1',
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

    // ------------------------------------------------------------------
    // Versión 1.1: contenido mínimo y datos que salen del entorno
    // ------------------------------------------------------------------

    public function test_el_aviso_1_1_trae_los_apartados_de_la_lfpdppp(): void
    {
        $this->get(route('aviso-privacidad'))->assertOk()
            ->assertSee('Identidad y domicilio del responsable')
            ->assertSee('Finalidades primarias')
            ->assertSee('Finalidades secundarias')
            ->assertSee('Mercado Pago')
            ->assertSee('Railway')
            ->assertSee('Cloudflare')
            ->assertSee('Firebase')
            ->assertSee('inteligencia artificial')
            ->assertSee('Derechos ARCO')
            ->assertSee('Revocación del consentimiento')
            ->assertSee('Cookies')
            ->assertSee('Cambios a este aviso')
            ->assertSee('Secretaría Anticorrupción y Buen Gobierno')
            ->assertSee('footwearpoint.mx@gmail.com');
    }

    public function test_los_terminos_1_1_traen_planes_licencia_y_jurisdiccion(): void
    {
        $this->get(route('terminos'))->assertOk()
            ->assertSee('$299 MXN', false)
            ->assertSee('$599 MXN', false)
            ->assertSee('$999 MXN', false)
            ->assertSee('$150 MXN', false)
            ->assertSee('todos los derechos reservados')
            ->assertSee('Ley aplicable y jurisdicción');
    }

    public function test_sin_variables_legal_se_muestra_texto_neutro(): void
    {
        config([
            'legal.domicilio_responsable' => null,
            'legal.contacto_arco' => null,
            'legal.jurisdiccion' => null,
        ]);

        $this->get(route('aviso-privacidad'))->assertOk()
            ->assertSee('Para conocer el domicilio del responsable')
            ->assertDontSee('Domicilio del responsable:');
        $this->get(route('terminos'))->assertOk()
            ->assertSee('los tribunales competentes que correspondan conforme a la ley');
    }

    public function test_con_variables_legal_se_muestran_sus_valores(): void
    {
        config([
            'legal.domicilio_responsable' => 'Calle Prueba 123, Ciudad de Prueba',
            'legal.contacto_arco' => 'el Equipo de Privacidad',
            'legal.jurisdiccion' => 'los tribunales de Ciudad de Prueba',
        ]);

        $this->get(route('aviso-privacidad'))->assertOk()
            ->assertSee('Domicilio del responsable: Calle Prueba 123, Ciudad de Prueba.')
            ->assertSee('el Equipo de Privacidad');
        $this->get(route('terminos'))->assertOk()
            ->assertSee('los tribunales de Ciudad de Prueba');
    }

    /**
     * Quien ya aceptó la 1.0 no queda bloqueado: la API sigue diciendo
     * legales_aceptados = true (no hay re-aceptación forzada).
     */
    public function test_quien_acepto_la_1_0_no_tiene_que_aceptar_otra_vez(): void
    {
        $empleado = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        AceptacionLegal::where('usuario_id', $empleado->id)->delete();
        foreach (['aviso_privacidad', 'terminos_condiciones'] as $tipo) {
            AceptacionLegal::create([
                'usuario_id' => $empleado->id,
                'tipo_documento' => $tipo,
                'version' => '1.0',
                'fecha_aceptacion' => now(),
                'ip_origen' => '127.0.0.1',
            ]);
        }

        $token = $empleado->createToken('prueba')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.legales_aceptados', true);
    }

    /** La API sin versión explícita guarda la vigente (1.1). */
    public function test_la_api_sin_version_guarda_la_vigente(): void
    {
        $empleado = $this->empleado();
        $token = $empleado->createToken('prueba')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/auth/aceptar-legales')->assertSuccessful();

        $this->assertDatabaseHas('aceptaciones_legales', [
            'usuario_id' => $empleado->id,
            'tipo_documento' => 'aviso_privacidad',
            'version' => '1.1',
        ]);
    }
}
