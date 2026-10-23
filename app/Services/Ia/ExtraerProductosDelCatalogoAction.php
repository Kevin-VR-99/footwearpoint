<?php

namespace App\Services\Ia;

use Anthropic\Core\Exceptions\BadRequestException;
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
        private ProponerRecortesDeFotosAction $recortes,
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

            // Lo gastado en intentos anteriores se conserva: el tope del mes
            // suma costo_usd, y si un reintento lo pisara, ese dinero
            // desaparecería de la cuenta.
            $gastoPrevio = [
                'entrada' => (int) $importacion->tokens_entrada,
                'salida'  => (int) $importacion->tokens_salida,
                'costo'   => (float) $importacion->costo_usd,
            ];

            $uso = new UsoDeTokens;
            $porBloque = max(1, (int) config('ia.paginas_por_bloque'));

            for ($desde = 1; $desde <= $paginas; $desde += $porBloque) {
                $hasta = min($desde + $porBloque - 1, $paginas);

                $uso = $this->leerRango($importacion, $archivoIa, $desde, $hasta, $uso, $gastoPrevio);
            }

            // Las fotos van aquí y no dentro del bucle a propósito: lo que la
            // IA leyó ya está guardado y pagado, así que un problema
            // dibujando páginas no puede estropearlo. Si no hay pdftoppm, o
            // un recorte sale mal, los productos quedan sin foto y se suben a
            // mano desde el catálogo maestro.
            try {
                $this->recortes->ejecutar($importacion, $contenido);
            } catch (Throwable $e) {
                Log::warning('No se pudieron proponer las fotos del catálogo', [
                    'importacion' => $importacion->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $importacion->update(['estado' => 'requiere_revision']);

            $this->auditoria->ejecutar(
                accion: 'importacion_catalogo.procesada',
                entidadTipo: 'importacion_catalogo',
                entidadId: $importacion->id,
                datosNuevos: [
                    'paginas'   => $paginas,
                    'productos' => $importacion->productosStaging()->count(),
                    'costo_usd' => (float) $importacion->costo_usd,
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

    /**
     * Lee un rango de páginas. Si la respuesta no cupo (max_tokens), parte el
     * rango a la mitad y lee cada parte; una sola página que no quepa sí es
     * error, con un mensaje que dice qué subir.
     *
     * @param  array{entrada: int, salida: int, costo: float}  $gastoPrevio
     */
    private function leerRango(ImportacionCatalogo $importacion, string $archivoIa, int $desde, int $hasta, UsoDeTokens $uso, array $gastoPrevio): UsoDeTokens
    {
        try {
            $bloque = $this->lector->leerBloque($archivoIa, $desde, $hasta);
        } catch (RespuestaCortadaPorTokens $e) {
            $uso = $uso->mas($e->uso);
            $this->anotarGasto($importacion, $uso, $gastoPrevio);

            if ($desde >= $hasta) {
                throw new RuntimeException(
                    "La página {$desde} tiene más productos de los que la IA puede devolver de una vez. Sube IA_MAX_TOKENS_BLOQUE o avisa al equipo."
                );
            }

            $mitad = intdiv($desde + $hasta, 2);
            $uso = $this->leerRango($importacion, $archivoIa, $desde, $mitad, $uso, $gastoPrevio);

            return $this->leerRango($importacion, $archivoIa, $mitad + 1, $hasta, $uso, $gastoPrevio);
        }

        $this->guardar($importacion, $bloque->productos);

        // Se suma y se guarda en cada vuelta: así la pantalla enseña el avance
        // de verdad y, si truena, queda registrado lo que ya se gastó.
        $uso = $uso->mas($bloque->uso);
        $this->anotarGasto($importacion, $uso, $gastoPrevio);

        return $uso;
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

    /** @param array{entrada: int, salida: int, costo: float} $gastoPrevio */
    private function anotarGasto(ImportacionCatalogo $importacion, UsoDeTokens $uso, array $gastoPrevio): void
    {
        $importacion->update([
            'proveedor_ia'    => 'anthropic',
            'modelo_ia'       => (string) config('ia.modelo'),
            'tokens_entrada'  => $gastoPrevio['entrada'] + $uso->totalDeEntrada(),
            'tokens_salida'   => $gastoPrevio['salida'] + $uso->salida,
            'costo_usd'       => round($gastoPrevio['costo'] + $uso->costo(), 4),
        ]);
    }

    private function mensajeParaLaPersona(Throwable $e): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }

        // Sin saldo, "intenta de nuevo" era el peor consejo posible: cada
        // intento vuelve a subir el PDF y a reescribir el cache, y se cobra.
        if ($this->pareceSaldoAgotado($e)) {
            return 'Puede que se haya agotado el saldo de la cuenta de la IA. Avisa al equipo antes de '
                .'reintentar, porque cada intento cuesta una lectura completa. El detalle quedó en el log.';
        }

        return 'No se pudo leer el catálogo con la IA. Intenta de nuevo; si sigue fallando, avisa al equipo.';
    }

    /**
     * Si el 400 del proveedor viene por saldo.
     *
     * El SDK no reintenta el 400 por su cuenta (solo 408, 409, 429 y 5xx),
     * asi que este error llega tal cual y una sola vez.
     *
     * Se reconoce por el texto que manda Anthropic, en ingles, dentro del
     * cuerpo del error. Es lo unico que lo distingue de cualquier otro 400 y
     * puede cambiar sin avisar: por eso el mensaje para la persona dice
     * "puede que" y el texto crudo se sigue guardando en el log.
     */
    private function pareceSaldoAgotado(Throwable $e): bool
    {
        if (! $e instanceof BadRequestException) {
            return false;
        }

        $texto = mb_strtolower($e->getMessage());

        return str_contains($texto, 'credit balance') || str_contains($texto, 'insufficient credit');
    }
}
