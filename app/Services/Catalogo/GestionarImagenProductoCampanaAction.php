<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Services\Imagenes\RedimensionarImagenWebp;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GestionarImagenProductoCampanaAction
{
    /**
     * Dónde viven las fotos dentro del bucket.
     *
     * Es constante porque sirve para dos cosas: guardar, y volver de la URL
     * pública a la ruta cuando hay que borrar.
     */
    private const CARPETA = 'productos/campana/imagenes';

    public function __construct(
        private AuditarCambios $auditar,
        private RedimensionarImagenWebp $redimensionar,
    ) {
    }

    /**
     * DECISIÓN PROVISIONAL MÍA: la tabla producto_imagenes no tiene
     * ninguna regla que impida 2 imágenes "es_principal=true" a la vez
     * para la misma publicación — igual que pasó con "configuraciones de
     * ciclo activas" en el Bloque 1, lo controlo yo aquí: al marcar una
     * como principal, las demás de esa misma publicación se desmarcan.
     * Además, la primera imagen que se sube SIEMPRE queda como principal,
     * aunque no se pida explícitamente (no tendría sentido una
     * publicación con imágenes pero sin ninguna marcada como principal).
     */
    public function agregar(ProductoCampana $productoCampana, UploadedFile $imagen, bool $esPrincipal = false): ProductoImagen
    {
        return $this->guardarImagen($productoCampana, $imagen, $esPrincipal);
    }

    /**
     * La misma alta, pero desde un archivo que ya está en el disco del
     * servidor y que no subió nadie por un formulario.
     *
     * Hace falta porque agregar() exige un UploadedFile, y hay altas que no
     * vienen de una petición: la que viene es el recorte que la lectura del
     * catálogo propone (E5-05). Antes la única salida era fabricar un
     * UploadedFile de mentiras, que es un parche.
     */
    public function agregarDesdeRuta(ProductoCampana $productoCampana, string $rutaLocal, bool $esPrincipal = false): ProductoImagen
    {
        return $this->guardarImagen($productoCampana, new File($rutaLocal), $esPrincipal);
    }

    private function guardarImagen(ProductoCampana $productoCampana, File|UploadedFile $archivo, bool $esPrincipal): ProductoImagen
    {
        return DB::transaction(function () use ($productoCampana, $archivo, $esPrincipal) {
            $esPrimera = $productoCampana->imagenes()->count() === 0;

            $ruta = Storage::disk('s3')->putFile(self::CARPETA, $archivo);

            // El disco s3 está con 'throw' => false, así que una subida
            // fallida devuelve false en vez de tronar. Sin esto se guardaba
            // una fila apuntando a un archivo que no existe.
            if ($ruta === false) {
                throw new RuntimeException('No se pudo guardar la foto. Inténtalo otra vez.');
            }

            $url = Storage::disk('s3')->url($ruta);
            $variantes = $this->guardarVariantes($archivo, $ruta);

            $siguienteOrden = ($productoCampana->imagenes()->max('orden') ?? 0) + 1;

            if ($esPrincipal || $esPrimera) {
                $this->desmarcarPrincipales($productoCampana);
            }

            $registro = ProductoImagen::create([
                'producto_campana_id' => $productoCampana->id,
                'url'                 => $url,
                'url_miniatura'       => $variantes[RedimensionarImagenWebp::MINIATURA] ?? null,
                'url_mediana'         => $variantes[RedimensionarImagenWebp::MEDIANA] ?? null,
                'orden'               => $siguienteOrden,
                'es_principal'        => $esPrincipal || $esPrimera,
            ]);
            $this->auditar->creado($registro, 'catalogo.imagen.agregada', 'producto_imagen');

            return $registro;
        });
    }

    public function marcarPrincipal(ProductoImagen $imagen): ProductoImagen
    {
        return DB::transaction(function () use ($imagen) {
            $this->desmarcarPrincipales($imagen->productoCampana);

            $imagen->es_principal = true;
            $this->auditar->guardar($imagen, 'catalogo.imagen.principal', 'producto_imagen');

            return $imagen->fresh();
        });
    }

    /**
     * Pendiente real, no resuelto a propósito: si se borra la imagen
     * principal, ninguna otra la reemplaza automáticamente — ningún
     * documento dice qué debería pasar en ese caso. Avísalo al equipo.
     */
    public function eliminar(ProductoImagen $imagen): void
    {
        // Los archivos primero y la fila después, a propósito: la fila es el
        // único dato que dice dónde están los archivos. Si se borrara antes
        // y el bucket fallara, quedarían huérfanos invisibles pagando
        // almacenamiento para siempre.
        $this->borrarDelBucket($imagen);

        DB::transaction(function () use ($imagen) {
            $imagen->delete();
            $this->auditar->eliminado($imagen, 'catalogo.imagen.eliminada', 'producto_imagen');
        });
    }

    /**
     * Borra la original y las dos WebP, las que existan.
     *
     * Antes esto no pasaba: eliminar() solo borraba la fila y los archivos se
     * quedaban en el bucket. Y desde las variantes (21-oct-2026) no era uno,
     * eran tres por cada foto.
     *
     * El disco s3 está con 'throw' => false (config/filesystems.php), así que
     * delete() devuelve false en vez de tronar: hay que mirar el resultado.
     */
    private function borrarDelBucket(ProductoImagen $imagen): void
    {
        $rutas = array_values(array_filter([
            $this->rutaEnElBucket($imagen->url),
            $this->rutaEnElBucket($imagen->url_miniatura),
            $this->rutaEnElBucket($imagen->url_mediana),
        ]));

        if ($rutas === []) {
            return;
        }

        if (! Storage::disk('s3')->delete($rutas)) {
            throw new RuntimeException(
                'No se pudo borrar la foto del almacenamiento, así que no se quitó del catálogo. Inténtalo otra vez.'
            );
        }
    }

    /**
     * De la URL pública que guarda la fila a la ruta dentro del bucket.
     *
     * No se arma con el bucket ni el endpoint: AWS_URL puede estar puesta o
     * no, y AWS_USE_PATH_STYLE_ENDPOINT cambia la forma de la URL, así que
     * hacerlo a mano se rompería en silencio el día que eso cambie. Se busca
     * la carpeta, que la decide esta misma clase.
     *
     * Si no se puede sacar la ruta (una foto de otro bucket, de antes de un
     * cambio de configuración), se anota y se devuelve null: se acepta dejar
     * un huérfano anotado en el log antes que dejar al admin sin poder quitar
     * esa foto nunca.
     */
    private function rutaEnElBucket(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $posicion = strpos($url, self::CARPETA.'/');

        if ($posicion === false) {
            Log::warning('No se pudo sacar la ruta del bucket de una foto de producto; el archivo queda huérfano', [
                'url' => $url,
            ]);

            return null;
        }

        // Por si alguna vez la URL trae firma o parámetros.
        return explode('?', substr($url, $posicion), 2)[0];
    }

    /**
     * Auditoría 21-oct-2026: además de la original (se conserva tal cual),
     * guarda dos WebP: ~400 px para listados y ~1200 px para el detalle.
     * Si GD no puede leer la foto, se sigue con la original sola.
     *
     * @return array<int, string> ancho => url pública
     */
    private function guardarVariantes(File|UploadedFile $archivo, string $rutaOriginal): array
    {
        try {
            $versiones = $this->redimensionar->generar($archivo->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $base = preg_replace('/\.[^.\/]+$/', '', $rutaOriginal);
        $urls = [];

        foreach ($versiones as $ancho => $version) {
            $ruta = $base.'-'.$ancho.'.webp';

            $guardada = Storage::disk('s3')->put($ruta, $version['contenido'], ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);

            // Con 'throw' => false, una subida fallida no avisaba y la fila
            // se quedaba con la URL de un archivo inexistente. Si falla, la
            // foto se queda con su original sola, que es lo que ya hace
            // cuando GD no puede leerla.
            if (! $guardada) {
                Log::warning('No se pudo guardar una variante de la foto del producto', ['ruta' => $ruta]);

                continue;
            }

            $urls[$ancho] = Storage::disk('s3')->url($ruta);
        }

        return $urls;
    }

    private function desmarcarPrincipales(ProductoCampana $productoCampana): void
    {
        $productoCampana->imagenes()->where('es_principal', true)->update(['es_principal' => false]);
    }
}
