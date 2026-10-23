<?php

namespace Tests\Feature\Respaldo;

use App\Services\Respaldo\ServidorMysqlVerificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** El servidor real: conteos de solo lectura y la contraseña nunca en la línea de comandos. */
class ServidorMysqlVerificacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuenta_las_filas_de_cada_tabla(): void
    {
        $conteos = app(ServidorMysqlVerificacion::class)->conteos((string) config('database.connections.mysql.database'));

        $this->assertArrayHasKey('usuarios', $conteos);
        $this->assertArrayHasKey('exportaciones', $conteos);
        $this->assertContainsOnly('int', $conteos);
    }

    public function test_cargar_usa_archivo_de_opciones_y_no_expone_la_contrasena(): void
    {
        config(['database.connections.mysql.password' => 'contrasena-que-no-debe-salir']);
        $comando = [];
        $opciones = '';
        Process::fake(function (PendingProcess $p) use (&$comando, &$opciones) {
            $comando = (array) $p->command;
            foreach ($comando as $a) {
                if (str_starts_with($a, '--defaults-extra-file=')) {
                    $opciones = (string) file_get_contents(substr($a, 22));
                }
            }

            return Process::result();
        });

        app(ServidorMysqlVerificacion::class)->cargar('footwearpoint_restaurada', '/tmp/x.sql');

        $this->assertStringNotContainsString('contrasena-que-no-debe-salir', implode(' ', $comando));
        $this->assertStringContainsString('contrasena-que-no-debe-salir', $opciones);
        $this->assertContains('--database=footwearpoint_restaurada', $comando);
        $this->assertContains('--execute=source /tmp/x.sql', $comando);
        $this->assertStringContainsString('loose-ssl-verify-server-cert=0', $opciones);
    }

    public function test_los_identificadores_se_escapan(): void
    {
        $this->assertSame('`a``b`', ServidorMysqlVerificacion::identificador('a`b'));
    }
}
