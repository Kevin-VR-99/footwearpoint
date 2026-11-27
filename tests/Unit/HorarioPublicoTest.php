<?php

namespace Tests\Unit;

use App\Support\HorarioPublico;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HorarioPublicoTest extends TestCase
{
    public function test_formatea_varios_grupos_con_rangos_y_cerrado(): void
    {
        $grupos = [
            HorarioPublico::grupo(['lun', 'mar', 'mie', 'jue', 'vie'], [['09:00', '14:00'], ['16:00', '20:00']]),
            HorarioPublico::grupo(['sab'], [['10:00', '14:00']]),
            HorarioPublico::grupo(['dom'], [], true),
        ];

        $this->assertSame(
            'Lun–Vie 9:00–14:00 y 16:00–20:00 · Sáb 10:00–14:00 · Dom cerrado',
            HorarioPublico::formatear($grupos)
        );
    }

    public function test_formatea_plantilla_de_24_horas(): void
    {
        $grupos = HorarioPublico::plantillas()['24_horas']['grupos'];

        $this->assertSame('Lun–Dom, las 24 horas', HorarioPublico::formatear($grupos));
    }

    public function test_rechaza_fin_menor_o_igual_que_inicio(): void
    {
        $errores = HorarioPublico::validar([
            HorarioPublico::grupo(['lun'], [['18:00', '09:00']]),
        ]);

        $this->assertNotEmpty($errores['horario_publico'] ?? []);
        $this->assertStringContainsString('fin debe ser mayor', $errores['horario_publico'][0]);
    }

    public function test_rechaza_rangos_traslapados_en_el_mismo_grupo(): void
    {
        $errores = HorarioPublico::validar([
            HorarioPublico::grupo(['lun'], [['09:00', '14:00'], ['13:00', '18:00']]),
        ]);

        $this->assertStringContainsString('traslapan', $errores['horario_publico'][0]);
    }

    public function test_rechaza_un_dia_en_dos_grupos(): void
    {
        $errores = HorarioPublico::validar([
            HorarioPublico::grupo(['lun', 'mar'], [['09:00', '14:00']]),
            HorarioPublico::grupo(['mar', 'mie'], [['16:00', '20:00']]),
        ]);

        $this->assertStringContainsString('Mar ya está en otro horario', $errores['horario_publico'][0]);
    }

    public function test_preparar_sin_grupos_conserva_el_texto_libre(): void
    {
        $out = HorarioPublico::preparar([], 'Lunes a sábado, 9:00 a 19:00');

        $this->assertNull($out['horarios_publicos']);
        $this->assertSame('Lunes a sábado, 9:00 a 19:00', $out['horario_publico']);
    }

    public function test_preparar_con_grupos_genera_el_texto_y_el_json(): void
    {
        $grupos = [HorarioPublico::grupo(['lun', 'mar', 'mie', 'jue', 'vie'], [['09:00', '18:00']])];
        $out = HorarioPublico::preparar($grupos, 'esto se ignora');

        $this->assertSame('Lun–Vie 9:00–18:00', $out['horario_publico']);
        $this->assertSame($grupos, $out['horarios_publicos']);
    }

    public function test_preparar_lanza_si_el_horario_es_invalido(): void
    {
        $this->expectException(ValidationException::class);

        HorarioPublico::preparar([
            HorarioPublico::grupo(['lun'], [['18:00', '09:00']]),
        ]);
    }

    public function test_intenta_parsear_una_frase_comun(): void
    {
        $grupos = HorarioPublico::intentarParsear('Lunes a sábado, 9:00 a 19:00');

        $this->assertNotNull($grupos);
        $this->assertSame('Lun–Sáb 9:00–19:00 · Dom cerrado', HorarioPublico::formatear($grupos));
    }

    public function test_no_parsea_un_texto_libre(): void
    {
        $this->assertNull(HorarioPublico::intentarParsear('Cita previa, horario variable'));
    }

    public function test_rangos_continuos_no_se_traslapan(): void
    {
        $this->assertSame([], HorarioPublico::validar([
            HorarioPublico::grupo(['lun'], [['09:00', '14:00'], ['14:00', '18:00']]),
        ]));
    }
}
