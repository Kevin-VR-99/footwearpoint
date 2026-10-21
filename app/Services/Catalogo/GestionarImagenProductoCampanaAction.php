<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Services\Imagenes\RedimensionarImagenWebp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GestionarImagenProductoCampanaAction
{
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
        return DB::transaction(function () use ($productoCampana, $imagen, $esPrincipal) {
            $esPrimera = $productoCampana->imagenes()->count() === 0;

            $ruta = $imagen->store('productos/campana/imagenes', 's3');
            $url = Storage::disk('s3')->url($ruta);
            $variantes = $this->guardarVariantes($imagen, $ruta);

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
        DB::transaction(function () use ($imagen) {
            $imagen->delete();
            $this->auditar->eliminado($imagen, 'catalogo.imagen.eliminada', 'producto_imagen');
        });
    }

    /**
     * Auditoría 21-oct-2026: además de la original (se conserva tal cual),
     * guarda dos WebP: ~400 px para listados y ~1200 px para el detalle.
     * Si GD no puede leer la foto, se sigue con la original sola.
     *
     * @return array<int, string> ancho => url pública
     */
    private function guardarVariantes(UploadedFile $imagen, string $rutaOriginal): array
    {
        try {
            $versiones = $this->redimensionar->generar($imagen->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $base = preg_replace('/\.[^.\/]+$/', '', $rutaOriginal);
        $urls = [];

        foreach ($versiones as $ancho => $version) {
            $ruta = $base.'-'.$ancho.'.webp';
            Storage::disk('s3')->put($ruta, $version['contenido'], ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);
            $urls[$ancho] = Storage::disk('s3')->url($ruta);
        }

        return $urls;
    }

    private function desmarcarPrincipales(ProductoCampana $productoCampana): void
    {
        $productoCampana->imagenes()->where('es_principal', true)->update(['es_principal' => false]);
    }
}
