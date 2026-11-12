<?php

namespace Tests\Feature\Publico;

use App\Http\Middleware\CobroInicialPendiente;
use App\Models\Usuario;
use App\Models\Vale;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-321 (K22) — Términos y condiciones alineados al sistema.
 *
 * Borrador que pasará por revisión jurídica: estas pruebas cuidan que diga lo
 * que hace el sistema y que no adelante lo que es de K41 (vencimiento,
 * gracia y suspensión) ni afirme que ya cumple la ley.
 */
class TerminosTG321Test extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const SECCIONES = [
        'terminos-que-es', 'terminos-cuentas', 'terminos-mensualidad', 'terminos-pagos',
        'terminos-pedidos', 'terminos-vales', 'terminos-contenido', 'terminos-responsabilidad',
        'terminos-cambios', 'terminos-ley', 'terminos-contacto',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function html(): string
    {
        return $this->get(route('terminos'))->assertOk()->getContent();
    }

    /** El texto como lo lee la persona: sin etiquetas y con espacios simples. */
    private function texto(): string
    {
        $texto = html_entity_decode(strip_tags($this->html()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private function assertDice(string ...$frases): void
    {
        $texto = $this->texto();

        foreach ($frases as $frase) {
            $this->assertStringContainsString($frase, $texto, "Los términos deberían decir «{$frase}»");
        }
    }

    private function assertNoDice(string ...$frases): void
    {
        $texto = mb_strtolower($this->texto());

        foreach ($frases as $frase) {
            $this->assertStringNotContainsString(mb_strtolower($frase), $texto, "Los términos no deberían decir «{$frase}»");
        }
    }

    public function test_el_indice_enlaza_a_cada_seccion_y_cada_seccion_existe(): void
    {
        $html = $this->html();

        foreach (self::SECCIONES as $id) {
            $this->assertStringContainsString('href="#'.$id.'"', $html, "Falta en el índice: {$id}");
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "La sección {$id} no aparece una sola vez");
        }
    }

    public function test_explica_los_cuatro_roles_y_que_la_plataforma_no_vende(): void
    {
        $this->assertDice(
            'La plataforma (FootwearPoint)',
            'La distribuidora contrata la plataforma',
            'El cliente mayorista compra calzado a una distribuidora para revenderlo',
            'El cliente directo compra calzado a una distribuidora para sí mismo',
            'FootwearPoint no es el vendedor final del calzado',
        );
        $this->assertNoDice('revendedor');
    }

    public function test_pide_mayoria_de_edad(): void
    {
        $this->assertDice('Debes ser mayor de edad (18 años o más)');
    }

    /** El primer mes antes del panel: es lo que hace CobroInicialPendiente. */
    public function test_el_primer_mes_se_paga_antes_de_usar_el_panel(): void
    {
        $this->assertTrue(class_exists(CobroInicialPendiente::class));
        $this->assertDice('El primer mes se paga antes de usar el panel.');
    }

    public function test_renovacion_y_cancelacion_sin_cobros_automaticos(): void
    {
        $this->assertDice(
            'No hay cobros automáticos',
            'Para no renovar basta con no pagar el mes siguiente',
            'Para cancelar el servicio',
        );
    }

    public function test_conserva_los_planes_y_la_linea_extra(): void
    {
        $this->assertDice('$299 MXN', '$599 MXN', '$999 MXN', '$150 MXN');
    }

    public function test_trae_la_clausula_sin_cfdi(): void
    {
        $this->assertDice('no emite factura electrónica (CFDI) por la mensualidad ni cobra IVA');
    }

    public function test_formas_de_pago_y_que_la_plataforma_no_recibe_el_dinero(): void
    {
        $this->assertDice('Mercado Pago:', 'Transferencia:', 'En la sucursal:', 'FootwearPoint no recibe ni guarda el dinero');
    }

    public function test_licencia_coherente_con_el_archivo_license(): void
    {
        $licencia = (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(base_path('LICENSE')));
        $this->assertStringContainsString('limitada, no exclusiva, intransferible y revocable', $licencia);

        $this->assertDice(
            'todos los derechos reservados',
            'limitada, no exclusiva, intransferible y revocable',
            'declara que tiene derecho a usarlos',
        );
    }

    public function test_reglas_de_la_nota_de_cambio(): void
    {
        $this->assertDice(
            'Su importe es lo que realmente pagaste por lo que devuelves.',
            'Puedes devolver solo una parte del pedido',
            'Solo se usa con la distribuidora que la emitió, en un pedido tuyo, completa y en un solo pedido.',
            'No se usa en Venta local',
        );
    }

    /** La vigencia de la nota la define cada distribuidora; el texto no fija un número. */
    public function test_la_vigencia_de_la_nota_la_define_la_distribuidora(): void
    {
        $this->assertDice('Su vigencia la define tu distribuidora; la fecha de vencimiento aparece en cada nota.');
        $this->assertNoDice('60 días');
    }

    /** El vale de sobrante es fijo: el número sale de la misma constante que usa el sistema. */
    public function test_el_sobrante_dice_los_dias_con_que_lo_genera_el_sistema(): void
    {
        $this->assertDice(
            'Si el pedido cuesta menos que la nota, la nota se usa completa y la diferencia queda en un vale nuevo a tu favor, que vence '
            .Vale::DIAS_VIGENCIA_TIPIFICADO.' días después de generarse.'
        );
    }

    /** Vencimiento, gracia y suspensión son de K41 (términos v1.3). */
    public function test_no_adelanta_lo_que_es_de_k41(): void
    {
        $this->assertNoDice('gracia', 'suspend', 'suspensión', 'vencida');
    }

    public function test_no_afirma_que_cumple_la_ley(): void
    {
        $this->assertNoDice('en cumplimiento', 'cumple con', 'certificad', '76 Bis');
    }

    public function test_la_pantalla_de_aceptar_muestra_los_dos_documentos_sin_ids_repetidos(): void
    {
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $html = Livewire::test('auth.aceptar-legales')->html();

        foreach (self::SECCIONES as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "La sección {$id} no aparece una sola vez");
        }
    }
}
