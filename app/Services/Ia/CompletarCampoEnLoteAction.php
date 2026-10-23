<?php

namespace App\Services\Ia;

use App\Models\ImportacionCatalogo;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use InvalidArgumentException;

/**
 * TG-239 (A9) — Rellenar un campo que falta en todos los productos de golpe.
 *
 * Esto no es un adorno, sale de los números reales. Al pasar el catálogo
 * Confort salieron 154 productos y la marca quedó dudosa en 116: el catálogo
 * no la trae impresa en ningún lado, porque la marca ES la línea. Corregir
 * eso de uno en uno son 116 clics para escribir siempre lo mismo.
 *
 * Solo toca los que están VACÍOS. Lo que la IA sí leyó no se pisa: si en
 * alguna página sí venía la marca, esa se respeta.
 */
class CompletarCampoEnLoteAction
{
    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /** @return int Cuántos productos se rellenaron. */
    public function ejecutar(ImportacionCatalogo $importacion, string $campo, string $valor): int
    {
        if (! in_array($campo, CamposDelProductoImportado::RELLENABLES_EN_LOTE, true)) {
            throw new InvalidArgumentException("El campo {$campo} no se puede rellenar en lote.");
        }

        $valor = trim($valor);

        if ($valor === '') {
            return 0;
        }

        $esBooleano = CamposDelProductoImportado::esBooleano($campo);
        $valorBooleano = in_array(mb_strtolower($valor), ['1', 'si', 'sí', 'true'], true);

        if ($esBooleano && ! in_array(mb_strtolower($valor), ['1', '0', 'si', 'sí', 'no', 'true', 'false'], true)) {
            throw new InvalidArgumentException("El campo {$campo} solo acepta sí o no.");
        }

        $rellenados = 0;

        foreach ($importacion->productosStaging()->where('estado', '!=', 'descartado')->get() as $fila) {
            $datos = $fila->datos_extraidos ?? [];

            if (! $this->estaVacio($datos[$campo] ?? null, $campo)) {
                continue;
            }

            $datos[$campo] = $esBooleano ? $valorBooleano : $valor;

            $fila->update([
                'datos_extraidos' => $datos,
                // Ya lo contestó una persona: deja de ser dudoso.
                'campos_dudosos'  => array_values(array_diff($fila->campos_dudosos ?? [], [$campo])),
                'estado'          => 'corregido',
            ]);

            $rellenados++;
        }

        if ($rellenados > 0) {
            $this->auditoria->ejecutar(
                accion: 'importacion_catalogo.relleno_en_lote',
                entidadTipo: 'importacion_catalogo',
                entidadId: $importacion->id,
                datosNuevos: ['campo' => $campo, 'valor' => $valor, 'productos' => $rellenados],
            );
        }

        return $rellenados;
    }

    /**
     * Qué cuenta como vacío.
     *
     * En un booleano, false NO es vacío: es una respuesta. El catálogo que
     * dice "solo enteros" ya contestó, y rellenar en lote no debe pisarlo,
     * igual que no pisa una marca que la IA sí leyó. Solo null está vacío.
     *
     * (La comprobación vieja hacía trim((string) false) = "" y lo trataba
     * como hueco, así que habría pisado esas lecturas.)
     */
    private function estaVacio(mixed $valor, string $campo): bool
    {
        if (CamposDelProductoImportado::esBooleano($campo)) {
            return $valor === null;
        }

        return $valor === null || trim((string) $valor) === '';
    }
}
