<?php

namespace App\Services\Cliente;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Support\MensajeError;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Importa clientes directos desde CSV o Excel en dos pasos: analizar (vista
 * previa renglón por renglón con sus errores) e importar solo los válidos.
 * No crea cuentas de la app: quedan como contactos, igual que el alta manual.
 *
 * Columnas (encabezado en la primera fila, sin importar mayúsculas/acentos):
 * nombre (obligatoria), telefono, email, direccion, notas.
 */
class ImportarClientesAction
{
    public const MAX_FILAS = 2000;

    private const ALIAS = [
        'nombre' => 'nombre', 'cliente' => 'nombre', 'nombre completo' => 'nombre',
        'telefono' => 'telefono', 'tel' => 'telefono', 'celular' => 'telefono', 'whatsapp' => 'telefono',
        'email' => 'email', 'correo' => 'email', 'correo electronico' => 'email',
        'direccion' => 'direccion', 'domicilio' => 'direccion',
        'notas' => 'notas', 'nota' => 'notas', 'comentarios' => 'notas',
    ];

    public function __construct(private PermisoModuloService $permisos, private RegistrarAuditoriaAction $auditoria)
    {
    }

    /** @return list<array{fila:int,nombre:string,telefono:?string,email:?string,direccion:?string,notas:?string,errores:list<string>}> */
    public function analizar(string $ruta, ?string $nombreOriginal = null): array
    {
        $this->exigirPermiso();
        $crudas = $this->leer($ruta, $nombreOriginal ?? $ruta);

        if (count($crudas) < 2) {
            throw new OperacionInvalidaException('El archivo está vacío o solo trae el encabezado.', 422);
        }

        $encabezado = array_map(fn ($c) => self::ALIAS[$this->normalizar((string) $c)] ?? null, array_shift($crudas));
        if (! in_array('nombre', $encabezado, true)) {
            throw new OperacionInvalidaException('Falta la columna «nombre» en el encabezado (columnas: nombre, telefono, email, direccion, notas).', 422);
        }
        if (count($crudas) > self::MAX_FILAS) {
            throw new OperacionInvalidaException('El archivo trae más de '.self::MAX_FILAS.' clientes. Divídelo en partes.', 422);
        }

        $existentesEmail = ClienteDirecto::query()->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->flip();
        $existentesTel = ClienteDirecto::query()->whereNotNull('telefono')->pluck('telefono')->map(fn ($t) => preg_replace('/\D/', '', $t))->filter()->flip();
        $vistosEmail = [];
        $vistosTel = [];
        $filas = [];

        foreach ($crudas as $i => $celdas) {
            $r = ['nombre' => null, 'telefono' => null, 'email' => null, 'direccion' => null, 'notas' => null];
            foreach ($encabezado as $col => $campo) {
                if ($campo !== null && $r[$campo] === null) {
                    $valor = trim((string) ($celdas[$col] ?? ''));
                    $r[$campo] = $valor === '' ? null : $valor;
                }
            }

            if (array_filter($r) === []) {
                continue; // renglón vacío
            }

            $errores = [];
            if ($r['nombre'] === null) {
                $errores[] = 'Falta el nombre.';
            } elseif (mb_strlen($r['nombre']) > 150) {
                $errores[] = 'El nombre es demasiado largo (máx. 150).';
            }

            if ($r['email'] !== null) {
                $r['email'] = mb_strtolower($r['email']);
                if (! filter_var($r['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($r['email']) > 190) {
                    $errores[] = 'El correo no es válido.';
                } elseif (isset($existentesEmail[$r['email']])) {
                    $errores[] = 'Ya existe un cliente con ese correo.';
                } elseif (isset($vistosEmail[$r['email']])) {
                    $errores[] = 'Correo repetido en el archivo (fila '.$vistosEmail[$r['email']].').';
                }
            }

            if ($r['telefono'] !== null) {
                $digitos = preg_replace('/\D/', '', $r['telefono']);
                if (strlen($digitos) < 10 || strlen($digitos) > 15) {
                    $errores[] = 'El teléfono debe tener de 10 a 15 dígitos.';
                } elseif (isset($existentesTel[$digitos])) {
                    $errores[] = 'Ya existe un cliente con ese teléfono.';
                } elseif (isset($vistosTel[$digitos])) {
                    $errores[] = 'Teléfono repetido en el archivo (fila '.$vistosTel[$digitos].').';
                }
                $r['telefono'] = $digitos;
            }

            $numero = $i + 2; // +1 por el encabezado, +1 porque Excel cuenta desde 1
            if ($r['email'] !== null && ! isset($vistosEmail[$r['email']])) {
                $vistosEmail[$r['email']] = $numero;
            }
            if ($r['telefono'] !== null && ! isset($vistosTel[$r['telefono']])) {
                $vistosTel[$r['telefono']] = $numero;
            }

            $r['direccion'] = $r['direccion'] !== null ? mb_substr($r['direccion'], 0, 255) : null;
            $r['notas'] = $r['notas'] !== null ? mb_substr($r['notas'], 0, 500) : null;
            $filas[] = ['fila' => $numero] + $r + ['errores' => $errores];
        }

        return $filas;
    }

    /** Crea los válidos (vuelve a validar contra la base) y regresa cuántos. */
    public function importar(array $filas): int
    {
        $this->exigirPermiso();

        return DB::transaction(function () use ($filas) {
            $creados = 0;
            foreach ($filas as $r) {
                if (($r['errores'] ?? []) !== [] || blank($r['nombre'] ?? null)) {
                    continue;
                }
                $duplicado = ClienteDirecto::query()
                    ->where(fn ($q) => $q->when($r['email'], fn ($w) => $w->orWhere('email', $r['email']))
                        ->when($r['telefono'], fn ($w) => $w->orWhere('telefono', $r['telefono'])))
                    ->when(! $r['email'] && ! $r['telefono'], fn ($q) => $q->whereRaw('1 = 0'))
                    ->exists();
                if ($duplicado) {
                    continue;
                }
                ClienteDirecto::create([
                    'nombre' => mb_substr($r['nombre'], 0, 150), 'telefono' => $r['telefono'], 'email' => $r['email'],
                    'direccion_contacto' => $r['direccion'], 'notas' => $r['notas'], 'estado' => 'activo',
                ]);
                $creados++;
            }

            $this->auditoria->ejecutar('clientes.importados', 'cliente_directo', null, null, [
                'creados' => $creados, 'filas' => count($filas),
            ]);

            return $creados;
        });
    }

    /** CSV con las filas con error, para corregir y volver a subir. */
    public function reporteErrores(array $filas): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['fila', 'nombre', 'telefono', 'email', 'direccion', 'notas', 'errores']);
        foreach ($filas as $r) {
            if (($r['errores'] ?? []) !== []) {
                fputcsv($h, [$r['fila'], $r['nombre'], $r['telefono'], $r['email'], $r['direccion'], $r['notas'], implode(' ', $r['errores'])]);
            }
        }
        rewind($h);

        return stream_get_contents($h);
    }

    private function leer(string $ruta, string $nombre): array
    {
        $extension = mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            throw new OperacionInvalidaException('Sube un archivo CSV o Excel (.xlsx).', 422);
        }

        try {
            if (in_array($extension, ['csv', 'txt'], true)) {
                $contenido = (string) file_get_contents($ruta);
                $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
                if (! mb_check_encoding($contenido, 'UTF-8')) {
                    $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
                }
                $primera = strtok($contenido, "\n") ?: '';
                $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
                $lineas = preg_split('/\r\n|\n|\r/', $contenido);

                // Los renglones vacíos se quedan para que el número de fila cuadre con el archivo.
                return array_map(fn ($l) => str_getcsv($l, $separador, '"', ''), $lineas);
            }

            $libro = IOFactory::load($ruta);

            return $libro->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable $e) {
            throw new OperacionInvalidaException('No se pudo leer el archivo. Revisa que sea un CSV o Excel válido.', 422);
        }
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '_' => ' ']);
    }

    private function exigirPermiso(): void
    {
        if (! $this->permisos->puede(auth()->user(), PermisoModuloService::CLIENTES_IMPORTAR)) {
            throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        }
    }
}
