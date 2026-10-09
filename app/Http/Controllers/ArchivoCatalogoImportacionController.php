<?php

namespace App\Http\Controllers;

use App\Models\ImportacionCatalogo;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TG-237 (A7) — Ver el catálogo que se subió.
 *
 * El archivo vive en un disco PRIVADO y no tiene dirección pública: se sirve
 * desde aquí para que pase por la sesión y por el rol. La ruta exige
 * admin_general, igual que la pantalla.
 */
class ArchivoCatalogoImportacionController extends Controller
{
    public function show(int $id): StreamedResponse
    {
        $importacion = ImportacionCatalogo::findOrFail($id);

        $disco = Storage::disk(SubirCatalogoParaImportarAction::disco());

        abort_unless($disco->exists($importacion->archivo_url), 404, 'Ese archivo ya no está.');

        // Inline: un PDF se abre en el navegador en vez de descargarse.
        return $disco->response(
            $importacion->archivo_url,
            'catalogo-'.$importacion->id.'.'.pathinfo($importacion->archivo_url, PATHINFO_EXTENSION),
            ['Content-Disposition' => 'inline'],
        );
    }
}
