<?php

namespace App\Services\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Ia\ProponerRecortesDeFotosAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TG-237 (A7 / E5-01) — Subir el catalogo de la fabrica para que lo lea la IA.
 *
 * Es el primer paso de la importacion: aqui solo se guarda el archivo y se
 * deja anotado que hay un catalogo esperando. Quien lo lee es A8, en una
 * tarea en segundo plano, porque procesar un PDF con Claude tarda minutos y
 * el servidor corta las peticiones web a los 30 segundos.
 *
 * Solo lo usa el admin general: el catalogo maestro es uno solo para todo el
 * sistema y no pertenece a ninguna distribuidora (TG-208).
 *
 * El archivo va a un disco PRIVADO, nunca al bucket publico de las fotos de
 * producto: un catalogo de fabrica es material del proveedor.
 */
class SubirCatalogoParaImportarAction
{
    /** Tamano maximo en kilobytes. Los catalogos reales pesan unos 28 MB. */
    public const MAXIMO_KB = 40960;

    /**
     * Lo que se acepta. El enum de la tabla tambien contempla imagenes, pero
     * la lectura con IA solo sabe de PDF (cuenta paginas y lo sube como
     * application/pdf): una foto se aceptaba y luego fallaba al procesar.
     */
    public const EXTENSIONES = ['pdf'];

    public function __construct(private RegistrarAuditoriaAction $auditoria) {}

    /** El disco donde viven los catalogos; se decide en el .env. */
    public static function disco(): string
    {
        return (string) config('filesystems.catalogos_disk', 'local');
    }

    public function ejecutar(UploadedFile $archivo, int $lineaId, ?int $campanaId): ImportacionCatalogo
    {
        // Nombre propio: el de la fabrica puede traer acentos, espacios o
        // repetirse. El original se conserva para mostrarlo.
        $extension = Str::lower($archivo->getClientOriginalExtension());
        $ruta = 'catalogos-ia/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;

        Storage::disk(self::disco())->put($ruta, $archivo->get(), 'private');

        $importacion = ImportacionCatalogo::create([
            'linea_id' => $lineaId,
            'campana_id' => $campanaId,
            'archivo_url' => $ruta,
            'tipo_archivo' => $extension === 'pdf' ? 'pdf' : 'imagen',
            'estado' => 'cargado',
            'iniciada_por_usuario_id' => Auth::id(),
        ]);

        $this->auditoria->ejecutar(
            accion: 'importacion_catalogo.cargada',
            entidadTipo: 'importacion_catalogo',
            entidadId: $importacion->id,
            datosNuevos: [
                'archivo' => $archivo->getClientOriginalName(),
                'linea_id' => $lineaId,
            ],
        );

        return $importacion;
    }

    /**
     * Quita una importación que el admin ya no quiere.
     *
     * No toca el catálogo maestro: los productos ya publicados viven en
     * productos y producto_campana, y la foto publicada es una copia, no el
     * recorte propuesto. Aquí se van el PDF, las propuestas y esos recortes.
     *
     * Mientras el estado es "procesando" no se quita: la lectura sigue en la
     * cola y borrar la fila a la mitad dejaría el trabajo sin dueño.
     *
     * @return array{propuestas: int, publicados: int}
     */
    public function eliminar(ImportacionCatalogo $importacion): array
    {
        if ($importacion->estado === 'procesando') {
            throw new RuntimeException('Ese catálogo se está leyendo. Espera a que termine para quitarlo.');
        }

        $filas = $importacion->productosStaging()->get();
        $publicados = $filas->filter(
            fn ($fila) => $fila->producto_creado_id !== null || $fila->producto_campana_creado_id !== null
        )->count();

        $this->borrarRecortesPropuestos($filas);
        $this->borrarArchivo($importacion);

        DB::transaction(function () use ($importacion, $filas, $publicados) {
            $this->auditoria->ejecutar(
                accion: 'importacion_catalogo.eliminada',
                entidadTipo: 'importacion_catalogo',
                entidadId: $importacion->id,
                datosPrevios: [
                    'archivo' => $importacion->archivo_url,
                    'estado' => $importacion->estado,
                    'propuestas' => $filas->count(),
                    'publicados' => $publicados,
                ],
            );

            // Antes que la fila: el cascade también las borraría, pero así
            // queda explícito que las propuestas se van y los productos no.
            $importacion->productosStaging()->delete();
            $importacion->delete();
        });

        return [
            'propuestas' => $filas->count(),
            'publicados' => $publicados,
        ];
    }

    /**
     * Los recortes viven en el bucket público, aparte de las fotos ya
     * copiadas al producto. Si el disco no confirma el borrado, la fila se
     * queda: es el único dato que dice dónde está el archivo.
     *
     * @param  Collection<int, ProductoImportadoStaging>  $filas
     */
    private function borrarRecortesPropuestos($filas): void
    {
        $rutas = [];

        foreach ($filas as $fila) {
            if (! $fila->foto_propuesta_url) {
                continue;
            }

            $ruta = ProponerRecortesDeFotosAction::rutaEnElBucket($fila->foto_propuesta_url);

            if ($ruta === null) {
                Log::warning('No se pudo sacar la ruta de un recorte propuesto; el archivo queda huérfano', [
                    'staging' => $fila->id,
                ]);

                continue;
            }

            $rutas[$ruta] = $ruta;
        }

        $pendientes = [];

        foreach ($rutas as $ruta) {
            if (Storage::disk('s3')->exists($ruta)) {
                $pendientes[] = $ruta;
            }
        }

        if ($pendientes !== [] && ! Storage::disk('s3')->delete($pendientes)) {
            throw new RuntimeException('No se pudieron borrar las fotos propuestas, así que el catálogo sigue en la lista. Inténtalo otra vez.');
        }
    }

    private function borrarArchivo(ImportacionCatalogo $importacion): void
    {
        $disco = Storage::disk(self::disco());

        if (! $importacion->archivo_url || ! $disco->exists($importacion->archivo_url)) {
            return;
        }

        if (! $disco->delete($importacion->archivo_url)) {
            throw new RuntimeException('No se pudo borrar el archivo del catálogo, así que sigue en la lista. Inténtalo otra vez.');
        }
    }
}
