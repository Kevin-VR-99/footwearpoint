<?php

namespace App\Services\Ia;

use App\Models\ImportacionCatalogo;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * TG-377 (A17) — Una página del catálogo, dibujada y guardada.
 *
 * Al importar, cada página se dibuja en un temporal, se recortan sus fotos y
 * el temporal se borra. Está bien así: guardar 120 páginas por catálogo sería
 * tirar espacio. Pero para corregir un recorte a mano hace falta volver a ver
 * la página, y para eso está esto.
 *
 * Se dibuja UNA vez por página y se guarda en el disco privado del catálogo.
 * La piden dos: el endpoint que la enseña y el guardado que recorta con el
 * recuadro nuevo. Vive aquí para que las dos dibujen igual.
 *
 * No gasta API: dibujar un PDF es trabajo del servidor.
 */
class PaginaDelCatalogo
{
    public const CARPETA = 'catalogos/paginas';

    public function __construct(private RasterizarPaginaPdf $rasterizador) {}

    /** Dónde vive (o viviría) la página dentro del bucket. */
    public function ruta(ImportacionCatalogo $importacion, int $pagina): string
    {
        return self::CARPETA.'/'.$importacion->id.'/'.$pagina.'.png';
    }

    public function existe(ImportacionCatalogo $importacion, int $pagina): bool
    {
        return $this->disco()->exists($this->ruta($importacion, $pagina));
    }

    /**
     * Se asegura de que la página esté guardada. Devuelve si se pudo.
     *
     * Si ya estaba, no vuelve a dibujarla.
     */
    public function asegurar(ImportacionCatalogo $importacion, int $pagina): bool
    {
        if ($this->existe($importacion, $pagina)) {
            return true;
        }

        if (! $this->rasterizador->disponible()) {
            return false;
        }

        $pdf = $this->pdfEnDisco($importacion);

        if ($pdf === null) {
            return false;
        }

        try {
            $png = $this->rasterizador->aPng($pdf, $pagina);

            if ($png === null) {
                return false;
            }

            try {
                $ruta = $this->ruta($importacion, $pagina);

                return (bool) $this->disco()->putFileAs(dirname($ruta), new File($png), basename($ruta));
            } finally {
                @unlink($png);
            }
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * La página en un archivo local, para recortarla con GD.
     *
     * Quien llama se encarga de borrarlo. Devuelve null si no se pudo.
     */
    public function enDiscoLocal(ImportacionCatalogo $importacion, int $pagina): ?string
    {
        if (! $this->asegurar($importacion, $pagina)) {
            return null;
        }

        $destino = tempnam(sys_get_temp_dir(), 'pag');

        if ($destino === false) {
            return null;
        }

        @unlink($destino);
        $destino .= '.png';

        $contenido = $this->disco()->get($this->ruta($importacion, $pagina));

        if ($contenido === null || file_put_contents($destino, $contenido) === false) {
            @unlink($destino);

            return null;
        }

        return $destino;
    }

    private function pdfEnDisco(ImportacionCatalogo $importacion): ?string
    {
        $disco = $this->disco();

        if (! $disco->exists($importacion->archivo_url)) {
            return null;
        }

        $ruta = tempnam(sys_get_temp_dir(), 'catalogo');

        if ($ruta === false || file_put_contents($ruta, (string) $disco->get($importacion->archivo_url)) === false) {
            return null;
        }

        return $ruta;
    }

    private function disco()
    {
        return Storage::disk(SubirCatalogoParaImportarAction::disco());
    }
}
