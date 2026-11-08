<?php

namespace App\Services\Vale;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TG-356 — Borra vales respetando vale_origen_id (ON DELETE RESTRICT).
 *
 * No se asume que el sobrante tenga un id mayor que su origen: un alta
 * manual o un id explícito puede dejar al hijo con un id menor. El orden se
 * calcula entero antes del primer DELETE. Si hay un ciclo, no se borra
 * ninguna fila de ese conjunto.
 */
class BorrarValesPorOrigen
{
    /**
     * @param  list<int>  $ids
     * @param  array<int, int|null>  $origenDe  id => vale_origen_id
     * @return list<list<int>> ondas de borrado; cada onda ya no tiene hijos dentro del conjunto
     */
    public static function ondas(array $ids, array $origenDe): array
    {
        $presentes = [];
        foreach ($ids as $id) {
            $presentes[(int) $id] = true;
        }
        if ($presentes === []) {
            return [];
        }

        $hijosDe = [];
        foreach (array_keys($presentes) as $id) {
            $origen = $origenDe[$id] ?? null;
            if ($origen === null || ! isset($presentes[(int) $origen])) {
                continue;
            }
            $hijosDe[(int) $origen][] = $id;
        }

        $pendientes = $presentes;
        $ondas = [];
        while ($pendientes !== []) {
            $hojas = [];
            foreach (array_keys($pendientes) as $id) {
                $tieneHijo = false;
                foreach ($hijosDe[$id] ?? [] as $hijo) {
                    if (isset($pendientes[$hijo])) {
                        $tieneHijo = true;
                        break;
                    }
                }
                if (! $tieneHijo) {
                    $hojas[] = $id;
                }
            }
            if ($hojas === []) {
                throw new RuntimeException('Hay un ciclo en vales.vale_origen_id. No se borró ningún vale de este conjunto para no dejar la cadena a medias.');
            }
            $ondas[] = $hojas;
            foreach ($hojas as $id) {
                unset($pendientes[$id]);
            }
        }

        return $ondas;
    }

    /** @param  Builder  $consulta  subconjunto a borrar (una distribuidora, o todos en la limpieza de pruebas) */
    public static function borrar(Builder $consulta): void
    {
        $ids = [];
        $origenDe = [];
        foreach ((clone $consulta)->get(['id', 'vale_origen_id']) as $fila) {
            $id = (int) $fila->id;
            $ids[] = $id;
            $origenDe[$id] = $fila->vale_origen_id === null ? null : (int) $fila->vale_origen_id;
        }

        foreach (self::ondas($ids, $origenDe) as $onda) {
            foreach (array_chunk($onda, 500) as $lote) {
                DB::table('vales')->whereIn('id', $lote)->delete();
            }
        }
    }
}
