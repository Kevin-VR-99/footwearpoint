<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * TG-282 (E5-05) — Convertir una página del PDF en imagen.
 *
 * Hace falta porque GD recorta imágenes pero no sabe abrir un PDF, y Claude
 * lee el documento pero solo puede contestar texto: la foto del zapato no la
 * puede devolver. Alguien tiene que dibujar la página, y ese alguien es
 * pdftoppm, del paquete poppler-utils.
 *
 * Si el binario no está, esto devuelve null y no truena: la lectura del
 * catálogo sigue, los productos se guardan con sus datos y nacen sin foto.
 * Un catálogo sin fotos sirve; un catálogo que no se puede leer, no.
 */
class RasterizarPaginaPdf
{
    /**
     * 150 ppp: a tamaño carta son ~1275x1650, de sobra para un recorte que
     * después se guarda en 1200 px de ancho como mucho. Más resolución solo
     * gasta memoria en el worker.
     */
    public const PUNTOS_POR_PULGADA = 150;

    private const SEGUNDOS = 60;

    private ?bool $hayBinario = null;

    public function disponible(): bool
    {
        if ($this->hayBinario === null) {
            // TG-377 (A17): se busca el banner de versión, no un código de
            // salida. pdftoppm -v devuelve 0 en unas versiones y 99 en otras,
            // así que por código no se puede distinguir.
            //
            // Antes esto era exitCode() !== null, y daba TRUE en una máquina
            // SIN poppler: un binario que no existe también contesta, con
            // código 1. Por eso el aviso «sin pdftoppm» nunca salía en local
            // y no había forma de entender por qué no se proponían fotos.
            //
            // No basta con buscar «pdftoppm» a secas: en Windows el error por
            // comando desconocido también trae esa palabra. El banner real
            // dice «pdftoppm version 24.02.0», y eso solo lo escribe él.
            $prueba = Process::timeout(10)->run(['pdftoppm', '-v']);

            $this->hayBinario = (bool) preg_match(
                '/pdftoppm\s+version/i',
                $prueba->output().' '.$prueba->errorOutput()
            );
        }

        return $this->hayBinario;
    }

    /**
     * Dibuja UNA página y devuelve la ruta del PNG, o null si no se pudo.
     *
     * Quien llama se encarga de borrar el archivo.
     */
    public function aPng(string $rutaPdf, int $pagina): ?string
    {
        if (! $this->disponible()) {
            return null;
        }

        $prefijo = tempnam(sys_get_temp_dir(), 'pagina');

        if ($prefijo === false) {
            return null;
        }

        // pdftoppm le pega la extensión al prefijo, así que el temporal vacío
        // estorba.
        @unlink($prefijo);

        $resultado = Process::timeout(self::SEGUNDOS)->run([
            'pdftoppm',
            '-png',
            '-r', (string) self::PUNTOS_POR_PULGADA,
            '-f', (string) $pagina,
            '-l', (string) $pagina,
            // Sin esto pdftoppm numera el archivo segun el total de paginas y
            // el nombre deja de ser predecible.
            '-singlefile',
            $rutaPdf,
            $prefijo,
        ]);

        $png = $prefijo.'.png';

        if (! $resultado->successful() || ! is_file($png)) {
            Log::warning('No se pudo dibujar una página del catálogo', [
                'pagina' => $pagina,
                'error'  => $resultado->errorOutput(),
            ]);

            @unlink($png);

            return null;
        }

        return $png;
    }
}
