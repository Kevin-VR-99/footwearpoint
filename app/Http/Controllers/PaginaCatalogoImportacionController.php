<?php

namespace App\Http\Controllers;

use App\Models\ImportacionCatalogo;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Services\Ia\PaginaDelCatalogo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TG-377 (A17) — Enseña una página del catálogo para corregir el recorte.
 *
 * La página vive en un disco PRIVADO y no tiene dirección pública: se sirve
 * desde aquí para que pase por la sesión y por el rol, igual que el PDF del
 * catálogo. Dibujarla y guardarla es cosa de PaginaDelCatalogo.
 */
class PaginaCatalogoImportacionController extends Controller
{
    public function __construct(private PaginaDelCatalogo $paginas) {}

    public function show(int $id, int $pagina): StreamedResponse
    {
        $importacion = ImportacionCatalogo::findOrFail($id);

        // Una página fuera del catálogo no es un error del sistema: es una
        // dirección escrita a mano. 404 y ya.
        abort_if(
            $pagina < 1 || ($importacion->paginas && $pagina > $importacion->paginas),
            404,
            'Esa página no está en el catálogo.'
        );

        abort_unless($this->paginas->asegurar($importacion, $pagina), 404, 'No se pudo dibujar esa página.');

        return Storage::disk(SubirCatalogoParaImportarAction::disco())->response(
            $this->paginas->ruta($importacion, $pagina),
            'pagina-'.$pagina.'.png',
            [
                'Content-Disposition' => 'inline; filename="pagina-'.$pagina.'.png"',
                'X-Content-Type-Options' => 'nosniff',
                // Es la misma página siempre: que el navegador no la vuelva a
                // pedir cada vez que se abre el editor.
                'Cache-Control' => 'private, max-age=86400',
            ]
        );
    }
}
