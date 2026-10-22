<?php

namespace Tests\Feature\Respaldo;

use App\Services\Respaldo\RespaldoException;
use App\Services\Respaldo\ServidorMysqlVerificacion;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * php artisan respaldo:verificar — simulacro de restauración en una base aparte.
 * Nunca crea bases de verdad: el servidor se cambia por uno en memoria.
 */
class VerificarRespaldoTest extends TestCase
{
    private ServidorEnMemoria $servidor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('respaldos');
        config(['respaldos.verificacion.base' => 'footwearpoint_restaurada_prueba']);

        $this->servidor = new ServidorEnMemoria((string) config('database.connections.mysql.database'));
        $this->app->instance(ServidorMysqlVerificacion::class, $this->servidor);
    }

    private function subirRespaldo(string $marca, string $sql = "-- tablas\n-- Dump completed\n"): void
    {
        Storage::disk('respaldos')->put('respaldos/2026/10/footwearpoint-'.$marca.'.sql.gz', gzencode($sql));
    }

    public function test_se_niega_si_la_base_es_la_principal(): void
    {
        $this->subirRespaldo('20261020-090000');
        $principal = (string) config('database.connections.mysql.database');

        $this->artisan('respaldo:verificar', ['--base' => $principal])
            ->expectsOutputToContain(RespaldoException::BASE_PRINCIPAL)
            ->assertFailed();

        $this->artisan('respaldo:verificar', ['--base' => strtoupper($principal)])->assertFailed();

        config(['respaldos.verificacion.base' => $principal]);
        $this->artisan('respaldo:verificar')->assertFailed();

        $this->assertSame([], $this->servidor->llamadas, 'No se tocó ningún servidor.');
    }

    public function test_se_niega_con_un_nombre_invalido(): void
    {
        $this->subirRespaldo('20261020-090000');

        $this->artisan('respaldo:verificar', ['--base' => 'x`; DROP DATABASE footwearpoint; --'])
            ->expectsOutputToContain(RespaldoException::BASE_INVALIDA)
            ->assertFailed();
        $this->assertSame([], $this->servidor->llamadas);
    }

    public function test_restaura_el_mas_reciente_compara_y_borra_la_base(): void
    {
        $this->subirRespaldo('20261001-090000', 'viejo');
        $this->subirRespaldo('20261020-090000', "nuevo\n-- Dump completed\n");
        $this->servidor->principal = ['pedidos' => 100, 'pagos' => 40];
        $this->servidor->restaurada = ['pedidos' => 98, 'pagos' => 40];

        $this->artisan('respaldo:verificar')
            ->expectsOutputToContain('footwearpoint-20261020-090000.sql.gz')
            ->expectsOutputToContain('Simulacro correcto: 2 tablas')
            ->assertSuccessful();

        $this->assertSame(['existe', 'crear', 'cargar', 'conteos:principal', 'conteos:verificacion', 'borrar'], $this->servidor->llamadas);
        $this->assertSame('footwearpoint_restaurada_prueba', $this->servidor->base);
        $this->assertStringStartsWith('nuevo', $this->servidor->sqlCargado);
        $this->assertFileDoesNotExist($this->servidor->rutaSql, 'El temporal se borra.');
    }

    public function test_falla_si_falta_una_tabla_y_aun_asi_borra_la_base(): void
    {
        $this->subirRespaldo('20261020-090000');
        $this->servidor->principal = ['pedidos' => 10, 'pagos' => 4];
        $this->servidor->restaurada = ['pedidos' => 10];

        $this->artisan('respaldo:verificar')
            ->expectsOutputToContain('Faltan tablas en el respaldo: pagos')
            ->assertFailed();

        $this->assertContains('borrar', $this->servidor->llamadas);
    }

    public function test_las_diferencias_grandes_son_aviso(): void
    {
        $this->subirRespaldo('20261020-090000');
        $this->servidor->principal = ['pedidos' => 100];
        $this->servidor->restaurada = ['pedidos' => 50];

        $this->artisan('respaldo:verificar')
            ->expectsOutputToContain('Aviso: pedidos: 100 en la principal y 50 en el respaldo.')
            ->assertSuccessful();
    }

    public function test_si_la_carga_falla_borra_la_base(): void
    {
        $this->subirRespaldo('20261020-090000');
        $this->servidor->fallarCarga = true;

        $this->artisan('respaldo:verificar')->expectsOutputToContain(RespaldoException::CARGA_FALLO)->assertFailed();
        $this->assertSame('borrar', end($this->servidor->llamadas));
    }

    public function test_si_la_base_ya_existe_no_la_toca_sin_reemplazar(): void
    {
        $this->subirRespaldo('20261020-090000');
        $this->servidor->yaExiste = true;

        $this->artisan('respaldo:verificar')->expectsOutputToContain(RespaldoException::BASE_EXISTE)->assertFailed();
        $this->assertSame(['existe'], $this->servidor->llamadas);

        $this->servidor->llamadas = [];
        $this->servidor->principal = $this->servidor->restaurada = ['pedidos' => 1];
        $this->artisan('respaldo:verificar', ['--reemplazar' => true])->assertSuccessful();
        $this->assertSame(['existe', 'borrar', 'crear'], array_slice($this->servidor->llamadas, 0, 3));
    }

    public function test_sin_respaldos_falla(): void
    {
        $this->artisan('respaldo:verificar')->expectsOutputToContain(RespaldoException::SIN_RESPALDOS)->assertFailed();
    }

    public function test_conservar_deja_la_base(): void
    {
        $this->subirRespaldo('20261020-090000');
        $this->servidor->principal = $this->servidor->restaurada = ['pedidos' => 1];

        $this->artisan('respaldo:verificar', ['--conservar' => true])->assertSuccessful();
        $this->assertNotContains('borrar', $this->servidor->llamadas);
    }
}

/** Servidor falso: registra qué se pidió y devuelve conteos fijos. */
class ServidorEnMemoria extends ServidorMysqlVerificacion
{
    public array $llamadas = [];
    public array $principal = ['pedidos' => 1];
    public array $restaurada = ['pedidos' => 1];
    public bool $yaExiste = false;
    public bool $fallarCarga = false;
    public ?string $base = null;
    public string $sqlCargado = '';
    public string $rutaSql = '';

    public function __construct(private string $basePrincipal) {}

    public function existe(string $base): bool
    {
        $this->llamadas[] = 'existe';

        return $this->yaExiste;
    }

    public function crear(string $base): void
    {
        if (strcasecmp($base, $this->basePrincipal) === 0) {
            throw new \LogicException('Se intentó crear la base principal.');
        }
        $this->llamadas[] = 'crear';
        $this->base = $base;
    }

    public function borrar(string $base): void
    {
        if (strcasecmp($base, $this->basePrincipal) === 0) {
            throw new \LogicException('Se intentó borrar la base principal.');
        }
        $this->llamadas[] = 'borrar';
        $this->yaExiste = false;
    }

    public function cargar(string $base, string $sql): void
    {
        $this->llamadas[] = 'cargar';
        $this->rutaSql = $sql;
        $this->sqlCargado = (string) file_get_contents($sql);
        if ($this->fallarCarga) {
            throw new RespaldoException(RespaldoException::CARGA_FALLO);
        }
    }

    public function conteos(string $base): array
    {
        $esPrincipal = $base === $this->basePrincipal;
        $this->llamadas[] = 'conteos:'.($esPrincipal ? 'principal' : 'verificacion');

        return $esPrincipal ? $this->principal : $this->restaurada;
    }
}
