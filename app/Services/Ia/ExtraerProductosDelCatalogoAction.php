<?php

namespace App\Services\Ia;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * TG-238 (A8 / E5-02) — Leer el catálogo con la IA, de diez en diez páginas.
 *
 * El recorrido completo:
 *   cargado -> procesando -> requiere_revision   (o -> error)
 *
 * Después de esto nadie publica nada: los productos quedan en
 * productos_importados_staging esperando que una persona los revise (A9). Eso
 * es a propósito y es lo que el equipo va a defender ante el comité: la IA
 * propone, una persona aprueba.
 *
 * Va bloque por bloque y guarda lo de cada uno antes de seguir. Si el bloque 7
 * falla, los seis anteriores ya están guardados y no se vuelven a pagar.
 */
class ExtraerProductosDelCatalogoAction
{
    public function __construct(
        private LectorDeCatalogos $lector,
        private ContarPaginasPdf $contador,
        private RegistrarAuditoriaAction $auditoria,
        private LimitesDeGastoIa $limites,
    ) {
    }

    public function ejecutar(ImportacionCatalogo $importacion): void
    {
        $archivoIa = null;

        try {
            // Topes de gasto: se revisan aquí también por si se mandó a la
            // cola por otro camino que no los revisó.
            if (($motivo = $this->limites->motivoPorGastoDelMes()) !== null) {
                throw new RuntimeException($motivo);
            }

            $contenido = $this->contenidoDelArchivo($importacion);
            $paginas = $this->contador->contar($contenido);

            if ($paginas < 1) {
                throw new RuntimeException('No se pudo leer cuántas páginas tiene el archivo. Puede estar dañado o protegido.');
            }

            if (($motivo = $this->limites->motivoPorPaginas($paginas)) !== null) {
                $importacion->update(['paginas' => $paginas]);

                throw new RuntimeException($motivo);
            }

            $importacion->update(['estado' => 'procesando', 'paginas' => $paginas, 'mensaje_error' => null]);

            $archivoIa = $this->archivoEnLaIa($importacion, $contenido);

            $uso = new UsoDeTokens;
            $porBloque = max(1, (int) config('ia.paginas_por_bloque'));

            for ($desde = 1; $desde <= $paginas; $desde += $porBloque) {
                $hasta = min($desde + $porBloque - 1, $paginas);

                $bloque = $this->lector->leerBloque($archivoIa, $desde, $hasta);

                $this->guardar($importacion, $bloque->productos);

                // Se suma y se guarda en cada vuelta: así la pantalla enseña
                // el avance de verdad y, si truena, queda registrado lo que
                // ya se gastó.
                $uso = $uso->mas($bloque->uso);
                $this->anotarGasto($importacion, $uso);
            }

            $importacion->update(['estado' => 'requiere_revision']);

            $this->auditoria->ejecutar(
                accion: 'importacion_catalogo.procesada',
                entidadTipo: 'importacion_catalogo',
                entidadId: $importacion->id,
                datosNuevos: [
                    'paginas'   => $paginas,
                    'productos' => $importacion->productosStaging()->count(),
                    'costo_usd' => $uso->costo(),
                ],
            );
        } catch (Throwable $e) {
            // El detalle técnico va al log; la persona ve algo que se entiende.
            Log::error('Falló la importación con IA', [
                'importacion' => $importacion->id,
                'error' => $e->getMessage(),
            ]);

            $importacion->update([
                'estado' => 'error',
                'mensaje_error' => $this->mensajeParaLaPersona($e),
            ]);
        } finally {
            // Pase lo que pase, el catálogo no se queda en el proveedor.
            if ($archivoIa !== null) {
                $this->olvidarArchivo($importacion, $archivoIa);
            }
        }
    }

    private function contenidoDelArchivo(ImportacionCatalogo $importacion): string
    {
        $disco = Storage::disk(SubirCatalogoParaImportarAction::disco());

        if (! $disco->exists($importacion->archivo_url)) {
            throw new RuntimeException('El archivo del catálogo ya no está guardado. Hay que subirlo otra vez.');
        }

        return (string) $disco->get($importacion->archivo_url);
    }

    /**
     * Sube el catálogo, o reutiliza el que ya se subió.
     *
     * El identificador se guarda en importaciones_catalogo.archivo_ia_id
     * (TG-277). Antes vivía en el caché, y eso se perdía al reiniciar el
     * worker o al desplegar: el reintento tenía que volver a mandar 29 MB y,
     * peor, ya no sabíamos qué archivo había quedado colgado en el proveedor
     * para borrarlo. En la tabla sobrevive a todo eso.
     */
    private function archivoEnLaIa(ImportacionCatalogo $importacion, string $contenido): string
    {
        $yaSubido = $importacion->archivo_ia_id;

        if (is_string($yaSubido) && $yaSubido !== '') {
            return $yaSubido;
        }

        $archivoIa = $this->lector->subir($contenido, 'catalogo-'.$importacion->id.'.pdf');

        $importacion->update(['archivo_ia_id' => $archivoIa]);

        return $archivoIa;
    }

    private function olvidarArchivo(ImportacionCatalogo $importacion, string $archivoIa): void
    {
        try {
            $this->lector->borrar($archivoIa);
        } catch (Throwable $e) {
            // No es grave: el archivo se subió con fecha de vencimiento y se
            // borra solo. Queda anotado por si pasara seguido.
            Log::warning('No se pudo borrar el catálogo del proveedor de IA', [
                'importacion' => $importacion->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Se limpia pase lo que pase: si el borrado falló, el archivo vence
        // solo, y dejar el identificador haría creer que todavía está ahí.
        $importacion->update(['archivo_ia_id' => null]);
    }

    /** @param array<int, array<string, mixed>> $productos */
    private function guardar(ImportacionCatalogo $importacion, array $productos): void
    {
        foreach ($productos as $producto) {
            if (! is_array($producto)) {
                continue;
            }

            $dudosos = $producto['dudosos'] ?? [];
            unset($producto['dudosos']);

            ProductoImportadoStaging::create([
                'importacion_id'  => $importacion->id,
                'datos_extraidos' => $producto,
                'campos_dudosos'  => is_array($dudosos) ? array_values($dudosos) : [],
                'estado'          => 'pendiente',
            ]);
        }
    }

    private function anotarGasto(ImportacionCatalogo $importacion, UsoDeTokens $uso): void
    {
        $importacion->update([
            'proveedor_ia'    => 'anthropic',
            'modelo_ia'       => (string) config('ia.modelo'),
            'tokens_entrada'  => $uso->totalDeEntrada(),
            'tokens_salida'   => $uso->salida,
            'costo_usd'       => $uso->costo(),
        ]);
    }

    private function mensajeParaLaPersona(Throwable $e): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }

        return 'No se pudo leer el catálogo con la IA. Intenta de nuevo; si sigue fallando, avisa al equipo.';
    }
}
