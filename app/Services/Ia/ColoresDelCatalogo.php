<?php

namespace App\Services\Ia;

use App\Models\Color;
use App\Models\ImportacionCatalogo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Los colores del catálogo se guardan TAL CUAL los leyó la IA.
 *
 * Antes (TG-240) se pedía a la persona decir a qué color de la paleta
 * correspondía cada uno ("MIEL" -> "Café"). Gabriel pidió quitarlo: si el
 * catálogo dice "Miel", el color del producto es "Miel". Si ese color no
 * existe todavía en `colores`, se crea solo.
 *
 * Para no duplicar ("MIEL", "Miel", "miel " o "Café"/"cafe" son el mismo), la
 * comparación ignora mayúsculas, acentos y espacios de más. El que se crea
 * se guarda con mayúscula inicial en cada palabra ("Hueso", "Azul Marino").
 *
 * La tabla `colores` es global (no es por distribuidora), igual que en el
 * alta manual de variantes; por eso aquí tampoco hay distribuidora.
 */
class ColoresDelCatalogo
{
    /** Para comparar: sin acentos, en minúsculas y con un solo espacio. */
    public static function clave(?string $nombre): string
    {
        return Str::lower(Str::ascii(self::limpio($nombre)));
    }

    /** Como se muestra y se guarda: "MIEL" -> "Miel", "azul  marino" -> "Azul Marino". */
    public static function paraMostrar(?string $nombre): string
    {
        return mb_convert_case(mb_strtolower(self::limpio($nombre)), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * El color con ese nombre; si no existe, lo crea. Null si no trae nombre.
     */
    public function resolver(?string $nombre): ?Color
    {
        $clave = self::clave($nombre);

        if ($clave === '') {
            return null;
        }

        if (($existente = $this->buscar($clave)) !== null) {
            return $existente;
        }

        try {
            return Color::create([
                'nombre' => Str::limit(self::paraMostrar($nombre), 80, ''),
                'activo' => true,
            ]);
        } catch (QueryException $e) {
            // Otro proceso lo creó al mismo tiempo (nombre único): se usa ese.
            return $this->buscar($clave) ?? throw $e;
        }
    }

    /**
     * Los colores distintos de una importación, solo para enseñarlos.
     *
     * @return array<string, array{productos: int, existe: bool}> nombre para mostrar => datos
     */
    public function distintos(ImportacionCatalogo $importacion): array
    {
        $colores = [];

        $filas = $importacion->productosStaging()
            ->where('estado', '!=', 'descartado')
            ->get();

        foreach ($filas as $fila) {
            $nombre = (string) (($fila->datos_extraidos ?? [])['color'] ?? '');

            if (self::clave($nombre) === '') {
                continue;
            }

            $mostrar = self::paraMostrar($nombre);

            $colores[$mostrar] ??= ['productos' => 0, 'existe' => $this->buscar(self::clave($nombre)) !== null];
            $colores[$mostrar]['productos']++;
        }

        ksort($colores);

        return $colores;
    }

    private function buscar(string $clave): ?Color
    {
        // La paleta es corta (decenas): compararla en PHP permite ignorar
        // acentos sin depender del cotejamiento de la base.
        return Color::query()->orderBy('id')->get()
            ->first(fn (Color $c) => self::clave($c->nombre) === $clave);
    }

    private static function limpio(?string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));
    }
}
