<?php

namespace App\Services\Ia;

use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;

/**
 * TG-240 (A10) — Emparejar los colores del catálogo con la paleta.
 *
 * Un catálogo dice "MIEL", "HUESO", "DORADO"; la tabla `colores` es una
 * paleta base, corta y con su código de color. Para eso existe
 * `variantes.nombre_color_comercial`: el color base es "Café" y el comercial
 * "Miel", como ya lo hace el catálogo demo.
 *
 * Lo importante es que esto se pregunta UNA VEZ POR COLOR DISTINTO, no por
 * producto: en los 154 productos del Confort hay cerca de veinte colores.
 * Preguntarlo por producto serían 154 decisiones para tomar veinte.
 *
 * Y antes de preguntar, se busca solo:
 *
 *  1. Si ese nombre comercial ya se usó antes (variantes), se reutiliza su
 *     color base. Así el catálogo se va enseñando a sí mismo y la segunda
 *     importación casi no pregunta nada.
 *  2. Si la IA propuso un color base de la paleta, se usa esa propuesta.
 *  3. Si el nombre coincide tal cual con uno de la paleta, ese.
 *
 * La persona solo confirma o corrige lo que quedó sin resolver.
 */
class ColoresDelCatalogo
{
    /**
     * Los colores distintos de una importación, con su propuesta.
     *
     * @return array<string, array{productos: int, asignado: ?int, sugerido: ?int, origen: string}>
     */
    public function distintos(ImportacionCatalogo $importacion): array
    {
        $colores = [];

        $filas = $importacion->productosStaging()
            ->where('estado', '!=', 'descartado')
            ->get();

        foreach ($filas as $fila) {
            $datos = $fila->datos_extraidos ?? [];
            $comercial = trim((string) ($datos['color'] ?? ''));

            if ($comercial === '') {
                continue;
            }

            if (! isset($colores[$comercial])) {
                [$sugerido, $origen] = $this->sugerir($comercial, $datos['color_base'] ?? null);

                $colores[$comercial] = [
                    'productos' => 0,
                    'asignado'  => null,
                    'sugerido'  => $sugerido,
                    'origen'    => $origen,
                ];
            }

            $colores[$comercial]['productos']++;

            if (($datos['color_base_id'] ?? null) !== null) {
                $colores[$comercial]['asignado'] = (int) $datos['color_base_id'];
            }
        }

        ksort($colores);

        return $colores;
    }

    /**
     * @return array{0: ?int, 1: string} El color base propuesto y de dónde salió.
     */
    public function sugerir(string $comercial, ?string $baseDeLaIa = null): array
    {
        // 1. Lo que ya se decidió antes para ese mismo nombre comercial.
        $anterior = Variante::where('nombre_color_comercial', $comercial) // collation *_ci
            ->whereNotNull('color_id')
            ->value('color_id');

        if ($anterior !== null) {
            return [(int) $anterior, 'ya se usó antes'];
        }

        // 2. Lo que propuso la IA, si es un color de la paleta.
        if ($baseDeLaIa !== null && ($id = $this->porNombre($baseDeLaIa)) !== null) {
            return [$id, 'lo propuso la IA'];
        }

        // 3. El nombre comercial tal cual es un color de la paleta.
        if (($id = $this->porNombre($comercial)) !== null) {
            return [$id, 'coincide con la paleta'];
        }

        return [null, 'hay que decidirlo'];
    }

    /**
     * Guarda la decisión en cada producto que use ese color.
     *
     * @param  array<string, int|string|null>  $mapa  nombre comercial => id del color base
     * @return int Cuántos productos quedaron con su color resuelto.
     */
    public function asignar(ImportacionCatalogo $importacion, array $mapa): int
    {
        $resueltos = 0;
        $filas = $importacion->productosStaging()->where('estado', '!=', 'descartado')->get();
        $previo = $this->mapaActual($filas);
        $cambiosPorNombre = [];

        foreach ($filas as $fila) {
            $datos = $fila->datos_extraidos ?? [];
            $comercial = trim((string) ($datos['color'] ?? ''));

            if ($comercial === '' || ! array_key_exists($comercial, $mapa)) {
                continue;
            }

            $colorId = $mapa[$comercial];

            if ($colorId === null || $colorId === '' || Color::find((int) $colorId) === null) {
                continue;
            }

            $anterior = $datos['color_base_id'] ?? null;
            $datos['color_base_id'] = (int) $colorId;
            $fila->update(['datos_extraidos' => $datos]);

            if ($anterior === null || (int) $anterior !== (int) $colorId) {
                $cambiosPorNombre[$comercial] = ($cambiosPorNombre[$comercial] ?? 0) + 1;
            }

            $resueltos++;
        }

        // Se audita siempre que se guarda, aunque no cambie ninguna fila
        // (filas_cambiadas = 0): queda constancia de quién confirmó la paleta.
        ksort($cambiosPorNombre);
        app(RegistrarAuditoriaAction::class)->ejecutar(
            'importacion_catalogo.colores_asignados',
            'importacion_catalogo',
            $importacion->id,
            ['colores' => $previo],
            [
                'colores'                    => $this->mapaActual($filas),
                'filas_cambiadas'            => array_sum($cambiosPorNombre),
                'filas_cambiadas_por_nombre' => $cambiosPorNombre,
                'filas_resueltas'            => $resueltos,
            ],
        );

        return $resueltos;
    }

    /**
     * nombre comercial => {color_base_id, color} con lo que hoy tienen las filas.
     *
     * @return array<string, array{color: ?string, color_base_id: ?int}>
     */
    private function mapaActual($filas): array
    {
        $mapa = [];

        foreach ($filas as $fila) {
            $datos = $fila->datos_extraidos ?? [];
            $comercial = trim((string) ($datos['color'] ?? ''));

            if ($comercial === '') {
                continue;
            }

            $id = $datos['color_base_id'] ?? null;

            if (! isset($mapa[$comercial]) || $id !== null) {
                $mapa[$comercial] = $id === null ? null : (int) $id;
            }
        }

        ksort($mapa);
        $nombres = Color::whereIn('id', array_filter($mapa))->pluck('nombre', 'id');

        return array_map(fn ($id) => [
            // Mismo orden en que MySQL guarda las claves de un JSON.
            'color'         => $id === null ? null : ($nombres[$id] ?? null),
            'color_base_id' => $id,
        ], $mapa);
    }

    /** Cuántos productos siguen sin color resuelto. */
    public function sinResolver(ImportacionCatalogo $importacion): int
    {
        return $importacion->productosStaging()
            ->where('estado', '!=', 'descartado')
            ->get()
            ->filter(fn ($f) => ($f->datos_extraidos['color_base_id'] ?? null) === null)
            ->count();
    }

    private function porNombre(?string $nombre): ?int
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            return null;
        }

        return Color::where('nombre', $nombre)->value('id'); // collation *_ci: sin LOWER()
    }
}
