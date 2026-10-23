<?php

namespace App\Services\Respaldo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Operaciones de servidor que necesita el simulacro de restauración. Separadas
 * para poder cambiarlas en las pruebas (el usuario de pruebas no puede crear
 * bases). La contraseña va en un archivo de opciones temporal (0600), nunca
 * en la línea de comandos ni en el log.
 */
class ServidorMysqlVerificacion
{
    public function existe(string $base): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?', [$base])->n > 0;
    }

    public function crear(string $base): void
    {
        DB::statement('CREATE DATABASE '.self::identificador($base).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function borrar(string $base): void
    {
        DB::statement('DROP DATABASE IF EXISTS '.self::identificador($base));
    }

    /** Carga el .sql en la base con el cliente mysql. */
    public function cargar(string $base, string $sql): void
    {
        $opciones = tempnam(sys_get_temp_dir(), 'fwp-verif-').'.cnf';

        try {
            $config = config('database.connections.'.config('database.default'));
            $valor = fn ($v) => '"'.addcslashes((string) $v, "\\\"").'"';
            file_put_contents($opciones, implode("\n", [
                '[client]',
                'host='.$valor($config['host'] ?? '127.0.0.1'),
                'port='.$valor($config['port'] ?? '3306'),
                'user='.$valor($config['username'] ?? ''),
                'password='.$valor($config['password'] ?? ''),
                ...RespaldarBaseDeDatosAction::opcionesSsl(),
                '',
            ]));
            @chmod($opciones, 0600);

            // Por stdin y no con --execute=source: con source el cliente de MariaDB
            // sigue tras un error y sale con 0, y el simulacro daba APTO con tablas vacías.
            $entrada = fopen($sql, 'rb');
            $resultado = Process::timeout((int) config('respaldos.timeout'))->input($entrada)->run([
                (string) config('respaldos.verificacion.mysql'),
                '--defaults-extra-file='.$opciones,
                '--default-character-set=utf8mb4',
                '--database='.$base,
            ]);
            if (is_resource($entrada)) {
                fclose($entrada);
            }

            if ($resultado->failed()) {
                Log::error('mysql no pudo cargar el respaldo.', [
                    'codigo' => $resultado->exitCode(),
                    'error' => mb_substr(trim($resultado->errorOutput()), 0, 300),
                ]);

                throw new RespaldoException(RespaldoException::CARGA_FALLO);
            }
        } finally {
            @unlink($opciones);
            @unlink(substr($opciones, 0, -4));
        }
    }

    /** @return array<string, int> tabla => filas (conteo exacto) */
    public function conteos(string $base): array
    {
        $tablas = DB::select(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$base]
        );

        $conteos = [];
        foreach ($tablas as $fila) {
            $tabla = (string) $fila->t;
            $conteos[$tabla] = (int) DB::selectOne('SELECT COUNT(*) AS n FROM '.self::identificador($base).'.'.self::identificador($tabla))->n;
        }

        return $conteos;
    }

    public static function identificador(string $nombre): string
    {
        return '`'.str_replace('`', '``', $nombre).'`';
    }
}
