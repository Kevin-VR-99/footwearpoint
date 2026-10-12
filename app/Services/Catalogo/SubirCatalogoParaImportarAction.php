<?php

namespace App\Services\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /** Lo que se acepta. El enum de la tabla tambien contempla imagenes. */
    public const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

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
            'linea_id'                => $lineaId,
            'campana_id'              => $campanaId,
            'archivo_url'             => $ruta,
            'tipo_archivo'            => $extension === 'pdf' ? 'pdf' : 'imagen',
            'estado'                  => 'cargado',
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
     * Borra una importacion y su archivo. Solo tiene sentido mientras nadie
     * la haya procesado: despues ya hay productos colgando de ella.
     */
    public function eliminar(ImportacionCatalogo $importacion): void
    {
        Storage::disk(self::disco())->delete($importacion->archivo_url);

        $this->auditoria->ejecutar(
            accion: 'importacion_catalogo.eliminada',
            entidadTipo: 'importacion_catalogo',
            entidadId: $importacion->id,
            datosPrevios: ['archivo' => $importacion->archivo_url],
        );

        $importacion->delete();
    }
}
