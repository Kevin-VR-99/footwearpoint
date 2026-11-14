<?php

namespace Tests\Feature\Publico;

use App\Services\Suscripcion\CalendarioVencimiento;
use App\Support\DocumentosLegales;
use Tests\TestCase;

/**
 * TG-339 (K41) — Vencimiento, gracia y cancelación de la mensualidad.
 *
 * El texto describe lo que hace suscripciones:vencer (PR #321). Los números
 * salen del mismo código que usa el comando, así que si cambian, estas
 * pruebas piden corregir los términos (y subir su versión).
 */
class TerminosTG339Test extends TestCase
{
    /** El texto como lo lee la persona: sin etiquetas y con espacios simples. */
    private function texto(): string
    {
        $html = $this->get(route('terminos'))->assertOk()->getContent();
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private function assertDice(string ...$frases): void
    {
        $texto = $this->texto();

        foreach ($frases as $frase) {
            $this->assertStringContainsString($frase, $texto, "Los términos deberían decir «{$frase}»");
        }
    }

    public function test_es_la_version_1_4_y_tiene_su_seccion_en_el_indice(): void
    {
        $this->assertSame('1.4', DocumentosLegales::version(DocumentosLegales::TERMINOS));

        $html = $this->get(route('terminos'))->assertOk()->getContent();
        $this->assertStringContainsString('href="#terminos-vencimiento"', $html);
        $this->assertSame(1, substr_count($html, 'id="terminos-vencimiento"'));
        $this->assertDice('Versión 1.4', 'Vencimiento, gracia y cancelación de la mensualidad');
    }

    public function test_avisos_antes_y_el_dia_del_vencimiento(): void
    {
        $this->assertDice(
            '3 días y 1 día antes del vencimiento, y el mismo día del vencimiento por correo y en el panel',
            'Ese día la distribuidora sigue activa.',
        );
    }

    /** Los 5 días salen de CalendarioVencimiento::DIAS_GRACIA, el número que usa el comando. */
    public function test_gracia_con_los_dias_del_comando(): void
    {
        $dias = CalendarioVencimiento::DIAS_GRACIA;

        $this->assertSame(5, $dias);
        $this->assertDice(
            "durante los {$dias} días siguientes al vencimiento, la mensualidad queda vencida, pero la distribuidora sigue operando con normalidad",
        );
    }

    /** Hoy el comando solo avisa: la suspensión es algo que PUEDE pasar. */
    public function test_la_suspension_se_redacta_como_posible(): void
    {
        $this->assertSame('aviso', config('suscripcion.vencimiento'));
        $this->assertDice(
            'a partir del sexto día sin pago, FootwearPoint podrá suspender a la distribuidora',
            'su personal solo puede entrar a la pantalla de pago del panel hasta que se pague',
        );
        $this->assertStringNotContainsString('será suspendida', mb_strtolower($this->texto()));
    }

    public function test_como_cambia_el_vencimiento_al_pagar_y_la_reactivacion(): void
    {
        $this->assertDice(
            'el nuevo vencimiento es el anterior más un mes',
            'el nuevo vencimiento es la fecha en que se aplica el pago más un mes',
            'del 31 de enero pasa al 28 de febrero, o al 29 si el año es bisiesto',
            'al aplicarse el pago se reactiva',
            'no se levanta con un pago',
            'La app móvil no cobra la mensualidad.',
        );
    }

    /** Cancelación: lo que hay hoy, sin afirmar que cumple el art. 76 Bis ni inventar reembolsos. */
    public function test_cancelacion_sin_reglas_inventadas(): void
    {
        $this->assertDice(
            'Para no renovar basta con no pagar el mes siguiente',
            'Hoy no hay un botón para cancelar desde el panel.',
        );

        $texto = mb_strtolower($this->texto());
        foreach (['reembols', 'devolvemos la mensualidad', '76 bis', 'en cumplimiento', 'cumple con'] as $frase) {
            $this->assertStringNotContainsString($frase, $texto, "Los términos no deberían decir «{$frase}»");
        }
    }

    public function test_ya_no_dice_que_el_vencimiento_va_en_otra_version(): void
    {
        $archivo = (string) file_get_contents(resource_path('views/components/legales/terminos.blade.php'));

        $this->assertStringNotContainsString('NO van', $archivo);
        $this->assertStringNotContainsString('son de K41', $archivo);
    }
}
