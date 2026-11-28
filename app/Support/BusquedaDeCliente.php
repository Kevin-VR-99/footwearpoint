<?php

namespace App\Support;

use App\Models\RevendedorDistribuidora;

/**
 * Coincide un cliente (o un mayorista) por nombre o por teléfono.
 * El nombre ignora mayúsculas y acentos. El teléfono acepta coincidencias
 * parciales, aunque el término traiga espacios o guiones.
 */
class BusquedaDeCliente
{
    public static function coincide(?string $nombre, ?string $telefono, string $termino): bool
    {
        $termino = trim($termino);

        if ($termino === '') {
            return true;
        }

        if (str_contains(self::texto($nombre), self::texto($termino))) {
            return true;
        }

        $digitosBusqueda = self::digitos($termino);
        $digitosTelefono = self::digitos((string) $telefono);

        return $digitosBusqueda !== ''
            && $digitosTelefono !== ''
            && str_contains($digitosTelefono, $digitosBusqueda);
    }

    /**
     * Nombre, teléfono y un detalle opcional (por ejemplo el código interno)
     * para pintar una sugerencia, tanto de cliente directo como de afiliación.
     *
     * @return array{id:int, nombre:string, telefono:string, detalle:string}
     */
    public static function ficha(object $item): array
    {
        if ($item instanceof RevendedorDistribuidora) {
            return [
                'id' => (int) $item->id,
                'nombre' => (string) ($item->revendedor?->nombre ?? ('Cliente mayorista #'.$item->id)),
                'telefono' => (string) ($item->revendedor?->telefono ?? ''),
                'detalle' => (string) ($item->codigo_interno ?? ''),
            ];
        }

        return [
            'id' => (int) $item->id,
            'nombre' => (string) ($item->nombre ?? ''),
            'telefono' => (string) ($item->telefono ?? ''),
            'detalle' => (string) ($item->codigo_interno ?? ''),
        ];
    }

    /**
     * Añade a una consulta (de cliente o de revendedor) el filtro por nombre
     * o teléfono, incluyendo dígitos parciales.
     */
    public static function acotar($query, string $termino, array $columnas = ['nombre', 'telefono']): void
    {
        $termino = trim($termino);

        if ($termino === '') {
            return;
        }

        $escapado = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $termino);
        $digitos = self::digitos($termino);

        $query->where(function ($q) use ($columnas, $escapado, $digitos) {
            foreach ($columnas as $i => $columna) {
                $metodo = $i === 0 ? 'where' : 'orWhere';
                $q->{$metodo}($columna, 'like', '%'.$escapado.'%');
            }

            if ($digitos !== '' && in_array('telefono', $columnas, true)) {
                $q->orWhere('telefono', 'like', '%'.$digitos.'%');
            }
        });
    }

    public static function texto(?string $valor): string
    {
        $valor = mb_strtolower((string) $valor);

        return strtr($valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
        ]);
    }

    public static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }
}
