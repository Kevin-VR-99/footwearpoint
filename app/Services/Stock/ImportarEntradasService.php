<?php

namespace App\Services\Stock;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Importar entradas de stock desde Excel o CSV (columnas: SKU, cantidad, motivo).
 *
 * Primero se lee y valida (vista previa, no escribe nada); luego se aplica
 * todo en una sola transacción con StockService::registrarEntradas.
 */
class ImportarEntradasService
{
    public const MAX_FILAS = 1000;
    public const MAX_KB = 2048;
    public const EXTENSIONES = ['csv', 'txt', 'xlsx', 'xls'];

    public function __construct(
        private StockService $stock,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return array{filas: list<array{fila:int, sku:string, cantidad:string, motivo:string, error:?string}>,
     *               lineas: list<array{variante_id:int, sku:string, cantidad:int, motivo:?string, filas:list<int>}>,
     *               errores:int}
     */
    public function previsualizar(UploadedFile $archivo): array
    {
        $extension = strtolower($archivo->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONES, true)) {
            throw new OperacionInvalidaException('Sube un archivo .xlsx, .xls o .csv.', 422);
        }

        if ($archivo->getSize() > self::MAX_KB * 1024) {
            throw new OperacionInvalidaException('El archivo pesa más de 2 MB.', 422);
        }

        return $this->validarFilas($this->leer($archivo->getRealPath(), $extension));
    }

    /**
     * @param  list<array<int, mixed>>  $crudas
     */
    public function validarFilas(array $crudas): array
    {
        // Primera fila: encabezados. Se aceptan en cualquier orden y mayúsculas.
        $encabezados = array_map(fn ($v) => $this->normalizar((string) $v), $crudas[0] ?? []);
        $colSku = array_search('sku', $encabezados, true);
        $colCantidad = array_search('cantidad', $encabezados, true);
        $colMotivo = array_search('motivo', $encabezados, true);

        if ($colSku === false || $colCantidad === false) {
            throw new OperacionInvalidaException('El archivo debe tener las columnas SKU y cantidad en la primera fila. Descarga la plantilla.', 422);
        }

        $datos = array_slice($crudas, 1, null, true);
        $datos = array_filter($datos, fn ($f) => trim(implode('', array_map('strval', $f))) !== '');

        if ($datos === []) {
            throw new OperacionInvalidaException('El archivo no tiene filas con datos.', 422);
        }

        if (count($datos) > self::MAX_FILAS) {
            throw new OperacionInvalidaException('El archivo tiene más de '.self::MAX_FILAS.' filas. Divídelo en partes.', 422);
        }

        $skus = collect($datos)->map(fn ($f) => trim((string) ($f[$colSku] ?? '')))->filter()->unique();
        $variantes = Variante::query()
            ->whereIn('sku', $skus->all())
            ->pluck('id', 'sku')
            ->mapWithKeys(fn ($id, $sku) => [mb_strtolower($sku) => ['id' => (int) $id, 'sku' => $sku]]);

        $filas = [];
        $lineas = [];
        $errores = 0;

        foreach ($datos as $indice => $f) {
            $sku = trim((string) ($f[$colSku] ?? ''));
            $cantidadTexto = trim((string) ($f[$colCantidad] ?? ''));
            $motivo = $colMotivo !== false ? mb_substr(trim((string) ($f[$colMotivo] ?? '')), 0, 300) : '';
            $error = null;
            $variante = $variantes[mb_strtolower($sku)] ?? null;

            if ($sku === '') {
                $error = 'Falta el SKU.';
            } elseif ($variante === null) {
                $error = 'SKU desconocido.';
            } elseif (! preg_match('/^\d+(\.0+)?$/', $cantidadTexto) || (int) $cantidadTexto < 1) {
                $error = 'La cantidad debe ser un entero mayor que cero.';
            } elseif ((int) $cantidadTexto > 100000) {
                $error = 'Cantidad demasiado grande.';
            }

            $filas[] = ['fila' => $indice + 1, 'sku' => $sku, 'cantidad' => $cantidadTexto, 'motivo' => $motivo, 'error' => $error];

            if ($error !== null) {
                $errores++;
                continue;
            }

            // SKU repetido: se suman las cantidades en una sola entrada.
            $id = $variante['id'];
            $lineas[$id] ??= ['variante_id' => $id, 'sku' => $variante['sku'], 'cantidad' => 0, 'motivo' => null, 'filas' => []];
            $lineas[$id]['cantidad'] += (int) $cantidadTexto;
            $lineas[$id]['motivo'] ??= $motivo !== '' ? $motivo : null;
            $lineas[$id]['filas'][] = $indice + 1;
        }

        return ['filas' => $filas, 'lineas' => array_values($lineas), 'errores' => $errores];
    }

    /**
     * Aplica la vista previa ya validada. Se vuelve a comprobar en el servicio.
     *
     * @param  list<array{variante_id:int, cantidad:int, motivo:?string}>  $lineas
     */
    public function aplicar(array $lineas, string $nombreArchivo): array
    {
        if ($lineas === []) {
            throw new OperacionInvalidaException('No hay filas válidas para importar.', 422);
        }

        $cantidades = [];
        $motivos = [];
        foreach ($lineas as $linea) {
            $cantidades[(int) $linea['variante_id']] = (int) $linea['cantidad'];
            $motivos[(int) $linea['variante_id']] = $linea['motivo'] ?? null;
        }

        $movimientos = $this->stock->registrarEntradas($cantidades, 'Importación: '.mb_substr($nombreArchivo, 0, 200), $motivos);

        $this->auditoria->ejecutar('stock.importacion', 'stock_local', null, null, [
            'archivo' => mb_substr($nombreArchivo, 0, 200),
            'variantes' => count($movimientos),
            'piezas' => array_sum($cantidades),
            'movimiento_ids' => array_map(fn ($m) => (int) $m->id, $movimientos),
        ]);

        return $movimientos;
    }

    /** @return list<array<int, mixed>> */
    private function leer(string $ruta, string $extension): array
    {
        try {
            if (in_array($extension, ['csv', 'txt'], true)) {
                $lector = new Csv();
                $lector->setInputEncoding(Csv::GUESS_ENCODING);
                $primera = (string) fgets(fopen($ruta, 'r'));
                $lector->setDelimiter(substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',');
            } else {
                $lector = IOFactory::createReaderForFile($ruta);
            }
            $lector->setReadDataOnly(true);

            return $lector->load($ruta)->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            report($e);
            throw new OperacionInvalidaException('No se pudo leer el archivo. Revisa que sea un Excel o CSV válido.', 422);
        }
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto, " \t\n\r\0\x0B\u{FEFF}"));

        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
}
