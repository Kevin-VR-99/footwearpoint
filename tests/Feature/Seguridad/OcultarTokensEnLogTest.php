<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\ServeCommand;
use App\Support\OcultarTokensEnUrl;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OcultarTokensEnLogTest extends TestCase
{
    public function test_oculta_el_token_de_restablecer_contrasena(): void
    {
        $linea = '2026-10-23 06:34:00 /reset-password/fd5d0d055887cdce6558 ~ 0.09ms';

        $this->assertSame(
            '2026-10-23 06:34:00 /reset-password/[oculto] ~ 0.09ms',
            OcultarTokensEnUrl::texto($linea)
        );
    }

    public function test_oculta_hash_y_firma_del_enlace_de_correo_y_tokens_en_query(): void
    {
        $texto = OcultarTokensEnUrl::texto('/verificar-correo/12/abc123def?expires=1761200000&signature=deadbeef99 y /api/x?token=secreto&page=2');

        $this->assertSame('/verificar-correo/12/[oculto]?expires=1761200000&signature=[oculto] y /api/x?token=[oculto]&page=2', $texto);
    }

    public function test_no_cambia_rutas_normales(): void
    {
        $linea = '2026-10-23 07:55:44 /admin/catalogos/5/revisar ~ 500.30ms';

        $this->assertSame($linea, OcultarTokensEnUrl::texto($linea));
    }

    public function test_artisan_serve_usa_la_version_que_oculta_tokens(): void
    {
        $this->assertInstanceOf(ServeCommand::class, \Illuminate\Support\Facades\Artisan::all()['serve']);
    }

    public function test_artisan_serve_oculta_el_token_aunque_llegue_partido(): void
    {
        $comando = $this->app->make(ServeCommand::class);

        $comando->agregarSalida("[Fri Oct 23 06:34:00 2026] 127.0.0.1:5000 [200]: GET /reset-password/fd5d0d05");
        $this->assertStringNotContainsString("\n", $comando->salidaPendiente());

        $comando->agregarSalida("5887cdce6558\n[Fri Oct 23 06:34:01 2026] 127.0.0.1:5000 Clos");

        $this->assertStringNotContainsString('fd5d0d05', $comando->salidaPendiente());
        $this->assertStringContainsString('/reset-password/[oculto]', $comando->salidaPendiente());
        $this->assertStringEndsWith('Clos', $comando->salidaPendiente());
    }

    public function test_los_logs_de_la_app_ocultan_tokens(): void
    {
        $archivo = storage_path('logs/prueba-ocultar-tokens.log');
        @unlink($archivo);

        config(['logging.channels.prueba_tokens' => [
            'driver' => 'single',
            'path' => $archivo,
            'tap' => [\App\Logging\OcultarTokensEnLog::class],
        ]]);

        Log::channel('prueba_tokens')->error('Fallo en https://fp.test/reset-password/tok123secreto', [
            'url' => 'https://fp.test/verificar-correo/3/hash999?signature=firma777',
        ]);

        $contenido = (string) file_get_contents($archivo);
        @unlink($archivo);

        $this->assertStringNotContainsString('tok123secreto', $contenido);
        $this->assertStringNotContainsString('hash999', $contenido);
        $this->assertStringNotContainsString('firma777', $contenido);
        $this->assertStringContainsString('/reset-password/[oculto]', $contenido);
    }

    public function test_los_canales_de_produccion_tienen_el_tap(): void
    {
        foreach (['single', 'daily', 'stderr'] as $canal) {
            $this->assertContains(\App\Logging\OcultarTokensEnLog::class, config("logging.channels.$canal.tap", []), $canal);
        }
    }
}
