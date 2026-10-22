<?php

namespace App\Services\Respaldo;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Simulacro de restauración (php artisan respaldo:verificar):
 *
 *   1. toma el respaldo más reciente del disco privado de respaldos;
 *   2. lo descarga y descomprime en temporales;
 *   3. lo restaura en una base APARTE (respaldos.verificacion.base), nunca
 *      en la principal: si el nombre coincide con DB_DATABASE se niega;
 *   4. compara las filas de cada tabla contra la base principal;
 *   5. borra la base de verificación y los temporales (siempre).
 *
 * Falla si falta alguna tabla o si la restauración quedó vacía. Las
 * diferencias de filas por encima de la tolerancia salen como aviso: la base
 * principal siguió cambiando después del respaldo.
 */
class VerificarRespaldoAction
{
    public function __construct(private ServidorMysqlVerificacion $servidor) {}

    /**
     * @return array{archivo: string, base: string, tablas: array<string, array{principal: int, restaurada: ?int}>, faltantes: list<string>, avisos: list<string>, ok: bool, segundos: float}
     *
     * @throws RespaldoException
     */
    public function ejecutar(?string $base = null, bool $reemplazar = false, bool $conservar = false): array
    {
        $inicio = microtime(true);
        $base = $this->validarBase($base ?? (string) config('respaldos.verificacion.base'));

        $disco = Storage::disk((string) config('respaldos.disco'));
        $archivo = $this->masReciente($disco->allFiles(trim((string) config('respaldos.carpeta'), '/')));

        if ($this->servidor->existe($base)) {
            if (! $reemplazar) {
                throw new RespaldoException(RespaldoException::BASE_EXISTE);
            }
            $this->servidor->borrar($base);
        }

        $temporal = tempnam(sys_get_temp_dir(), 'fwp-verif-');
        $gz = $temporal.'.sql.gz';
        $sql = $temporal.'.sql';
        $creada = false;

        try {
            $this->descargar($disco, $archivo, $gz, $sql);

            $this->servidor->crear($base);
            $creada = true;
            $this->servidor->cargar($base, $sql);

            $principal = $this->servidor->conteos($this->basePrincipal());
            $restaurada = $this->servidor->conteos($base);
        } finally {
            if ($creada && ! $conservar) {
                try {
                    $this->servidor->borrar($base);
                } catch (Throwable $e) {
                    Log::error('No se pudo borrar la base de verificación.', ['base' => $base, 'error' => class_basename($e)]);
                }
            }
            foreach ([$temporal, $gz, $sql] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }

        return $this->comparar($archivo, $base, $principal, $restaurada, microtime(true) - $inicio);
    }

    /** @throws RespaldoException */
    public function validarBase(string $base): string
    {
        $base = trim($base);

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $base) !== 1) {
            throw new RespaldoException(RespaldoException::BASE_INVALIDA);
        }

        if (strcasecmp($base, $this->basePrincipal()) === 0) {
            throw new RespaldoException(RespaldoException::BASE_PRINCIPAL);
        }

        return $base;
    }

    private function basePrincipal(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    /** @param  list<string>  $archivos */
    private function masReciente(array $archivos): string
    {
        $patron = '/'.preg_quote(RespaldarBaseDeDatosAction::PREFIJO, '/').'(\d{8}-\d{6})\.sql\.gz$/';
        $candidatos = [];

        foreach ($archivos as $archivo) {
            if (preg_match($patron, $archivo, $m) === 1) {
                $candidatos[$m[1].'|'.$archivo] = $archivo;
            }
        }

        if ($candidatos === []) {
            throw new RespaldoException(RespaldoException::SIN_RESPALDOS);
        }

        krsort($candidatos);

        return reset($candidatos);
    }

    private function descargar($disco, string $archivo, string $gz, string $sql): void
    {
        try {
            $entrada = $disco->readStream($archivo);
            $salida = fopen($gz, 'wb');
            stream_copy_to_stream($entrada, $salida);
            fclose($salida);
            if (is_resource($entrada)) {
                fclose($entrada);
            }

            $comprimido = gzopen($gz, 'rb');
            $plano = fopen($sql, 'wb');
            while (! gzeof($comprimido)) {
                fwrite($plano, (string) gzread($comprimido, 1024 * 1024));
            }
            gzclose($comprimido);
            fclose($plano);
        } catch (Throwable $e) {
            throw new RespaldoException(RespaldoException::DESCARGA_FALLO, 0, $e);
        }

        if (! is_file($sql) || filesize($sql) === 0) {
            throw new RespaldoException(RespaldoException::DESCARGA_FALLO);
        }
    }

    private function comparar(string $archivo, string $base, array $principal, array $restaurada, float $segundos): array
    {
        $tolerancia = (float) config('respaldos.verificacion.tolerancia_porcentaje', 10);
        $tablas = [];
        $faltantes = [];
        $avisos = [];

        foreach ($principal as $tabla => $filas) {
            $tablas[$tabla] = ['principal' => $filas, 'restaurada' => $restaurada[$tabla] ?? null];

            if (! array_key_exists($tabla, $restaurada)) {
                $faltantes[] = $tabla;

                continue;
            }

            $diferencia = abs($filas - $restaurada[$tabla]);
            if ($diferencia > 0 && $diferencia / max(1, $filas) * 100 > $tolerancia) {
                $avisos[] = sprintf('%s: %d en la principal y %d en el respaldo.', $tabla, $filas, $restaurada[$tabla]);
            }
        }

        $ok = $faltantes === [] && $restaurada !== [] && array_sum($restaurada) > 0;

        Log::info('Simulacro de restauración.', [
            'archivo' => $archivo, 'ok' => $ok, 'tablas' => count($restaurada),
            'faltantes' => $faltantes, 'avisos' => count($avisos),
        ]);

        return [
            'archivo' => $archivo,
            'base' => $base,
            'tablas' => $tablas,
            'faltantes' => $faltantes,
            'avisos' => $avisos,
            'ok' => $ok,
            'segundos' => round($segundos, 1),
        ];
    }
}
