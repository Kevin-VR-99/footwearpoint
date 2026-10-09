<?php

namespace App\Services\Respaldo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TG-235 (G17) — Respaldo de la base de datos:
 *
 *   1. mysqldump a un archivo temporal (transacción consistente, sin
 *      bloquear tablas). La contraseña va en un archivo de opciones
 *      temporal, nunca en la línea de comandos ni en el log;
 *   2. revisa que el volcado esté completo ("-- Dump completed" al final);
 *   3. lo comprime con gzip y lo sube al disco privado 'respaldos' como
 *      respaldos/AAAA/MM/footwearpoint-AAAAMMDD-HHMMSS.sql.gz y revisa el tamaño;
 *   4. borra los respaldos con más días que RESPALDO_RETENCION_DIAS.
 *
 * Lo corre el comando respaldo:base-de-datos desde un servicio cron de
 * Railway (ver docs/respaldos.md). Los temporales siempre se borran.
 */
class RespaldarBaseDeDatosAction
{
    public const PREFIJO = 'footwearpoint-';

    private const FIN_DEL_VOLCADO = '-- Dump completed';

    /**
     * @return array{archivo: string, bytes: int, borrados: list<string>, segundos: float}
     *
     * @throws RespaldoException
     */
    public function ejecutar(?Carbon $ahora = null): array
    {
        $inicio = microtime(true);
        $ahora ??= now();
        $disco = $this->disco();
        $conexion = $this->conexion();

        $base = tempnam(sys_get_temp_dir(), 'fwp-respaldo-');
        $opciones = $base.'.cnf';
        $sql = $base.'.sql';
        $gz = $base.'.sql.gz';

        try {
            $this->escribirOpciones($opciones, $conexion);
            $this->volcar($opciones, $sql, $conexion['database']);
            $this->revisarVolcado($sql);
            $this->comprimir($sql, $gz);

            $archivo = $this->ruta($ahora);
            $bytes = (int) filesize($gz);
            $this->subir($disco, $archivo, $gz, $bytes);
        } finally {
            foreach ([$base, $opciones, $sql, $gz] as $temporal) {
                if (is_file($temporal)) {
                    @unlink($temporal);
                }
            }
        }

        $borrados = $this->borrarViejos($disco, $ahora);
        $segundos = round(microtime(true) - $inicio, 1);

        Log::info('Respaldo de la base de datos guardado.', [
            'archivo'  => $archivo,
            'bytes'    => $bytes,
            'borrados' => count($borrados),
            'segundos' => $segundos,
        ]);

        return ['archivo' => $archivo, 'bytes' => $bytes, 'borrados' => $borrados, 'segundos' => $segundos];
    }

    /** respaldos/AAAA/MM/footwearpoint-AAAAMMDD-HHMMSS.sql.gz (en UTC). */
    public function ruta(Carbon $cuando): string
    {
        $utc = $cuando->copy()->utc();

        return trim((string) config('respaldos.carpeta'), '/').'/'.$utc->format('Y/m').'/'
            .self::PREFIJO.$utc->format('Ymd-His').'.sql.gz';
    }

    private function disco(): Filesystem
    {
        $nombre = (string) config('respaldos.disco');
        $config = config("filesystems.disks.{$nombre}");

        if (! is_array($config) || blank($config['bucket'] ?? null) || blank($config['key'] ?? null)
            || blank($config['secret'] ?? null) || blank($config['endpoint'] ?? null)) {
            throw new RespaldoException(RespaldoException::SIN_CONFIGURAR);
        }

        return Storage::disk($nombre);
    }

    /** @return array{host: string, port: string, username: string, password: string, database: string} */
    private function conexion(): array
    {
        $config = config('database.connections.'.config('database.default'));

        if (! is_array($config) || ! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RespaldoException(RespaldoException::SOLO_MYSQL);
        }

        return [
            'host'     => (string) ($config['host'] ?? '127.0.0.1'),
            'port'     => (string) ($config['port'] ?? '3306'),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'database' => (string) ($config['database'] ?? ''),
        ];
    }

    private function escribirOpciones(string $ruta, array $conexion): void
    {
        $valor = fn (string $v) => '"'.addcslashes($v, "\\\"").'"';

        file_put_contents($ruta, implode("\n", [
            '[client]',
            'host='.$valor($conexion['host']),
            'port='.$valor($conexion['port']),
            'user='.$valor($conexion['username']),
            'password='.$valor($conexion['password']),
            '',
        ]));
        @chmod($ruta, 0600);
    }

    private function volcar(string $opciones, string $sql, string $baseDeDatos): void
    {
        $comando = array_merge(
            [(string) config('respaldos.mysqldump'), '--defaults-extra-file='.$opciones],
            [
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$sql,
            ],
            preg_split('/\s+/', trim((string) config('respaldos.opciones_extra')), -1, PREG_SPLIT_NO_EMPTY),
            [$baseDeDatos],
        );

        $resultado = Process::timeout((int) config('respaldos.timeout'))->run($comando);

        if ($resultado->failed()) {
            // Solo el código y el inicio del error; nunca las opciones.
            Log::error('mysqldump falló.', [
                'codigo' => $resultado->exitCode(),
                'error'  => mb_substr(trim($resultado->errorOutput()), 0, 300),
            ]);

            throw new RespaldoException(RespaldoException::VOLCADO_FALLO);
        }
    }

    private function revisarVolcado(string $sql): void
    {
        $tamano = is_file($sql) ? (int) filesize($sql) : 0;

        if ($tamano === 0) {
            throw new RespaldoException(RespaldoException::VOLCADO_INCOMPLETO);
        }

        $archivo = fopen($sql, 'rb');
        fseek($archivo, max(0, $tamano - 512));
        $final = (string) stream_get_contents($archivo);
        fclose($archivo);

        if (! str_contains($final, self::FIN_DEL_VOLCADO)) {
            throw new RespaldoException(RespaldoException::VOLCADO_INCOMPLETO);
        }
    }

    private function comprimir(string $sql, string $gz): void
    {
        $entrada = fopen($sql, 'rb');
        $salida = gzopen($gz, 'wb6');

        while (! feof($entrada)) {
            gzwrite($salida, (string) fread($entrada, 1024 * 1024));
        }

        fclose($entrada);
        gzclose($salida);
    }

    private function subir(Filesystem $disco, string $archivo, string $gz, int $bytes): void
    {
        try {
            $flujo = fopen($gz, 'rb');
            $disco->writeStream($archivo, $flujo);

            if (is_resource($flujo)) {
                fclose($flujo);
            }

            $guardado = $disco->size($archivo);
        } catch (Throwable $e) {
            Log::error('No se pudo subir el respaldo.', ['archivo' => $archivo, 'error' => class_basename($e)]);

            throw new RespaldoException(RespaldoException::SUBIDA_FALLO, 0, $e);
        }

        if ($guardado !== $bytes) {
            throw new RespaldoException(RespaldoException::SUBIDA_FALLO);
        }
    }

    /** @return list<string> los que se borraron */
    private function borrarViejos(Filesystem $disco, Carbon $ahora): array
    {
        $dias = (int) config('respaldos.retencion_dias');

        if ($dias <= 0) {
            return [];
        }

        $limite = $ahora->copy()->utc()->subDays($dias);
        $borrados = [];

        foreach ($disco->allFiles(trim((string) config('respaldos.carpeta'), '/')) as $archivo) {
            // Solo los que hizo este comando (por su nombre).
            if (preg_match('/'.preg_quote(self::PREFIJO, '/').'(\d{8}-\d{6})\.sql\.gz$/', $archivo, $m) !== 1) {
                continue;
            }

            if (Carbon::createFromFormat('Ymd-His', $m[1], 'UTC')->lt($limite)) {
                $disco->delete($archivo);
                $borrados[] = $archivo;
            }
        }

        return $borrados;
    }
}
