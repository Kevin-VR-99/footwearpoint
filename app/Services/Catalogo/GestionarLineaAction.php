<?php

namespace App\Services\Catalogo;

use App\Models\Linea;

/**
 * Alta y edición de líneas del catálogo compartido (TG-213).
 *
 * La línea existe una sola vez en todo el sistema y la administra el admin
 * general. Ya no cuelga de una temporada: es al revés, cada línea tiene sus
 * temporadas (D1).
 *
 * Aquí ya no se valida ningún cupo: el límite de líneas es de cada
 * distribuidora, no del catálogo, y vive en CupoLineasDistribuidora (TG-210).
 */
class GestionarLineaAction
{
    public function crear(array $datos, array $marcaIds = []): Linea
    {
        $linea = Linea::create([
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'logotipo_url' => $datos['logotipo_url'] ?? null,
            'activa'       => true,
        ]);

        if (! empty($marcaIds)) {
            $this->sincronizarMarcas($linea, $marcaIds);
        }

        return $linea->load('marcas', 'campanas');
    }

    public function actualizar(Linea $linea, array $datos, ?array $marcaIds = null): Linea
    {
        $linea->fill([
            'nombre'       => $datos['nombre'] ?? $linea->nombre,
            'descripcion'  => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $linea->descripcion,
            'logotipo_url' => array_key_exists('logotipo_url', $datos) ? $datos['logotipo_url'] : $linea->logotipo_url,
            'activa'       => array_key_exists('activa', $datos) ? $datos['activa'] : $linea->activa,
        ]);
        $linea->save();

        if (is_array($marcaIds)) {
            $this->sincronizarMarcas($linea, $marcaIds);
        }

        return $linea->fresh(['marcas', 'campanas']);
    }

    /** Qué marcas maneja la línea. La tabla puente también es del catálogo. */
    private function sincronizarMarcas(Linea $linea, array $marcaIds): void
    {
        $linea->marcas()->sync(array_map('intval', $marcaIds));
    }
}
