<?php

namespace Tests\Feature\Publico;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-322 (K23) — Aviso de privacidad alineado al sistema.
 *
 * Es un borrador que pasará por revisión jurídica, así que estas pruebas
 * cuidan sobre todo lo que NO debe decir: que cumple la ley, la autoridad
 * vieja, proveedores sin comprobar, plazos o personas que nadie ha definido.
 */
class AvisoPrivacidadTG322Test extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const SECCIONES = [
        'aviso-responsable', 'aviso-datos', 'aviso-finalidades', 'aviso-actividad',
        'aviso-tienda', 'aviso-cookies', 'aviso-proveedores', 'aviso-arco',
        'aviso-limitar', 'aviso-seguridad', 'aviso-cambios', 'aviso-autoridad',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function aviso(): string
    {
        return $this->get(route('aviso-privacidad'))->assertOk()->getContent();
    }

    /** El texto como lo lee la persona: sin etiquetas y con espacios simples. */
    private function texto(): string
    {
        $texto = html_entity_decode(strip_tags($this->aviso()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private function assertDice(string ...$frases): void
    {
        $texto = $this->texto();

        foreach ($frases as $frase) {
            $this->assertStringContainsString($frase, $texto, "El aviso debería decir «{$frase}»");
        }
    }

    private function assertNoDice(string ...$frases): void
    {
        $texto = $this->texto();

        foreach ($frases as $frase) {
            $this->assertStringNotContainsString($frase, $texto, "El aviso no debería decir «{$frase}»");
        }
    }

    public function test_el_indice_enlaza_a_cada_seccion_y_cada_seccion_existe(): void
    {
        $html = $this->aviso();

        foreach (self::SECCIONES as $id) {
            $this->assertStringContainsString('href="#'.$id.'"', $html, "Falta en el índice: {$id}");
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "La sección {$id} no aparece una sola vez");
        }
    }

    public function test_cita_la_ley_vigente_y_la_autoridad_nueva(): void
    {
        $this->assertDice('20 de marzo de 2025', '14 de noviembre de 2025', 'Secretaría Anticorrupción y Buen Gobierno');
        $this->assertNoDice('INAI', 'Instituto Nacional de Transparencia');
    }

    public function test_no_afirma_que_cumple_la_ley_ni_que_esta_certificado(): void
    {
        $html = mb_strtolower($this->texto());

        foreach (['en cumplimiento', 'cumple con', 'certificad'] as $frase) {
            $this->assertStringNotContainsString($frase, $html, "El aviso no debe decir «{$frase}»");
        }
    }

    public function test_distingue_la_plataforma_de_cada_distribuidora(): void
    {
        $this->assertDice('es la plataforma tecnológica', 'es un negocio independiente', 'por cuenta de la distribuidora');
    }

    public function test_habla_de_clientes_mayoristas_y_no_de_revendedores(): void
    {
        $html = mb_strtolower($this->texto());

        $this->assertStringContainsString('clientes mayoristas', $html);
        $this->assertStringNotContainsString('revendedor', $html);
    }

    public function test_incluye_los_datos_nuevos_del_sistema(): void
    {
        $this->assertDice(
            // Personal: teléfono y dirección que pedirá el alta de usuarios.
            'Personal de la distribuidora: nombre, correo, teléfono, dirección',
            'foto de perfil opcional, que puedes subir desde el panel o desde la app',
            'cookies técnicas',
            'Google Maps',
        );
    }

    public function test_el_registro_de_actividad_dice_que_guarda_y_cuanto_tiempo(): void
    {
        $this->assertDice(
            'un registro por persona, por día y por canal',
            'no guarda tu dirección IP',
            'se conserva 90 días y después se borra',
            'se conservan 24 meses',
        );
    }

    /** Solo proveedores comprobados en el código o en config. */
    public function test_nombra_solo_proveedores_comprobados(): void
    {
        $this->assertDice('Railway', 'Cloudflare R2', 'Gmail', 'Firebase Cloud Messaging', 'Anthropic', 'Mercado Pago');
        // Sin comprobar en producción: va como pendiente en el PR.
        $this->assertNoDice('Sentry', 'Monitoreo de errores');
    }

    /** ARCO: cómo se pide, sin plazos ni responsables inventados. */
    public function test_arco_explica_como_pedirlo_sin_inventar_plazos_ni_personas(): void
    {
        config(['legal.contacto_arco' => null]);

        $this->assertDice('artículo 28 de la LFPDPPP', 'dentro de los plazos que fija la LFPDPPP');
        $this->assertNoDice('20 días', '15 días', 'dirigida a');
    }

    public function test_la_version_y_la_fecha_se_ven_arriba(): void
    {
        $html = $this->aviso();

        $arriba = strpos($html, 'Última actualización: '.config('legal.fecha_actualizacion'));
        $indice = strpos($html, 'href="#aviso-responsable"');

        $this->assertNotFalse($arriba);
        $this->assertLessThan($indice, $arriba, 'La versión y la fecha van antes del índice');
    }

    /** En la pantalla de aceptar también va el índice, sin ids repetidos. */
    public function test_la_pantalla_de_aceptar_muestra_el_aviso_con_su_indice(): void
    {
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $html = Livewire::test('auth.aceptar-legales')->html();

        foreach (self::SECCIONES as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "La sección {$id} no aparece una sola vez");
        }
    }
}
