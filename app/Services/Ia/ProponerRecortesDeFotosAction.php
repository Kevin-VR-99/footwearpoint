<?php

namespace App\Services\Ia;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * TG-282 (E5-05) — Proponer la foto de cada producto recién leído.
 *
 * Va DESPUÉS de leer el catálogo, no dentro: así un problema dibujando
 * páginas no estropea una lectura que ya se pagó. Lo que la IA leyó ya está
 * guardado cuando esto corre.
 *
 * Nada se publica solo. Cada recorte queda como propuesta y una persona lo
 * confirma, sube otro, o lo deja vacío en la pantalla de revisión. Aprobar un
 * producto no exige foto.
 */
class ProponerRecortesDeFotosAction
{
    public const CARPETA = 'catalogos/recortes-propuestos';

    /**
     * De la URL guardada a la ruta dentro del bucket, para volver a leer el
     * recorte cuando se aprueba.
     *
     * Igual que en las fotos del catálogo: no se arma con el bucket ni el
     * endpoint, porque AWS_URL puede estar puesta o no y
     * AWS_USE_PATH_STYLE_ENDPOINT cambia la forma de la URL. Se busca la
     * carpeta, que la decide esta misma clase.
     */
    public static function rutaEnElBucket(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $posicion = strpos($url, self::CARPETA.'/');

        if ($posicion === false) {
            return null;
        }

        return explode('?', substr($url, $posicion), 2)[0];
    }

    public function __construct(
        private RasterizarPaginaPdf $rasterizador,
        private RecortarFotoDelCatalogo $recortador,
    ) {}

    /**
     * @param  string  $contenido  el PDF, tal como se leyó del bucket
     */
    public function ejecutar(ImportacionCatalogo $importacion, string $contenido): void
    {
        $importacion->touch();

        if (! $this->rasterizador->disponible()) {
            // En local pasa siempre: XAMPP no trae poppler. No es un error,
            // y decirlo ayuda a entender por qué no hubo fotos.
            Log::info('Sin pdftoppm: el catálogo se leyó pero no se proponen fotos', [
                'importacion' => $importacion->id,
            ]);

            return;
        }

        $pdf = $this->aDisco($contenido);

        if ($pdf === null) {
            return;
        }

        try {
            // Por página, para dibujarla UNA vez aunque tenga tres colores.
            foreach ($this->porPagina($importacion) as $pagina => $productos) {
                $importacion->touch();
                $this->proponerDeUnaPagina($pdf, (int) $pagina, $productos);
            }
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * @param  Collection<int, ProductoImportadoStaging>  $productos
     */
    private function proponerDeUnaPagina(string $pdf, int $pagina, $productos): void
    {
        $imagen = $this->rasterizador->aPng($pdf, $pagina);

        if ($imagen === null) {
            return;
        }

        try {
            foreach ($productos as $producto) {
                $this->proponerDeUnProducto($imagen, $producto);
            }
        } finally {
            @unlink($imagen);
        }
    }

    private function proponerDeUnProducto(string $imagen, ProductoImportadoStaging $producto): void
    {
        $recorte = $this->recortador->recortar($imagen, $producto->datos_extraidos ?? []);

        if ($recorte === null) {
            $this->marcarDudoso($producto);

            return;
        }

        try {
            $ruta = Storage::disk('s3')->putFile(self::CARPETA, new File($recorte));

            if ($ruta === false) {
                // El disco s3 esta con 'throw' => false: una subida fallida
                // no avisa sola.
                Log::warning('No se pudo guardar el recorte propuesto', ['producto' => $producto->id]);
                $this->marcarDudoso($producto);

                return;
            }

            $producto->update([
                'foto_propuesta_url' => Storage::disk('s3')->url($ruta),
                'foto_estado' => 'propuesta',
            ]);
        } finally {
            @unlink($recorte);
        }
    }

    /**
     * Sin recorte el producto se aprueba igual, pero quien revisa tiene que
     * saber que ahí falta algo: por eso entra a los campos dudosos, que es
     * justo lo que esa pantalla destaca.
     */
    private function marcarDudoso(ProductoImportadoStaging $producto): void
    {
        $dudosos = $producto->campos_dudosos ?? [];

        if (! in_array('foto', $dudosos, true)) {
            $dudosos[] = 'foto';
        }

        $producto->update(['campos_dudosos' => array_values($dudosos)]);
    }

    /** @return Collection<int, Collection<int, ProductoImportadoStaging>> */
    private function porPagina(ImportacionCatalogo $importacion)
    {
        return ProductoImportadoStaging::where('importacion_id', $importacion->id)
            ->whereNull('foto_propuesta_url')
            ->get()
            ->groupBy(fn (ProductoImportadoStaging $p) => (int) ($p->datos_extraidos['pagina'] ?? 0))
            ->filter(fn ($productos, $pagina) => $pagina >= 1);
    }

    /** pdftoppm necesita una ruta; el PDF solo vive en memoria. */
    private function aDisco(string $contenido): ?string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'catalogo');

        if ($ruta === false || file_put_contents($ruta, $contenido) === false) {
            Log::warning('No se pudo dejar el catálogo en disco para dibujarlo');

            return null;
        }

        return $ruta;
    }
}
