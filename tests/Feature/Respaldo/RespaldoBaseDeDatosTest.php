<?php

namespace Tests\Feature\Respaldo;

use App\Services\Respaldo\RespaldarBaseDeDatosAction;
use App\Services\Respaldo\RespaldoException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TG-235 (G17) — php artisan respaldo:base-de-datos: mysqldump + gzip al
 * bucket privado de respaldos, con retención.
 *
 * Nunca corre mysqldump de verdad (Process::fake) ni sube a R2 (Storage::fake).
 */
class RespaldoBaseDeDatosTest extends TestCase
{
    private const CONTRASENA = 'contrasena-de-prueba-que-no-debe-salir';

    /** Lo que "vuelca" mysqldump en cada prueba. */
    private string $volcado = "CREATE TABLE `pagos` (id int);\nINSERT INTO `pagos` VALUES (1);\n-- Dump completed on 2026-10-09  9:00:01\n";

    private int $codigoMysqldump = 0;

    /** @var list<array> comandos que se pidieron */
    private array $comandos = [];

    /** Contenido del archivo de opciones al momento del volcado. */
    private string $opciones = '';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00:00', 'UTC'));

        config([
            'filesystems.disks.respaldos.key'      => 'llave-de-prueba',
            'filesystems.disks.respaldos.secret'   => 'secreto-de-prueba',
            'filesystems.disks.respaldos.bucket'   => 'fwp-respaldos-prueba',
            'filesystems.disks.respaldos.endpoint' => 'https://cuenta.r2.cloudflarestorage.com',
            'database.connections.mysql.password'  => self::CONTRASENA,
            'respaldos.retencion_dias'             => 30,
        ]);
        Storage::fake('respaldos');

        Process::fake(function (PendingProcess $proceso) {
            $this->comandos[] = $proceso->command;

            foreach ((array) $proceso->command as $argumento) {
                if (str_starts_with($argumento, '--defaults-extra-file=')) {
                    $this->opciones = (string) file_get_contents(substr($argumento, strlen('--defaults-extra-file=')));
                }
                if (str_starts_with($argumento, '--result-file=') && $this->codigoMysqldump === 0) {
                    file_put_contents(substr($argumento, strlen('--result-file=')), $this->volcado);
                }
            }

            return Process::result('', $this->codigoMysqldump === 0 ? '' : 'mysqldump: Got error: 2005', $this->codigoMysqldump);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private const ARCHIVO = 'respaldos/2026/10/footwearpoint-20261009-090000.sql.gz';

    public function test_vuelca_comprime_y_guarda_el_respaldo_en_el_bucket_privado(): void
    {
        $this->artisan('respaldo:base-de-datos')
            ->expectsOutputToContain('Respaldo guardado: '.self::ARCHIVO)
            ->assertSuccessful();

        Storage::disk('respaldos')->assertExists(self::ARCHIVO);
        $this->assertSame($this->volcado, gzdecode(Storage::disk('respaldos')->get(self::ARCHIVO)));

        $comando = $this->comandos[0];
        $this->assertSame('mysqldump', $comando[0]);
        $this->assertContains('--single-transaction', $comando);
        $this->assertContains('--routines', $comando);
        $this->assertSame(config('database.connections.mysql.database'), end($comando));
        $this->assertSame('private', config('filesystems.disks.respaldos.visibility'));
    }

    public function test_la_contrasena_nunca_va_en_la_linea_de_comandos_ni_en_el_log_y_no_quedan_temporales(): void
    {
        Log::spy();
        $antes = glob(sys_get_temp_dir().'/fwp-respaldo-*') ?: [];

        $this->artisan('respaldo:base-de-datos')->assertSuccessful();

        $this->assertStringNotContainsString(self::CONTRASENA, implode(' ', $this->comandos[0]));
        $this->assertStringContainsString('password="'.self::CONTRASENA.'"', $this->opciones);
        Log::shouldHaveReceived('info')->withArgs(fn ($mensaje, $contexto = []) => ! str_contains(json_encode($contexto), self::CONTRASENA))->once();
        $this->assertSame($antes, glob(sys_get_temp_dir().'/fwp-respaldo-*') ?: []);
    }

    public function test_un_volcado_incompleto_no_se_guarda_y_el_comando_falla(): void
    {
        $this->volcado = "CREATE TABLE `pagos` (id int);\nINSERT INTO `pag";

        $this->artisan('respaldo:base-de-datos')
            ->expectsOutputToContain(RespaldoException::VOLCADO_INCOMPLETO)
            ->assertFailed();

        $this->assertSame([], Storage::disk('respaldos')->allFiles());
    }

    public function test_si_mysqldump_falla_no_se_guarda_nada(): void
    {
        $this->codigoMysqldump = 2;

        $this->artisan('respaldo:base-de-datos')
            ->expectsOutputToContain(RespaldoException::VOLCADO_FALLO)
            ->assertFailed();

        $this->assertSame([], Storage::disk('respaldos')->allFiles());
    }

    public function test_sin_bucket_configurado_avisa_y_no_vuelca(): void
    {
        config(['filesystems.disks.respaldos.bucket' => null]);

        $this->artisan('respaldo:base-de-datos')
            ->expectsOutputToContain(RespaldoException::SIN_CONFIGURAR)
            ->assertFailed();

        $this->assertSame([], $this->comandos);
    }

    public function test_borra_solo_los_respaldos_mas_viejos_que_la_retencion(): void
    {
        $disco = Storage::disk('respaldos');
        $disco->put('respaldos/2026/09/footwearpoint-20260908-090000.sql.gz', 'viejo');   // 31 días
        $disco->put('respaldos/2026/09/footwearpoint-20260910-090000.sql.gz', 'reciente'); // 29 días
        $disco->put('respaldos/2026/01/manual-importante.sql.gz', 'otro');                 // no es de este comando

        $resultado = app(RespaldarBaseDeDatosAction::class)->ejecutar();

        $this->assertSame(['respaldos/2026/09/footwearpoint-20260908-090000.sql.gz'], $resultado['borrados']);
        $disco->assertMissing('respaldos/2026/09/footwearpoint-20260908-090000.sql.gz');
        $disco->assertExists('respaldos/2026/09/footwearpoint-20260910-090000.sql.gz');
        $disco->assertExists('respaldos/2026/01/manual-importante.sql.gz');
        $disco->assertExists(self::ARCHIVO);

        // Con retención 0 no se borra ninguno.
        config(['respaldos.retencion_dias' => 0]);
        Carbon::setTestNow(Carbon::parse('2027-10-09 09:00:00', 'UTC'));
        $this->assertSame([], app(RespaldarBaseDeDatosAction::class)->ejecutar()['borrados']);
    }
}
