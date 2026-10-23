<?php

namespace Tests\Feature\Correo;

use App\Rules\CorreoConDominioReal;
use App\Services\Correo\VerificadorDominioCorreo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * La regla que rechaza correos cuyo dominio no existe. Ninguna prueba toca
 * el DNS real: el resolvedor se inyecta.
 */
class CorreoConDominioRealTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function falla(string $correo, VerificadorDominioCorreo $verificador): bool
    {
        return Validator::make(
            ['email' => $correo],
            ['email' => ['required', 'email', new CorreoConDominioReal($verificador)]],
        )->fails();
    }

    public function test_rechaza_un_dominio_que_no_existe_con_mensaje_en_espanol(): void
    {
        $validador = Validator::make(
            ['email' => 'ana@gmial.con'],
            ['email' => ['required', 'email', new CorreoConDominioReal(VerificadorDominioCorreo::falso(['gmial.con']))]],
        );

        $this->assertTrue($validador->fails());
        $this->assertSame('Ese correo no parece existir. Usa un correo válido.', $validador->errors()->first('email'));
    }

    public function test_acepta_un_dominio_que_existe(): void
    {
        $this->assertFalse($this->falla('ana@gmail.com', VerificadorDominioCorreo::falso(['gmial.con'])));
    }

    public function test_normaliza_mayusculas_y_punto_final(): void
    {
        $vistos = [];
        $verificador = new VerificadorDominioCorreo(function (string $dominio) use (&$vistos) {
            $vistos[] = $dominio;

            return true;
        });

        $this->assertFalse($this->falla('Ana@GMAIL.Com', $verificador));
        $this->assertSame(['gmail.com'], $vistos);
    }

    public function test_un_dominio_sin_punto_se_rechaza_sin_consultar(): void
    {
        $consultas = 0;
        $verificador = new VerificadorDominioCorreo(function () use (&$consultas) {
            $consultas++;

            return true;
        });

        $this->assertFalse($verificador->existe('localhost'));
        $this->assertSame(0, $consultas);
    }

    public function test_guarda_el_resultado_en_cache_un_rato(): void
    {
        $consultas = 0;
        $verificador = new VerificadorDominioCorreo(function () use (&$consultas) {
            $consultas++;

            return false;
        });

        $this->assertFalse($verificador->existe('nada.mx'));
        $this->assertFalse($verificador->existe('nada.mx'));
        $this->assertSame(1, $consultas);

        $this->travel(2)->minutes();

        $verificador->existe('nada.mx');
        $this->assertSame(2, $consultas);
    }

    public function test_sin_resolvedor_inyectado_usa_el_registrado_en_el_contenedor(): void
    {
        $this->app->instance(VerificadorDominioCorreo::class, VerificadorDominioCorreo::falso(['inventado.mx']));

        $this->assertTrue(Validator::make(['e' => 'a@inventado.mx'], ['e' => [new CorreoConDominioReal()]])->fails());
        $this->assertFalse(Validator::make(['e' => 'a@real.mx'], ['e' => [new CorreoConDominioReal()]])->fails());
    }
}
